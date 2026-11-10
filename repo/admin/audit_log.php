<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$action_filter = $_GET['action_filter'] ?? '';
$user_filter   = $_GET['user'] ?? '';
$entity_filter = $_GET['entity'] ?? '';
$search        = trim($_GET['search'] ?? '');
$date_from     = $_GET['date_from'] ?? '';
$date_to       = $_GET['date_to'] ?? '';

$per_page = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = "WHERE 1=1";
$params = [];

if ($action_filter !== '') { $where .= " AND a.action = ?";      $params[] = $action_filter; }
if ($user_filter !== '')   { $where .= " AND a.user_id = ?";     $params[] = (int) $user_filter; }
if ($entity_filter !== '') { $where .= " AND a.entity_type = ?"; $params[] = $entity_filter; }
if ($date_from !== '')     { $where .= " AND DATE(a.created_at) >= ?"; $params[] = $date_from; }
if ($date_to !== '')       { $where .= " AND DATE(a.created_at) <= ?"; $params[] = $date_to; }
if ($search !== '') {
    $where .= " AND (a.details LIKE ? OR a.actor_name LIKE ? OR a.ip_address LIKE ? OR a.old_value LIKE ? OR a.new_value LIKE ?)";
    for ($i = 0; $i < 5; $i++) { $params[] = "%$search%"; }
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log a $where");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare("
    SELECT a.*, u.email AS user_email
    FROM audit_log a
    LEFT JOIN users u ON a.user_id = u.user_id
    $where
    ORDER BY a.created_at DESC, a.id DESC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$actions   = $pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$entities  = $pdo->query("SELECT DISTINCT entity_type FROM audit_log WHERE entity_type IS NOT NULL ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);
$actors    = $pdo->query("SELECT DISTINCT a.user_id, a.actor_name FROM audit_log a WHERE a.user_id IS NOT NULL ORDER BY a.actor_name")->fetchAll();

$action_labels = [
    'login_success' => ['Login Success', 'bg-success-subtle text-success'],
    'login_failed' => ['Login Failed', 'bg-danger-subtle text-danger'],
    'logout' => ['Logout', 'bg-secondary-subtle text-secondary'],
    'access_denied' => ['ACCESS DENIED', 'bg-danger-subtle text-danger'],
    'csrf_failure' => ['CSRF BLOCKED', 'bg-danger-subtle text-danger'],
    'assignment_blocked' => ['Assignment Blocked', 'bg-danger-subtle text-danger'],
    'account_registered' => ['Account Registered', 'bg-primary-subtle text-primary'],
    'user_created' => ['User Created', 'bg-primary-subtle text-primary'],
    'user_updated' => ['User Updated', 'bg-info-subtle text-info-emphasis'],
    'role_changed' => ['Role Changed', 'bg-warning-subtle text-warning-emphasis'],
    'user_deleted' => ['User Deleted', 'bg-danger-subtle text-danger'],
    'password_reset_requested' => ['Reset Requested', 'bg-secondary-subtle text-secondary'],
    'password_reset_completed' => ['Reset Completed', 'bg-success-subtle text-success'],
    'password_changed_forced' => ['Password Expired', 'bg-warning-subtle text-warning-emphasis'],
    '2fa_enabled' => ['2FA Enabled', 'bg-success-subtle text-success'],
    '2fa_disabled' => ['2FA Disabled', 'bg-warning-subtle text-warning-emphasis'],
    '2fa_verified' => ['2FA Verified', 'bg-success-subtle text-success'],
    '2fa_failed' => ['2FA Failed', 'bg-danger-subtle text-danger'],
    'ticket_created' => ['Ticket Created', 'bg-primary-subtle text-primary'],
    'ticket_viewed' => ['Ticket Viewed', 'bg-light text-secondary'],
    'ticket_edited' => ['Ticket Edited', 'bg-info-subtle text-info-emphasis'],
    'ticket_assigned' => ['Ticket Assigned', 'bg-primary-subtle text-primary'],
    'ticket_commented' => ['Comment Added', 'bg-info-subtle text-info-emphasis'],
    'ticket_status_changed' => ['Status Changed', 'bg-warning-subtle text-warning-emphasis'],
    'ticket_closed' => ['Ticket Closed', 'bg-success-subtle text-success'],
    'ticket_withdrawn' => ['Ticket Withdrawn', 'bg-secondary-subtle text-secondary'],
    'ticket_escalated' => ['SLA Escalation', 'bg-danger-subtle text-danger'],
    'report_exported' => ['Report Exported', 'bg-secondary-subtle text-secondary'],
    'kb_article_created' => ['KB Created', 'bg-primary-subtle text-primary'],
    'kb_article_updated' => ['KB Updated', 'bg-info-subtle text-info-emphasis'],
    'kb_article_deleted' => ['KB Deleted', 'bg-danger-subtle text-danger'],
    'canned_response_created' => ['Canned Response Created', 'bg-primary-subtle text-primary'],
    'canned_response_deleted' => ['Canned Response Deleted', 'bg-danger-subtle text-danger'],
    'department_created' => ['Department Created', 'bg-primary-subtle text-primary'],
    'department_updated' => ['Department Updated', 'bg-info-subtle text-info-emphasis'],
    'department_deleted' => ['Department Deleted', 'bg-danger-subtle text-danger'],
];

$filter_params = array_filter([
    'action_filter' => $action_filter, 'user' => $user_filter, 'entity' => $entity_filter,
    'search' => $search, 'date_from' => $date_from, 'date_to' => $date_to,
], fn($v) => $v !== '');

function pretty_json($raw) {
    if ($raw === null || $raw === '') return null;
    $decoded = json_decode($raw, true);
    if ($decoded === null) return $raw;
    $out = [];
    foreach ($decoded as $k => $v) {
        if (is_array($v) && isset($v['from'])) {
            $out[] = "$k: " . var_export($v['from'], true) . " -> " . var_export($v['to'], true);
        } else {
            $out[] = "$k: " . (is_scalar($v) ? $v : json_encode($v));
        }
    }
    return implode("\n", $out);
}

$page_title = 'Audit Log';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h1 class="h3 fw-bold mb-1">Audit log</h1>
        <p class="text-muted mb-0">Complete, append-only record of every action taken in the system.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="export_audit_csv.php?<?= http_build_query($filter_params) ?>" class="btn btn-outline-primary d-inline-flex align-items-center">
            <i class="bi bi-download me-1"></i> Export CSV
        </a>
        <a href="manage_users.php" class="btn btn-outline-secondary d-inline-flex align-items-center">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white py-3 border-0">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label fs-8 text-muted mb-1">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Details, user, IP..." value="<?= e($search) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fs-8 text-muted mb-1">Action</label>
                <select name="action_filter" class="form-select form-select-sm">
                    <option value="">All actions</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?= e($a) ?>" <?= $action_filter === $a ? 'selected' : '' ?>><?= e($action_labels[$a][0] ?? $a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fs-8 text-muted mb-1">User</label>
                <select name="user" class="form-select form-select-sm">
                    <option value="">All users</option>
                    <?php foreach ($actors as $ac): ?>
                        <option value="<?= $ac['user_id'] ?>" <?= $user_filter == $ac['user_id'] ? 'selected' : '' ?>><?= e($ac['actor_name'] ?: '#' . $ac['user_id']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fs-8 text-muted mb-1">Record type</label>
                <select name="entity" class="form-select form-select-sm">
                    <option value="">All types</option>
                    <?php foreach ($entities as $en): ?>
                        <option value="<?= e($en) ?>" <?= $entity_filter === $en ? 'selected' : '' ?>><?= e(ucfirst($en)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <div class="row g-1">
                    <div class="col-6">
                        <label class="form-label fs-8 text-muted mb-1">From</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($date_from) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label fs-8 text-muted mb-1">To</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($date_to) ?>">
                    </div>
                </div>
            </div>
            <div class="col-12 d-flex gap-2 mt-2">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <?php if (!empty($filter_params)): ?>
                    <a href="audit_log.php" class="btn btn-outline-secondary btn-sm">Clear</a>
                <?php endif; ?>
                <span class="ms-auto text-muted fs-8 align-self-center"><?= number_format($total) ?> entries</span>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 fs-7">
            <thead class="table-light fs-8 text-uppercase">
                <tr>
                    <th>Timestamp</th>
                    <th>Action</th>
                    <th>Who</th>
                    <th>Record</th>
                    <th>Details</th>
                    <th>Change</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log):
                $label = $action_labels[$log['action']] ?? [$log['action'], 'bg-secondary-subtle text-secondary'];
                $old = pretty_json($log['old_value']);
                $new = pretty_json($log['new_value']);
            ?>
                <tr>
                    <td class="text-nowrap text-muted"><?= e($log['created_at']) ?></td>
                    <td><span class="badge <?= $label[1] ?>"><?= e($label[0]) ?></span></td>
                    <td>
                        <?php if ($log['actor_name']): ?>
                            <div class="fw-medium"><?= e($log['actor_name']) ?></div>
                            <small class="text-muted"><?= e($log['actor_role'] ?: '') ?><?= $log['user_email'] ? ' · ' . e($log['user_email']) : '' ?></small>
                        <?php else: ?>
                            <span class="text-muted">Anonymous</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <?php if ($log['entity_type']): ?>
                            <?= e($log['entity_type']) ?><?= $log['entity_id'] ? ' #' . (int) $log['entity_id'] : '' ?>
                        <?php else: ?>
                            <span class="text-muted">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($log['details']) ?></td>
                    <td style="max-width:260px;">
                        <?php if ($old || $new): ?>
                            <?php if ($old): ?><div class="text-danger fs-8" style="white-space:pre-wrap;"><?= e($old) ?></div><?php endif; ?>
                            <?php if ($new): ?><div class="text-success fs-8" style="white-space:pre-wrap;"><?= e($new) ?></div><?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td><code class="fs-8"><?= e($log['ip_address']) ?></code></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No audit entries match these filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="card-footer bg-white py-3">
        <?php render_pagination($page, $total_pages, $filter_params); ?>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
