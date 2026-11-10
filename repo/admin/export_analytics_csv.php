<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
require_once __DIR__ . '/../includes/escalation.php';

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

$compliance_weekly = $pdo->query("
    SELECT MIN(DATE(updated_at)) AS week_start, COUNT(*) AS total_closed, SUM(escalated = 0) AS compliant
    FROM tickets
    WHERE status = 'Closed' AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
    GROUP BY YEARWEEK(updated_at, 1)
    ORDER BY week_start
")->fetchAll();

audit_log($pdo, current_user()['user_id'], 'report_exported', 'Exported analytics report as CSV');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="helpdeskpro-analytics-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');

fputcsv($out, ['HelpDeskPro Analytics Report']);
fputcsv($out, ['Generated', date('Y-m-d H:i')]);
fputcsv($out, []);

fputcsv($out, ['Summary']);
fputcsv($out, ['Total tickets (all time)', $total_all]);
fputcsv($out, ['Active tickets', $total_active]);
fputcsv($out, ['Average resolution time (hours, closed tickets)', $avg_resolution !== null ? round($avg_resolution, 1) : 'N/A']);
fputcsv($out, ['SLA breaches (not yet escalated)', $sla_breaches]);
fputcsv($out, ['Escalated tickets (still active)', $escalated_active]);
fputcsv($out, ['Overall SLA compliance %', $overall_compliance_pct !== null ? $overall_compliance_pct : 'N/A']);
fputcsv($out, []);

fputcsv($out, ['Tickets by Status']);
foreach ($status_counts as $k => $v) fputcsv($out, [$k, $v]);
fputcsv($out, []);

fputcsv($out, ['Tickets by Priority']);
foreach ($priority_counts as $k => $v) fputcsv($out, [$k, $v]);
fputcsv($out, []);

fputcsv($out, ['Tickets by Category']);
foreach ($category_counts as $k => $v) fputcsv($out, [$k, $v]);
fputcsv($out, []);

fputcsv($out, ['Technician Performance']);
fputcsv($out, ['Technician', 'Total Assigned', 'Closed', 'Active', 'Avg Resolution (hours)']);
foreach ($technician_perf as $t) {
    fputcsv($out, [$t['name'], (int) $t['total_assigned'], (int) $t['closed_count'], (int) $t['active_count'], $t['avg_hours'] !== null ? round($t['avg_hours'], 1) : 'N/A']);
}
fputcsv($out, []);

fputcsv($out, ['Weekly SLA Compliance (last 12 weeks)']);
fputcsv($out, ['Week Starting', 'Closed Tickets', 'Compliant', 'Compliance %']);
foreach ($compliance_weekly as $w) {
    $pct = $w['total_closed'] > 0 ? round(($w['compliant'] / $w['total_closed']) * 100, 1) : 'N/A';
    fputcsv($out, [$w['week_start'], $w['total_closed'], $w['compliant'], $pct]);
}

fclose($out);
exit;
