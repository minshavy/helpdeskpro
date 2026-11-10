<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('employee');
$user = current_user();

$stmt = $pdo->prepare("
    SELECT
        SUM(status = 'Open') AS open_count,
        SUM(status = 'In Progress') AS progress_count,
        SUM(status = 'Closed') AS closed_count,
        COUNT(*) AS total
    FROM tickets WHERE user_id = ?
");
$stmt->execute([$user['user_id']]);
$stats = $stmt->fetch();

$stmt = $pdo->prepare("SELECT * FROM tickets WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$user['user_id']]);
$recent = $stmt->fetchAll();

$page_title = 'Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h1 class="h3 fw-bold mb-1">Welcome back, <?= e($user['name']) ?></h1>
        <p class="text-muted mb-0">Here's what's happening with your tickets.</p>
    </div>
    <a href="submit_ticket.php" class="btn btn-primary d-inline-flex align-items-center">
        <i class="bi bi-plus-lg me-1"></i> Submit ticket
    </a>
</div>

<?php if ((int) $stats['total'] === 0): ?>
<!-- First-visit welcome: only shown until the person raises their first ticket -->
<div class="card help-cta mb-4">
    <div class="card-body p-4 d-flex flex-column flex-md-row align-items-md-center gap-3">
        <i class="bi bi-stars text-primary fs-2 flex-shrink-0"></i>
        <div class="flex-grow-1">
            <h2 class="h6 fw-bold mb-1">Welcome to HelpDeskPro</h2>
            <p class="fs-7 text-muted mb-0">New here? The guide explains how to raise a ticket, how response times work, and what happens next. It takes about two minutes.</p>
        </div>
        <a href="../help.php" class="btn btn-primary flex-shrink-0">Read the guide</a>
    </div>
</div>
<?php endif; ?>

<!-- Personal Ticket Summary Cards -->
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
            <div class="text-muted fs-7 mb-1"><i class="bi bi-collection me-1"></i> Total</div>
            <div class="fs-2 fw-bold"><?= (int) $stats['total'] ?></div>
        </div>
    </div>
</div>

<!-- Personal Recent Tickets -->
<div class="card">
    <div class="card-header bg-white py-3 border-0">
        <h6 class="fw-bold mb-0"><i class="bi bi-clock-history me-2 text-primary"></i>Recent tickets</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light fs-8 text-uppercase">
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recent as $t): ?>
                <tr>
                    <td class="fw-semibold text-muted">#<?= $t['ticket_id'] ?></td>
                    <td class="fw-medium"><?= e($t['title']) ?></td>
                    <td><?= priority_badge($t['priority']) ?></td>
                    <td><?= status_badge($t['status']) ?></td>
                    <td><?= e($t['created_at']) ?></td>
                    <td class="text-end"><a href="../ticket.php?id=<?= $t['ticket_id'] ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recent)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No tickets yet. <a href="submit_ticket.php">Submit your first one</a>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
