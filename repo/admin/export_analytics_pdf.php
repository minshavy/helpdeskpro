<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
require_once __DIR__ . '/../includes/escalation.php';
require_once __DIR__ . '/../lib/FPDF/fpdf.php';

$sla_hours = get_sla_hours();
$sla_case = "CASE priority WHEN 'High' THEN {$sla_hours['High']} WHEN 'Medium' THEN {$sla_hours['Medium']} ELSE {$sla_hours['Low']} END";

$avg_resolution = $pdo->query("SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) AS h FROM tickets WHERE status = 'Closed'")->fetch()['h'];
$sla_breaches = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status != 'Closed' AND escalated = 0 AND TIMESTAMPDIFF(HOUR, created_at, NOW()) > ($sla_case)")->fetchColumn();
$escalated_active = $pdo->query("SELECT COUNT(*) FROM tickets WHERE escalated = 1 AND status != 'Closed'")->fetchColumn();
$total_active = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status != 'Closed'")->fetchColumn();
$total_all = $pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();

$overall_compliance = $pdo->query("SELECT SUM(escalated = 0) AS compliant, COUNT(*) AS total FROM tickets WHERE status = 'Closed'")->fetch();
$overall_compliance_pct = $overall_compliance['total'] > 0 ? round(($overall_compliance['compliant'] / $overall_compliance['total']) * 100, 1) : null;

$status_counts = $pdo->query("SELECT status, COUNT(*) AS c FROM tickets GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$priority_counts = $pdo->query("SELECT priority, COUNT(*) AS c FROM tickets GROUP BY priority")->fetchAll(PDO::FETCH_KEY_PAIR);
$category_counts = $pdo->query("SELECT category, COUNT(*) AS c FROM tickets GROUP BY category")->fetchAll(PDO::FETCH_KEY_PAIR);

$technician_perf = $pdo->query("
    SELECT u.name,
        COUNT(t.ticket_id) AS total_assigned,
        SUM(t.status = 'Closed') AS closed_count,
        SUM(t.status != 'Closed') AS active_count,
        AVG(CASE WHEN t.status = 'Closed' THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at) END) AS avg_hours
    FROM users u
    LEFT JOIN tickets t ON t.assigned_to = u.user_id
    WHERE u.role = 'technician'
    GROUP BY u.user_id, u.name
    ORDER BY closed_count DESC
")->fetchAll();

$admin = current_user();
audit_log($pdo, $admin['user_id'], 'report_exported', 'Exported analytics report as PDF');

function pdf_safe($text) {
    $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', (string) $text);
    return $converted !== false ? $converted : (string) $text;
}

class AnalyticsPDF extends FPDF {
    public $generatedBy = '';

    function Header() {
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(79, 70, 229);
        $this->Cell(0, 9, 'HelpDeskPro - Analytics Report', 0, 1);
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, pdf_safe('Generated ' . date('Y-m-d H:i') . ' by ' . $this->generatedBy), 0, 1);
        $this->Ln(2);
        $this->SetDrawColor(220, 220, 220);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(5);
        $this->SetTextColor(0, 0, 0);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb} - Confidential', 0, 0, 'C');
    }

    function SectionTitle($title) {
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(30, 30, 30);
        $this->Cell(0, 8, pdf_safe($title), 0, 1);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(1);
    }

    /** A small KPI "card" drawn as a bordered box with a big number and a label. */
    function KpiCard($x, $y, $w, $h, $label, $value, $accentRgb = [79, 70, 229]) {
        $this->SetXY($x, $y);
        $this->SetDrawColor(229, 231, 235);
        $this->SetFillColor(255, 255, 255);
        $this->Rect($x, $y, $w, $h, 'DF');
        $this->SetXY($x + 3, $y + 3);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(107, 114, 128);
        $this->Cell($w - 6, 4, pdf_safe($label));
        $this->SetXY($x + 3, $y + 8);
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor($accentRgb[0], $accentRgb[1], $accentRgb[2]);
        $this->Cell($w - 6, 10, pdf_safe($value));
        $this->SetTextColor(0, 0, 0);
    }

    /** Simple horizontal bar chart drawn with Rect() - no external charting library needed. */
    function BarChart($x, $y, $w, $h, $data, $colors) {
        if (empty($data)) {
            $this->SetFont('Arial', 'I', 9);
            $this->SetTextColor(150, 150, 150);
            $this->SetXY($x, $y);
            $this->Cell($w, 8, 'No data yet.');
            $this->SetTextColor(0, 0, 0);
            return $y + 8;
        }
        $maxVal = max(array_values($data)) ?: 1;
        $barHeight = 6;
        $gap = 4;
        $labelWidth = 30;
        $barMaxWidth = $w - $labelWidth - 15;
        $i = 0;
        foreach ($data as $label => $value) {
            $by = $y + $i * ($barHeight + $gap);
            $this->SetFont('Arial', '', 8);
            $this->SetXY($x, $by);
            $this->Cell($labelWidth, $barHeight, pdf_safe($label));
            $barW = $maxVal > 0 ? ($value / $maxVal) * $barMaxWidth : 0;
            $color = $colors[$i % count($colors)];
            $this->SetFillColor($color[0], $color[1], $color[2]);
            if ($barW > 0) {
                $this->Rect($x + $labelWidth, $by + 1, $barW, $barHeight - 2, 'F');
            }
            $this->SetXY($x + $labelWidth + $barW + 2, $by);
            $this->Cell(15, $barHeight, (string) $value);
            $i++;
        }
        return $y + $i * ($barHeight + $gap);
    }
}

$pdf = new AnalyticsPDF();
$pdf->generatedBy = $admin['name'];
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 15);

// ---------------------------------------------------------
// KPI summary cards
// ---------------------------------------------------------
$pdf->SectionTitle('Summary');
$cardW = 45;
$cardH = 20;
$startX = 10;
$y = $pdf->GetY();

$compliance_color = $overall_compliance_pct === null ? [107, 114, 128] : ($overall_compliance_pct >= 90 ? [22, 163, 74] : ($overall_compliance_pct >= 75 ? [217, 119, 6] : [220, 38, 38]));

$pdf->KpiCard($startX, $y, $cardW, $cardH, 'Total Tickets', (string) $total_all);
$pdf->KpiCard($startX + $cardW + 5, $y, $cardW, $cardH, 'Avg Resolution', $avg_resolution !== null ? round($avg_resolution, 1) . 'h' : 'N/A');
$pdf->KpiCard($startX + ($cardW + 5) * 2, $y, $cardW, $cardH, 'SLA Breaches', (string) $sla_breaches, $sla_breaches > 0 ? [220, 38, 38] : [22, 163, 74]);
$pdf->KpiCard($startX + ($cardW + 5) * 3, $y, $cardW, $cardH, 'SLA Compliance', $overall_compliance_pct !== null ? $overall_compliance_pct . '%' : 'N/A', $compliance_color);

$pdf->SetY($y + $cardH + 8);

// ---------------------------------------------------------
// Bar charts: status / priority / category breakdown
// ---------------------------------------------------------
$pdf->SectionTitle('Tickets by Status');
$statusColors = [[148, 163, 184], [217, 119, 6], [22, 163, 74]];
$endY = $pdf->BarChart(15, $pdf->GetY(), 180, 30, $status_counts, $statusColors);
$pdf->SetY($endY + 4);

$pdf->SectionTitle('Tickets by Priority');
$priorityColors = [[22, 163, 74], [217, 119, 6], [220, 38, 38]];
$endY = $pdf->BarChart(15, $pdf->GetY(), 180, 30, $priority_counts, $priorityColors);
$pdf->SetY($endY + 4);

$pdf->SectionTitle('Tickets by Category');
$categoryColors = [[79, 70, 229], [124, 58, 237], [8, 145, 178], [148, 163, 184]];
$endY = $pdf->BarChart(15, $pdf->GetY(), 180, 30, $category_counts, $categoryColors);
$pdf->SetY($endY + 8);

// ---------------------------------------------------------
// Technician performance table
// ---------------------------------------------------------
if ($pdf->GetY() > 220) {
    $pdf->AddPage();
}
$pdf->SectionTitle('Technician Performance');
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(243, 244, 246);
$cols = ['Technician' => 55, 'Total Assigned' => 32, 'Closed' => 25, 'Active' => 25, 'Avg Resolution' => 40];
foreach ($cols as $label => $width) {
    $pdf->Cell($width, 7, pdf_safe($label), 1, 0, 'L', true);
}
$pdf->Ln();
$pdf->SetFont('Arial', '', 9);
foreach ($technician_perf as $t) {
    $pdf->Cell($cols['Technician'], 7, pdf_safe($t['name']), 1);
    $pdf->Cell($cols['Total Assigned'], 7, (string) (int) $t['total_assigned'], 1);
    $pdf->Cell($cols['Closed'], 7, (string) (int) $t['closed_count'], 1);
    $pdf->Cell($cols['Active'], 7, (string) (int) $t['active_count'], 1);
    $pdf->Cell($cols['Avg Resolution'], 7, $t['avg_hours'] !== null ? round($t['avg_hours'], 1) . 'h' : 'N/A', 1);
    $pdf->Ln();
}
if (empty($technician_perf)) {
    $pdf->Cell(array_sum($cols), 8, 'No technicians yet.', 1, 1, 'C');
}

$pdf->Output('D', 'helpdeskpro-analytics-' . date('Y-m-d') . '.pdf');
exit;
