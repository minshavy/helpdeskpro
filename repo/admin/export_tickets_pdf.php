<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
require_once __DIR__ . '/../lib/FPDF/fpdf.php';

$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$category_filter = $_GET['category'] ?? '';
$technician_filter = $_GET['technician'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$where = "WHERE 1=1";
$params = [];
$filter_summary = [];

if ($search !== '') {
    $where .= " AND t.title LIKE ?";
    $params[] = "%$search%";
    $filter_summary[] = "Search: \"$search\"";
}
if (in_array($status_filter, ['Open', 'In Progress', 'Closed'])) {
    $where .= " AND t.status = ?";
    $params[] = $status_filter;
    $filter_summary[] = "Status: $status_filter";
}
if (in_array($priority_filter, ['Low', 'Medium', 'High'])) {
    $where .= " AND t.priority = ?";
    $params[] = $priority_filter;
    $filter_summary[] = "Priority: $priority_filter";
}
if (in_array($category_filter, ['Hardware', 'Software', 'Network', 'Other'])) {
    $where .= " AND t.category = ?";
    $params[] = $category_filter;
    $filter_summary[] = "Category: $category_filter";
}
if ($technician_filter !== '') {
    if ($technician_filter === 'unassigned') {
        $where .= " AND t.assigned_to IS NULL";
        $filter_summary[] = "Technician: Unassigned";
    } else {
        $where .= " AND t.assigned_to = ?";
        $params[] = (int) $technician_filter;
        $stmt = $pdo->prepare("SELECT name FROM users WHERE user_id = ?");
        $stmt->execute([(int) $technician_filter]);
        $filter_summary[] = "Technician: " . ($stmt->fetchColumn() ?: '#' . $technician_filter);
    }
}
if ($date_from !== '') {
    $where .= " AND DATE(t.created_at) >= ?";
    $params[] = $date_from;
    $filter_summary[] = "From: $date_from";
}
if ($date_to !== '') {
    $where .= " AND DATE(t.created_at) <= ?";
    $params[] = $date_to;
    $filter_summary[] = "To: $date_to";
}

$sql = "
    SELECT t.ticket_id, t.title, t.category, t.priority, t.status, t.escalated,
           u.name AS submitter_name, tech.name AS technician_name, t.created_at
    FROM tickets t
    JOIN users u ON t.user_id = u.user_id
    LEFT JOIN users tech ON t.assigned_to = tech.user_id
    $where
    ORDER BY t.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$admin = current_user();
audit_log($pdo, $admin['user_id'], 'report_exported', 'Exported ticket list as PDF (' . count($tickets) . ' rows)');

// Strip non-Latin1 characters since core FPDF fonts only support Windows-1252.
// Good enough for this report; anything beyond basic accents renders as '?'.
function pdf_safe($text) {
    $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', (string) $text);
    return $converted !== false ? $converted : (string) $text;
}

class TicketsPDF extends FPDF {
    public $reportTitle = 'HelpDeskPro - Ticket Report';
    public $generatedBy = '';

    function Header() {
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(79, 70, 229);
        $this->Cell(0, 8, pdf_safe($this->reportTitle), 0, 1);
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, pdf_safe('Generated ' . date('Y-m-d H:i') . ' by ' . $this->generatedBy), 0, 1);
        $this->Ln(3);
        $this->SetDrawColor(220, 220, 220);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(4);
        $this->SetTextColor(0, 0, 0);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    function TableHeader($cols) {
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(243, 244, 246);
        $this->SetTextColor(55, 65, 81);
        foreach ($cols as $label => $width) {
            $this->Cell($width, 7, pdf_safe($label), 1, 0, 'L', true);
        }
        $this->Ln();
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(0, 0, 0);
    }
}

$pdf = new TicketsPDF();
$pdf->generatedBy = $admin['name'];
$pdf->AliasNbPages();
$pdf->AddPage('L'); // landscape for more columns
$pdf->SetAutoPageBreak(true, 15);

if (!empty($filter_summary)) {
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->MultiCell(0, 5, pdf_safe('Filters applied: ' . implode(' | ', $filter_summary)));
    $pdf->Ln(2);
    $pdf->SetTextColor(0, 0, 0);
}

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 6, pdf_safe(count($tickets) . ' ticket(s)'), 0, 1);
$pdf->Ln(1);

$cols = [
    'ID' => 12, 'Title' => 65, 'Category' => 22, 'Priority' => 18,
    'Status' => 22, 'Escalated' => 18, 'Submitter' => 35, 'Technician' => 35, 'Created' => 30,
];
$pdf->TableHeader($cols);

$row_alt = false;
foreach ($tickets as $t) {
    if ($pdf->GetY() > 180) {
        $pdf->AddPage('L');
        $pdf->TableHeader($cols);
    }
    $pdf->SetFillColor($row_alt ? 249 : 255, $row_alt ? 250 : 255, $row_alt ? 251 : 255);
    $pdf->Cell($cols['ID'], 6, '#' . $t['ticket_id'], 1, 0, 'L', true);
    $pdf->Cell($cols['Title'], 6, pdf_safe(mb_strimwidth($t['title'] ?? '', 0, 42, '...')), 1, 0, 'L', true);
    $pdf->Cell($cols['Category'], 6, pdf_safe($t['category']), 1, 0, 'L', true);
    $pdf->Cell($cols['Priority'], 6, pdf_safe($t['priority']), 1, 0, 'L', true);
    $pdf->Cell($cols['Status'], 6, pdf_safe($t['status']), 1, 0, 'L', true);
    $pdf->Cell($cols['Escalated'], 6, $t['escalated'] ? 'Yes' : 'No', 1, 0, 'L', true);
    $pdf->Cell($cols['Submitter'], 6, pdf_safe(mb_strimwidth($t['submitter_name'] ?? '', 0, 22, '...')), 1, 0, 'L', true);
    $pdf->Cell($cols['Technician'], 6, pdf_safe(mb_strimwidth($t['technician_name'] ?? 'Unassigned', 0, 22, '...')), 1, 0, 'L', true);
    $pdf->Cell($cols['Created'], 6, pdf_safe($t['created_at']), 1, 0, 'L', true);
    $pdf->Ln();
    $row_alt = !$row_alt;
}

if (empty($tickets)) {
    $pdf->Cell(array_sum($cols), 8, 'No tickets match the selected filters.', 1, 1, 'C');
}

$pdf->Output('D', 'helpdeskpro-tickets-' . date('Y-m-d') . '.pdf');
exit;
