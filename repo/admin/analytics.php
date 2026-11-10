<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

// ---------------------------------------------------------
// SLA thresholds (hours) by priority - tune these to match
// whatever service levels your organization commits to.
// ---------------------------------------------------------
$sla_hours = ['High' => 24, 'Medium' => 72, 'Low' => 120];

// ---------------------------------------------------------
// KPI: Average resolution time (closed tickets only)
// ---------------------------------------------------------
$avg_resolution = $pdo->query("
    SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) AS avg_hours
    FROM tickets WHERE status = 'Closed'
")->fetch()['avg_hours'];

// ---------------------------------------------------------
// KPI: Tickets this week vs last week (trend arrow)
// ---------------------------------------------------------
$this_week = $pdo->query("SELECT COUNT(*) FROM tickets WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$last_week = $pdo->query("SELECT COUNT(*) FROM tickets WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

// ---------------------------------------------------------
// KPI: SLA breaches - open/in-progress tickets that have exceeded
// their priority's threshold
// ---------------------------------------------------------
$sla_case = "CASE priority " .
    "WHEN 'High' THEN {$sla_hours['High']} " .
    "WHEN 'Medium' THEN {$sla_hours['Medium']} " .
    "ELSE {$sla_hours['Low']} END";
// Breach = past its BUSINESS-hours deadline (see includes/business_hours.php)
$sla_breaches = $pdo->query("
    SELECT COUNT(*) FROM tickets
    WHERE status != 'Closed'
    AND escalated = 0
    AND sla_due_at IS NOT NULL
    AND sla_due_at < NOW()
")->fetchColumn();

$total_open_active = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status != 'Closed'")->fetchColumn();
$escalated_active = $pdo->query("SELECT COUNT(*) FROM tickets WHERE escalated = 1 AND status != 'Closed'")->fetchColumn();

// ---------------------------------------------------------
// Chart data: tickets by status / priority / category
// ---------------------------------------------------------
$status_counts = $pdo->query("SELECT status, COUNT(*) AS c FROM tickets GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$priority_counts = $pdo->query("SELECT priority, COUNT(*) AS c FROM tickets GROUP BY priority")->fetchAll(PDO::FETCH_KEY_PAIR);
$category_counts = $pdo->query("SELECT category, COUNT(*) AS c FROM tickets GROUP BY category")->fetchAll(PDO::FETCH_KEY_PAIR);

// Per-department workload: how each support team is loaded, and how well
// each is keeping to its SLA. This is the main reason departments exist.
$department_stats = $pdo->query("
    SELECT d.name,
        COUNT(t.ticket_id) AS total,
        SUM(t.status != 'Closed') AS active,
        SUM(t.status = 'Closed') AS closed,
        SUM(t.escalated = 1) AS breached,
        AVG(CASE WHEN t.status = 'Closed' THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at) END) AS avg_hours
    FROM departments d
    LEFT JOIN tickets t ON t.department_id = d.department_id
    GROUP BY d.department_id, d.name
    ORDER BY total DESC, d.name
")->fetchAll();

// ---------------------------------------------------------
// Chart data: ticket volume over the last 30 days
// ---------------------------------------------------------
$daily = $pdo->query("
    SELECT DATE(created_at) AS d, COUNT(*) AS c
    FROM tickets
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY DATE(created_at)
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Fill in every day (including zero-ticket days) so the line chart isn't misleading
$daily_labels = [];
$daily_values = [];
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i day"));
    $daily_labels[] = date('M j', strtotime($day));
    $daily_values[] = (int) ($daily[$day] ?? 0);
}

// ---------------------------------------------------------
// SLA compliance trend - % of tickets closed each week that never
// breached their SLA threshold (escalated = 0), over the last 12 weeks.
// A ticket that was auto-escalated at any point counts as non-compliant,
// even if it was eventually resolved - the point of "compliance" is
// whether the team responded within the promised window, not just
// whether it got done eventually.
// ---------------------------------------------------------
$compliance_rows = $pdo->query("
    SELECT YEARWEEK(updated_at, 1) AS yw,
           MIN(DATE(updated_at)) AS week_start,
           COUNT(*) AS total_closed,
           SUM(escalated = 0) AS compliant
    FROM tickets
    WHERE status = 'Closed' AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
    GROUP BY YEARWEEK(updated_at, 1)
    ORDER BY yw
")->fetchAll();

$compliance_labels = [];
$compliance_values = [];
foreach ($compliance_rows as $row) {
    $compliance_labels[] = date('M j', strtotime($row['week_start']));
    $compliance_values[] = $row['total_closed'] > 0 ? round(($row['compliant'] / $row['total_closed']) * 100, 1) : null;
}

// Overall compliance (all-time), used as a single headline figure in exports
$overall_compliance = $pdo->query("
    SELECT
        SUM(escalated = 0) AS compliant,
        COUNT(*) AS total
    FROM tickets WHERE status = 'Closed'
")->fetch();
$overall_compliance_pct = $overall_compliance['total'] > 0
    ? round(($overall_compliance['compliant'] / $overall_compliance['total']) * 100, 1)
    : null;

// ---------------------------------------------------------
// Technician performance table
// ---------------------------------------------------------
$technician_perf = $pdo->query("
    SELECT
        u.name,
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

$page_title = 'Analytics';
include __DIR__ . '/../includes/header.php';
?>

<!-- Top Action Bar -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h1 class="h3 fw-bold mb-1">Analytics</h1>
        <p class="text-muted mb-0">Ticket volume, resolution time, and technician performance</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <form method="POST" action="run_escalation.php" class="d-inline">
            <?php csrf_field(); ?>
            <button type="submit" class="btn btn-warning text-dark fw-medium d-inline-flex align-items-center">
                <i class="bi bi-lightning-charge me-1"></i> Run SLA check now
            </button>
        </form>
        <div class="dropdown">
            <button class="btn btn-outline-primary dropdown-toggle d-inline-flex align-items-center" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-download me-1"></i> Export report
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="export_analytics_pdf.php"><i class="bi bi-file-earmark-pdf me-2"></i>PDF report</a></li>
                <li><a class="dropdown-item" href="export_analytics_csv.php"><i class="bi bi-file-earmark-spreadsheet me-2"></i>CSV data</a></li>
            </ul>
        </div>
        <a href="dashboard.php" class="btn btn-outline-secondary d-inline-flex align-items-center">
            <i class="bi bi-arrow-left me-1"></i> Back to dashboard
        </a>
    </div>
</div>

<!-- Analytics Metric Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl">
        <div class="card p-3">
            <div class="text-muted fs-7 mb-1"><i class="bi bi-clock me-1"></i> Avg. Resolution</div>
            <div class="fs-3 fw-bold"><?= $avg_resolution !== null ? round($avg_resolution, 1) . 'h' : 'N/A' ?></div>
            <div class="fs-8 text-muted">closed tickets</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card p-3">
            <div class="text-muted fs-7 mb-1"><i class="bi bi-calendar-event me-1"></i> This Week</div>
            <div class="fs-3 fw-bold"><?= (int) $this_week ?></div>
            <?php if ($last_week > 0):
                $delta = round((($this_week - $last_week) / $last_week) * 100);
            ?>
                <div class="fs-8 <?= $delta > 0 ? 'text-danger' : 'text-success' ?>">
                    <i class="bi bi-arrow-<?= $delta > 0 ? 'up' : 'down' ?>-short"></i> <?= abs($delta) ?>% vs last week
                </div>
            <?php else: ?>
                <div class="fs-8 text-muted">vs <?= (int) $last_week ?> last week</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card p-3 <?= $sla_breaches > 0 ? 'border-danger' : '' ?>">
            <div class="text-muted fs-7 mb-1"><i class="bi bi-shield-exclamation me-1"></i> SLA Breaches</div>
            <div class="fs-3 fw-bold <?= $sla_breaches > 0 ? 'text-danger' : '' ?>"><?= (int) $sla_breaches ?></div>
            <div class="fs-8 text-muted">not yet escalated</div>
        </div>
    </div>
    <div class="col-6 col-md-6 col-xl">
        <div class="card p-3 <?= $escalated_active > 0 ? 'border-warning' : '' ?>">
            <div class="text-muted fs-7 mb-1"><i class="bi bi-lightning me-1"></i> Escalated</div>
            <div class="fs-3 fw-bold <?= $escalated_active > 0 ? 'text-warning' : '' ?>"><?= (int) $escalated_active ?></div>
            <div class="fs-8 text-muted">still active</div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl">
        <div class="card p-3">
            <div class="text-muted fs-7 mb-1"><i class="bi bi-inbox me-1"></i> Active Tickets</div>
            <div class="fs-3 fw-bold"><?= (int) $total_open_active ?></div>
            <div class="fs-8 text-muted">not yet closed</div>
        </div>
    </div>
</div>

<!-- Donut Charts Row -->
<div class="row g-4 mb-4">
    <div class="col-12 col-md-4">
        <div class="card h-100 p-3">
            <h6 class="fw-bold mb-3 text-center">Tickets by status</h6>
            <div style="height: 200px;"><canvas id="statusChart"></canvas></div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card h-100 p-3">
            <h6 class="fw-bold mb-3 text-center">Tickets by priority</h6>
            <div style="height: 200px;"><canvas id="priorityChart"></canvas></div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card h-100 p-3">
            <h6 class="fw-bold mb-3 text-center">Tickets by category</h6>
            <div style="height: 200px;"><canvas id="categoryChart"></canvas></div>
        </div>
    </div>
</div>

<!-- 30-day trend -->
<div class="card p-3 mb-4">
    <h6 class="fw-bold mb-2 text-center">Ticket volume &mdash; last 30 days</h6>
    <div style="height: 220px;"><canvas id="trendChart"></canvas></div>
</div>

<!-- SLA compliance trend -->
<div class="card p-3 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="fw-bold mb-0">SLA compliance &mdash; weekly, last 12 weeks</h6>
        <?php if ($overall_compliance_pct !== null): ?>
            <span class="badge <?= $overall_compliance_pct >= 90 ? 'bg-success-subtle text-success' : ($overall_compliance_pct >= 75 ? 'bg-warning-subtle text-warning-emphasis' : 'bg-danger-subtle text-danger') ?> fs-7">
                <?= $overall_compliance_pct ?>% all-time
            </span>
        <?php endif; ?>
    </div>
    <p class="text-muted fs-8 mb-2">Share of closed tickets that never breached their SLA threshold (weren't auto-escalated).</p>
    <?php if (empty($compliance_values)): ?>
        <p class="text-muted text-center py-4 mb-0">Not enough closed tickets yet to show a trend.</p>
    <?php else: ?>
        <div style="height: 200px;"><canvas id="complianceChart"></canvas></div>
    <?php endif; ?>
</div>

<!-- Department Workload Table -->
<div class="card mb-4">
    <div class="card-header bg-white py-3 border-0 fw-bold">
        <i class="bi bi-diagram-3 text-primary me-2"></i>Department workload
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light fs-8 text-uppercase">
                <tr>
                    <th>Department</th>
                    <th>Total</th>
                    <th>Active</th>
                    <th>Closed</th>
                    <th>SLA Breached</th>
                    <th>Avg Resolution</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($department_stats as $d): ?>
                <tr>
                    <td class="fw-medium"><?= e($d['name']) ?></td>
                    <td><?= (int) $d['total'] ?></td>
                    <td><?= (int) $d['active'] ?></td>
                    <td><?= (int) $d['closed'] ?></td>
                    <td>
                        <?php if ((int) $d['breached'] > 0): ?>
                            <span class="badge bg-danger-subtle text-danger"><?= (int) $d['breached'] ?></span>
                        <?php else: ?>
                            <span class="badge bg-success-subtle text-success">0</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $d['avg_hours'] !== null ? round($d['avg_hours'], 1) . 'h' : '&mdash;' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($department_stats)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No departments yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Technician Performance Table -->
<div class="card">
    <div class="card-header bg-white py-3 border-0 fw-bold">
        <i class="bi bi-award text-primary me-2"></i>Technician performance
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light fs-8 text-uppercase">
                <tr>
                    <th>Technician</th>
                    <th>Total Assigned</th>
                    <th>Closed</th>
                    <th>Active</th>
                    <th>Avg. Resolution Time</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($technician_perf as $t): ?>
                <tr>
                    <td class="fw-medium"><?= e($t['name']) ?></td>
                    <td><?= (int) $t['total_assigned'] ?></td>
                    <td><?= (int) $t['closed_count'] ?></td>
                    <td><span class="badge bg-success-subtle text-success"><?= (int) $t['active_count'] ?></span></td>
                    <td><?= $t['avg_hours'] !== null ? round($t['avg_hours'], 1) . 'h' : '&mdash;' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($technician_perf)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No technicians yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const palette = {
    Open: '#94a3b8', 'In Progress': '#d97706', Closed: '#16a34a',
    Low: '#16a34a', Medium: '#d97706', High: '#dc2626',
    Hardware: '#4f46e5', Software: '#7c3aed', Network: '#0891b2', Other: '#94a3b8'
};

function makeDoughnut(canvasId, dataObj) {
    const labels = Object.keys(dataObj);
    const values = Object.values(dataObj);
    const colors = labels.map(l => palette[l] || '#4f46e5');
    new Chart(document.getElementById(canvasId), {
        type: 'doughnut',
        data: { labels, datasets: [{ data: values, backgroundColor: colors }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'bottom' } } }
    });
}

makeDoughnut('statusChart', <?= json_encode($status_counts) ?>);
makeDoughnut('priorityChart', <?= json_encode($priority_counts) ?>);
makeDoughnut('categoryChart', <?= json_encode($category_counts) ?>);

new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($daily_labels) ?>,
        datasets: [{
            label: 'Tickets Submitted',
            data: <?= json_encode($daily_values) ?>,
            borderColor: '#4f46e5',
            backgroundColor: 'rgba(79,70,229,0.08)',
            tension: 0.3,
            fill: true,
            pointRadius: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
        plugins: { legend: { display: false } }
    }
});

<?php if (!empty($compliance_values)): ?>
new Chart(document.getElementById('complianceChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($compliance_labels) ?>,
        datasets: [{
            label: 'SLA Compliance %',
            data: <?= json_encode($compliance_values) ?>,
            borderColor: '#16a34a',
            backgroundColor: 'rgba(22,163,74,0.08)',
            tension: 0.3,
            fill: true,
            pointRadius: 3,
            spanGaps: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } } },
        plugins: { legend: { display: false } }
    }
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
