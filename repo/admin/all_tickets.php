<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$category_filter = $_GET['category'] ?? '';
$technician_filter = $_GET['technician'] ?? '';
$department_filter = $_GET['department'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$per_page = 15;
$page = max(1, (int) ($_GET['page'] ?? 1));

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
if ($department_filter !== '') {
    if ($department_filter === 'unrouted') {
        $where .= " AND t.department_id IS NULL";
    } else {
        $where .= " AND t.department_id = ?";
        $params[] = (int) $department_filter;
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

$count_sql = "SELECT COUNT(*) FROM tickets t $where";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_tickets = (int) $stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_tickets / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sql = "
    SELECT t.*, u.name AS submitter_name, tech.name AS technician_name, d.name AS department_name
    FROM tickets t
    JOIN users u ON t.user_id = u.user_id
    LEFT JOIN users tech ON t.assigned_to = tech.user_id
    LEFT JOIN departments d ON t.department_id = d.department_id
    $where
    ORDER BY t.created_at DESC
    LIMIT $per_page OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$technicians = $pdo->query("SELECT user_id, name FROM users WHERE role = 'technician' ORDER BY name")->fetchAll();
$departments = $pdo->query("SELECT department_id, name FROM departments ORDER BY name")->fetchAll();

// Preserve current filters when paginating
$filter_params = array_filter([
    'search' => $search, 'status' => $status_filter, 'priority' => $priority_filter,
    'category' => $category_filter, 'technician' => $technician_filter, 'department' => $department_filter,
    'date_from' => $date_from, 'date_to' => $date_to,
], fn($v) => $v !== '');

$page_title = 'All Tickets';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h1 class="h3 fw-bold mb-1">All tickets</h1>
        <p class="text-muted mb-0">Every ticket across the organization</p>
    </div>
    <div class="dropdown">
        <button class="btn btn-outline-primary dropdown-toggle d-inline-flex align-items-center" type="button" data-bs-toggle="dropdown">
            <i class="bi bi-download me-1"></i> Export
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="export_tickets_pdf.php?<?= http_build_query($filter_params) ?>"><i class="bi bi-file-earmark-pdf me-2"></i>PDF (current filters)</a></li>
            <li><a class="dropdown-item" href="export_tickets_csv.php?<?= http_build_query($filter_params) ?>"><i class="bi bi-file-earmark-spreadsheet me-2"></i>CSV (current filters)</a></li>
        </ul>
    </div>
</div>

<div class="card">
    <!-- Search & Filter Bar -->
    <div class="card-header bg-white py-3 border-0">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-12 col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" placeholder="Search by title..." value="<?= e($search) ?>">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select">
                    <option value="">All statuses</option>
                    <?php foreach (['Open', 'In Progress', 'Closed'] as $s): ?>
                        <option value="<?= $s ?>" <?= $status_filter === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="priority" class="form-select">
                    <option value="">All priorities</option>
                    <?php foreach (['Low', 'Medium', 'High'] as $p): ?>
                        <option value="<?= $p ?>" <?= $priority_filter === $p ? 'selected' : '' ?>><?= $p ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="category" class="form-select">
                    <option value="">All categories</option>
                    <?php foreach (['Hardware', 'Software', 'Network', 'Other'] as $c): ?>
                        <option value="<?= $c ?>" <?= $category_filter === $c ? 'selected' : '' ?>><?= $c ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="technician" class="form-select">
                    <option value="">All technicians</option>
                    <option value="unassigned" <?= $technician_filter === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
                    <?php foreach ($technicians as $tech): ?>
                        <option value="<?= $tech['user_id'] ?>" <?= $technician_filter == $tech['user_id'] ? 'selected' : '' ?>><?= e($tech['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="department" class="form-select">
                    <option value="">All departments</option>
                    <option value="unrouted" <?= $department_filter === 'unrouted' ? 'selected' : '' ?>>Unrouted</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['department_id'] ?>" <?= $department_filter == $d['department_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fs-8 text-muted mb-0">From</label>
                <input type="date" name="date_from" class="form-control" value="<?= e($date_from) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fs-8 text-muted mb-0">To</label>
                <input type="date" name="date_to" class="form-control" value="<?= e($date_to) ?>">
            </div>
            <div class="col-12 col-md-2 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">Filter</button>
                <?php if (!empty($filter_params)): ?>
                    <a href="all_tickets.php" class="btn btn-outline-secondary" title="Clear filters"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Tickets Table -->
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light fs-8 text-uppercase">
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Department</th>
                    <th>Submitter</th>
                    <th>Technician</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tickets as $t): ?>
                <tr>
                    <td class="fw-semibold text-muted">#<?= $t['ticket_id'] ?></td>
                    <td class="fw-medium"><?= e($t['title']) ?></td>
                    <td><?php if ($t['department_name']): ?><span class="badge bg-secondary-subtle text-secondary"><?= e($t['department_name']) ?></span><?php else: ?><span class="text-muted fs-8">&mdash;</span><?php endif; ?></td>
                    <td><?= e($t['submitter_name']) ?></td>
                    <td><?= e($t['technician_name'] ?? 'Unassigned') ?></td>
                    <td><?= priority_badge($t['priority']) ?></td>
                    <td><?= status_badge($t['status']) ?><?= escalated_badge($t['escalated']) ?></td>
                    <td class="text-end"><a href="../ticket.php?id=<?= $t['ticket_id'] ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($tickets)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No tickets found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="card-footer bg-white py-3">
        <?php render_pagination($page, $total_pages, $filter_params); ?>
        <p class="text-center text-muted fs-8 mt-2 mb-0"><?= $total_tickets ?> ticket<?= $total_tickets === 1 ? '' : 's' ?> total</p>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
