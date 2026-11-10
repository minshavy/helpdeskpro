<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$category_filter = $_GET['category'] ?? '';
$technician_filter = $_GET['technician'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$where = "WHERE 1=1";
$params = [];

if ($search !== '') {
    $where .= " AND t.title LIKE ?";
    $params[] = "%$search%";
}
if (in_array($status_filter, ['Open', 'In Progress', 'Closed'])) {
    $where .= " AND t.status = ?";
    $params[] = $status_filter;
}
if (in_array($priority_filter, ['Low', 'Medium', 'High'])) {
    $where .= " AND t.priority = ?";
    $params[] = $priority_filter;
}
if (in_array($category_filter, ['Hardware', 'Software', 'Network', 'Other'])) {
    $where .= " AND t.category = ?";
    $params[] = $category_filter;
}
if ($technician_filter !== '') {
    if ($technician_filter === 'unassigned') {
        $where .= " AND t.assigned_to IS NULL";
    } else {
        $where .= " AND t.assigned_to = ?";
        $params[] = (int) $technician_filter;
    }
}
if ($date_from !== '') {
    $where .= " AND DATE(t.created_at) >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where .= " AND DATE(t.created_at) <= ?";
    $params[] = $date_to;
}

$sql = "
    SELECT t.ticket_id, t.title, t.category, t.priority, t.status, t.escalated,
           u.name AS submitter_name, u.email AS submitter_email, u.staff_id AS submitter_staff_id,
           tech.name AS technician_name,
           t.created_at, t.updated_at
    FROM tickets t
    JOIN users u ON t.user_id = u.user_id
    LEFT JOIN users tech ON t.assigned_to = tech.user_id
    $where
    ORDER BY t.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

audit_log($pdo, current_user()['user_id'], 'report_exported', 'Exported ticket list as CSV (' . count($tickets) . ' rows)');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="helpdeskpro-tickets-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Ticket ID', 'Title', 'Category', 'Priority', 'Status', 'Escalated', 'Submitter', 'Submitter Staff ID', 'Submitter Email', 'Technician', 'Created At', 'Last Updated']);

foreach ($tickets as $t) {
    fputcsv($out, [
        $t['ticket_id'],
        $t['title'],
        $t['category'],
        $t['priority'],
        $t['status'],
        $t['escalated'] ? 'Yes' : 'No',
        $t['submitter_name'],
        $t['submitter_staff_id'],
        $t['submitter_email'],
        $t['technician_name'] ?? 'Unassigned',
        $t['created_at'],
        $t['updated_at'],
    ]);
}
fclose($out);
exit;
