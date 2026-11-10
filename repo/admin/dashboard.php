<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$stats = $pdo->query("
    SELECT
        SUM(status = 'Open') AS open_count,
        SUM(status = 'In Progress') AS progress_count,
        SUM(status = 'Closed') AS closed_count,
        SUM(assigned_to IS NULL) AS unassigned_count,
        COUNT(*) AS total
    FROM tickets
")->fetch();

$workload = $pdo->query("
    SELECT u.name, COUNT(t.ticket_id) AS ticket_count,
           SUM(t.status != 'Closed') AS active_count
    FROM users u
    LEFT JOIN tickets t ON t.assigned_to = u.user_id
    WHERE u.role = 'technician'
    GROUP BY u.user_id, u.name
")->fetchAll();

$recent = $pdo->query("
    SELECT t.*, u.name AS submitter_name FROM tickets t
    JOIN users u ON t.user_id = u.user_id
    ORDER BY t.created_at DESC LIMIT 8
")->fetchAll();

$page_title = 'Admin Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h1 class="h3 fw-bold mb-1">Admin dashboard</h1>
        <p class="text-muted mb-0">Organization-wide ticket overview.</p>
    </div>
    <a href="analytics.php" class="btn btn-outline-primary d-inline-flex align-items-center">
        <i class="bi bi-graph-up me-2"></i> View full analytics
    </a>
</div>

<!-- Stats Cards Grid -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl">
        <div class="card p-3 h-100">
            <div class="text-muted fs-7 mb-1"><i class="bi bi-door-open me-1"></i> Open</div>
            <div class="fs-2 fw-bold"><?= (int) $stats['open_count'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card p-3 h-100">
            <div class="text-muted fs-7 mb-1"><i class="bi bi-hourglass-split me-1"></i> In Progress</div>
            <div class="fs-2 fw-bold"><?= (int) $stats['progress_count'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card p-3 h-100">
            <div class="text-muted fs-7 mb-1"><i class="bi bi-check-circle me-1"></i> Closed</div>
            <div class="fs-2 fw-bold"><?= (int) $stats['closed_count'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-6 col-xl">
        <div class="card p-3 h-100">
            <div class="text-muted fs-7 mb-1"><i class="bi bi-person-x me-1"></i> Unassigned</div>
            <div class="fs-2 fw-bold"><?= (int) $stats['unassigned_count'] ?></div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl">
        <div class="card p-3 h-100 bg-primary text-white">
            <div class="opacity-75 fs-7 mb-1"><i class="bi bi-collection me-1"></i> Total Tickets</div>
            <div class="fs-2 fw-bold"><?= (int) $stats['total'] ?></div>
        </div>
    </div>
</div>

<!-- Main Content Section -->
<div class="row g-4">
    <!-- Technician Workload Table -->
    <div class="col-12 col-xl-5">
        <div class="card h-100">
            <div class="card-header bg-white py-3 border-0 fw-bold">
                <i class="bi bi-person-workspace text-primary me-2"></i>Technician workload
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light fs-8 text-uppercase">
                        <tr>
                            <th>Technician</th>
                            <th class="text-center">Total Tickets</th>
                            <th class="text-end">Active Tickets</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($workload as $w): ?>
                        <tr>
                            <td class="fw-medium"><?= e($w['name']) ?></td>
                            <td class="text-center"><span class="badge bg-light text-dark border"><?= (int) $w['ticket_count'] ?></span></td>
                            <td class="text-end"><span class="badge bg-success-subtle text-success"><?= (int) $w['active_count'] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($workload)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4">No technicians yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Tickets Table -->
    <div class="col-12 col-xl-7">
        <div class="card h-100">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <span class="fw-bold"><i class="bi bi-clock-history text-primary me-2"></i>Recent tickets</span>
                <a href="all_tickets.php" class="fs-7 text-decoration-none">View all &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light fs-8 text-uppercase">
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Submitter</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent as $t): ?>
                        <tr>
                            <td class="fw-semibold text-muted">#<?= $t['ticket_id'] ?></td>
                            <td class="fw-medium"><?= e($t['title']) ?></td>
                            <td><?= e($t['submitter_name']) ?></td>
                            <td><?= priority_badge($t['priority']) ?></td>
                            <td><?= status_badge($t['status']) ?></td>
                            <td class="text-end"><a href="../ticket.php?id=<?= $t['ticket_id'] ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recent)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No tickets yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
