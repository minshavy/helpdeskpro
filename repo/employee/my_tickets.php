<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('employee');
$user = current_user();

$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['category'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$per_page = 15;
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = "WHERE user_id = ?";
$params = [$user['user_id']];

if ($search !== '') {
    $where .= " AND title LIKE ?";
    $params[] = "%$search%";
}
if (in_array($status_filter, ['Open', 'In Progress', 'Closed'])) {
    $where .= " AND status = ?";
    $params[] = $status_filter;
}
if (in_array($category_filter, ['Hardware', 'Software', 'Network', 'Other'])) {
    $where .= " AND category = ?";
    $params[] = $category_filter;
}
if ($date_from !== '') {
    $where .= " AND DATE(created_at) >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where .= " AND DATE(created_at) <= ?";
    $params[] = $date_to;
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets $where");
$stmt->execute($params);
$total_tickets = (int) $stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_tickets / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sql = "SELECT * FROM tickets $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$filter_params = array_filter([
    'search' => $search, 'status' => $status_filter, 'category' => $category_filter,
    'date_from' => $date_from, 'date_to' => $date_to,
], fn($v) => $v !== '');

$page_title = 'My Tickets';
include __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
    <h1 class="h3 fw-bold mb-1">My Tickets</h1>
    <p class="text-muted mb-0">All the support tickets you've submitted</p>
</div>

<div class="card">
    <div class="card-header bg-white py-3 border-0">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-12 col-md-4">
                <input type="text" name="search" class="form-control" placeholder="Search by title..." value="<?= e($search) ?>">
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
                <select name="category" class="form-select">
                    <option value="">All categories</option>
                    <?php foreach (['Hardware', 'Software', 'Network', 'Other'] as $c): ?>
                        <option value="<?= $c ?>" <?= $category_filter === $c ? 'selected' : '' ?>><?= $c ?></option>
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
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">Filter</button>
                <?php if (!empty($filter_params)): ?>
                    <a href="my_tickets.php" class="btn btn-outline-secondary" title="Clear filters"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light fs-8 text-uppercase">
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Category</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tickets as $t): ?>
                <tr>
                    <td class="fw-semibold text-muted">#<?= $t['ticket_id'] ?></td>
                    <td class="fw-medium"><?= e($t['title']) ?></td>
                    <td><?= e($t['category']) ?></td>
                    <td><?= priority_badge($t['priority']) ?></td>
                    <td><?= status_badge($t['status']) ?><?= escalated_badge($t['escalated']) ?></td>
                    <td><?= e($t['created_at']) ?></td>
                    <td class="text-end">
                        <a href="../ticket.php?id=<?= $t['ticket_id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
                        <?php if ($t['status'] === 'Open' && $t['assigned_to'] === null): ?>
                            <a href="edit_ticket.php?id=<?= $t['ticket_id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($tickets)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No tickets found.</td></tr>
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
