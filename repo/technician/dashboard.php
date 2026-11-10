<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('technician');
$user = current_user();

$stmt = $pdo->prepare("
    SELECT
        SUM(status = 'Open') AS open_count,
        SUM(status = 'In Progress') AS progress_count,
        SUM(status = 'Closed') AS closed_count,
        COUNT(*) AS total
    FROM tickets WHERE assigned_to = ?
");
$stmt->execute([$user['user_id']]);
$stats = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT t.*, u.name AS submitter_name FROM tickets t
    JOIN users u ON t.user_id = u.user_id
    WHERE t.assigned_to = ? AND t.status != 'Closed'
    ORDER BY t.priority = 'High' DESC, t.created_at ASC
    LIMIT 5
");
$stmt->execute([$user['user_id']]);
$active = $stmt->fetchAll();

$page_title = 'Technician Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h1 class="h3 fw-bold mb-1">Welcome back, <?= e($user['name']) ?></h1>
        <p class="text-muted mb-0">Your assigned support tickets at a glance</p>
    </div>
    <a href="tickets.php" class="btn btn-outline-primary">View all assigned</a>
</div>

<!-- Personal Workload Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card p-3">
            <div class="text-muted fs-7 mb-1"><i class="bi bi-door-open me-1"></i> Open</div>
            <div class="fs-2 fw-bold"><?= (int) $stats['open_count'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3">
            <div class="text-muted fs-7 mb-1"><i class="bi bi-hourglass-split me-1"></i> In Progress</div>
            <div class="fs-2 fw-bold"><?= (int) $stats['progress_count'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3">
            <div class="text-muted fs-7 mb-1"><i class="bi bi-check-circle me-1"></i> Closed</div>
            <div class="fs-2 fw-bold"><?= (int) $stats['closed_count'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 bg-light">
            <div class="text-muted fs-7 mb-1"><i class="bi bi-collection me-1"></i> Total Assigned</div>
            <div class="fs-2 fw-bold"><?= (int) $stats['total'] ?></div>
        </div>
    </div>
</div>

<!-- Active Tickets Queue -->
<div class="card">
    <div class="card-header bg-white py-3 border-0">
        <h6 class="fw-bold mb-0">Active tickets &mdash; highest priority first</h6>
    </div>
    <?php if (empty($active)): ?>
        <div class="card-body text-center py-5">
            <i class="bi bi-emoji-smile text-primary empty-state-icon mb-3 d-block"></i>
            <p class="text-muted mb-0 fw-medium">No active tickets assigned. Nice and clear!</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light fs-8 text-uppercase">
                    <tr><th>ID</th><th>Title</th><th>Submitter</th><th>Priority</th><th>Status</th><th class="text-end">Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($active as $t): ?>
                    <tr>
                        <td class="fw-semibold text-muted">#<?= $t['ticket_id'] ?></td>
                        <td class="fw-medium"><?= e($t['title']) ?></td>
                        <td><?= e($t['submitter_name']) ?></td>
                        <td><?= priority_badge($t['priority']) ?></td>
                        <td><?= status_badge($t['status']) ?></td>
                        <td class="text-end"><a href="../ticket.php?id=<?= $t['ticket_id'] ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
