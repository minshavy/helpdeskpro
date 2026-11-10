<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$user = current_user();
$ticket_id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT t.*, u.name AS submitter_name, u.staff_id AS submitter_staff_id,
           tech.name AS technician_name, tech.staff_id AS technician_staff_id,
           d.name AS department_name
    FROM tickets t
    JOIN users u ON t.user_id = u.user_id
    LEFT JOIN users tech ON t.assigned_to = tech.user_id
    LEFT JOIN departments d ON t.department_id = d.department_id
    WHERE t.ticket_id = ?
");
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch();

if (!$ticket) {
    flash('error', 'Ticket not found.');
    header('Location: ' . base_url('index.php'));
    exit;
}

// Permission check: employees can only view their own tickets,
// technicians only tickets assigned to them, admins can view all.
$can_view = ($user['role'] === 'admin')
    || ($user['role'] === 'employee' && $ticket['user_id'] == $user['user_id'])
    || ($user['role'] === 'technician' && $ticket['assigned_to'] == $user['user_id']);

if (!$can_view) {
    flash('error', 'You do not have permission to view that ticket.');
    header('Location: ' . base_url('index.php'));
    exit;
}

$can_update_status = in_array($user['role'], ['admin', 'technician']) && $ticket['status'] !== 'Closed';
$can_comment = true; // any role that can view can comment

// The submitter can edit/withdraw their own ticket only while it's still
// Open - once a technician has started work, changing it out from under
// them isn't safe, so editing is locked at that point.
$is_owner = $user['role'] === 'employee' && (int) $ticket['user_id'] === (int) $user['user_id'];

// The submitter may edit or withdraw only while NOBODY has picked the ticket
// up. Checking the status alone isn't enough: a technician can set an
// assigned ticket back to "Open", and the submitter must not then be able to
// change or cancel it out from under them.
$is_unclaimed = $ticket['status'] === 'Open' && $ticket['assigned_to'] === null;
$can_edit = $is_owner && $is_unclaimed;
$can_withdraw = $is_owner && $is_unclaimed;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'comment' && $can_comment) {
        $message = trim($_POST['message'] ?? '');
        if ($message !== '' && str_len($message) <= 2000) {
            log_ticket_action($pdo, $ticket_id, $user['user_id'], null, $message);
            audit_log($pdo, $user['user_id'], 'ticket_commented',
                "Commented on ticket #$ticket_id", 'ticket', $ticket_id, null, ['comment' => $message]);
            notify_ticket_stakeholders(
                $pdo, $ticket_id, 'New Comment',
                htmlspecialchars($user['name']) . " commented: \"" . htmlspecialchars($message) . "\"",
                $user['user_id']
            );
            flash('success', 'Comment added.');
        }
    }

    if ($action === 'update_status' && $can_update_status) {
        $new_status = $_POST['status'] ?? '';
        if (in_array($new_status, ['Open', 'In Progress', 'Closed'])) {
            $stmt = $pdo->prepare("UPDATE tickets SET status = ? WHERE ticket_id = ?");
            $stmt->execute([$new_status, $ticket_id]);
            log_ticket_action($pdo, $ticket_id, $user['user_id'], $new_status, "Status changed to $new_status");
            audit_log($pdo, $user['user_id'], 'ticket_status_changed',
                "Ticket #$ticket_id status: {$ticket['status']} -> $new_status",
                'ticket', $ticket_id, ['status' => $ticket['status']], ['status' => $new_status]);
            notify_ticket_stakeholders(
                $pdo, $ticket_id, 'Ticket Status Updated',
                "Status changed to <strong>" . htmlspecialchars($new_status) . "</strong> by " . htmlspecialchars($user['name']) . ".",
                $user['user_id']
            );
            flash('success', 'Ticket status updated.');
        }
    }

    if ($action === 'close' && $can_update_status) {
        $stmt = $pdo->prepare("UPDATE tickets SET status = 'Closed' WHERE ticket_id = ?");
        $stmt->execute([$ticket_id]);
        log_ticket_action($pdo, $ticket_id, $user['user_id'], 'Closed', 'Ticket closed.');
        audit_log($pdo, $user['user_id'], 'ticket_closed',
            "Closed ticket #$ticket_id", 'ticket', $ticket_id,
            ['status' => $ticket['status']], ['status' => 'Closed']);
        notify_ticket_stakeholders(
            $pdo, $ticket_id, 'Ticket Closed',
            'This ticket has been closed by ' . htmlspecialchars($user['name']) . '.',
            $user['user_id']
        );
        flash('success', 'Ticket closed.');
    }

    if ($action === 'withdraw' && $can_withdraw) {
        $stmt = $pdo->prepare("UPDATE tickets SET status = 'Closed' WHERE ticket_id = ?");
        $stmt->execute([$ticket_id]);
        log_ticket_action($pdo, $ticket_id, $user['user_id'], 'Closed', 'Ticket withdrawn by submitter.');
        audit_log($pdo, $user['user_id'], 'ticket_withdrawn',
            "Withdrew ticket #$ticket_id", 'ticket', $ticket_id,
            ['status' => $ticket['status']], ['status' => 'Closed']);
        flash('success', 'Ticket withdrawn.');
    }

    header('Location: ' . base_url('ticket.php?id=' . $ticket_id));
    exit;
}

// Record that this person read the ticket. Auditors care about who had
// sight of a record, not just who changed it.
audit_log($pdo, $user['user_id'], 'ticket_viewed', "Viewed ticket #$ticket_id", 'ticket', $ticket_id);

// Fetch ticket logs / comments
$stmt = $pdo->prepare("
    SELECT l.*, u.name AS author_name
    FROM ticket_logs l
    JOIN users u ON l.user_id = u.user_id
    WHERE l.ticket_id = ?
    ORDER BY l.timestamp ASC
");
$stmt->execute([$ticket_id]);
$logs = $stmt->fetchAll();

// Canned responses (shared library) - only technicians/admins get the quick-insert dropdown
$canned_responses = [];
if (in_array($user['role'], ['technician', 'admin'])) {
    $canned_responses = $pdo->query("SELECT id, title, body FROM canned_responses ORDER BY title")->fetchAll();
}

$page_title = 'Ticket #' . $ticket['ticket_id'];
include __DIR__ . '/includes/header.php';
?>

<?php
// Presentation layer redesigned from the Ticket Details design canvas.
$sla_overdue = !empty($ticket['sla_due_at'])
    && strtotime($ticket['sla_due_at']) < time()
    && $ticket['status'] !== 'Closed';
?>

<!-- Breadcrumb -->
<div class="mb-3">
    <a href="javascript:history.back()" class="text-decoration-none text-muted fs-7">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<!-- Header -->
<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start mb-4 gap-3">
    <div class="min-w-0">
        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
            <h1 class="h3 fw-bold mb-0"><?= e($ticket['title']) ?></h1>
            <?= status_badge($ticket['status']) ?><?= escalated_badge($ticket['escalated']) ?>
        </div>
        <p class="text-muted mb-0 fs-7">
            Ticket #<?= $ticket['ticket_id'] ?> &middot; Opened <?= e($ticket['created_at']) ?>
            <?php if ($ticket['department_name']): ?> &middot; <?= e($ticket['department_name']) ?><?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?php if ($can_edit): ?>
            <a href="<?= base_url('employee/edit_ticket.php?id=' . $ticket_id) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-pencil me-1"></i>Edit
            </a>
        <?php endif; ?>
        <?php if ($can_withdraw): ?>
            <form method="POST" onsubmit="return confirm('Withdraw this ticket? This closes it without a technician resolving it.')" class="d-inline">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="withdraw">
                <button type="submit" class="btn btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Withdraw</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <!-- Main column -->
    <div class="col-12 col-xl-8">

        <!-- Description + attachment -->
        <div class="card mb-4">
            <div class="card-body p-4">
                <h2 class="fs-7 fw-semibold text-muted mb-2">Description</h2>
                <p class="mb-0" style="line-height:1.6;"><?= nl2br(e($ticket['description'])) ?></p>

                <?php if ($ticket['attachment']): ?>
                    <div class="mt-4 pt-4 border-top">
                        <h2 class="fs-7 fw-semibold text-muted mb-2">Attachment</h2>
                        <?= render_attachment_preview($ticket['attachment'], base_url('uploads/' . $ticket['attachment'])) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Activity -->
        <div class="card">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-4"><i class="bi bi-clock-history text-primary me-2"></i>Activity</h2>

                <?php if (empty($logs)): ?>
                    <p class="text-muted text-center py-3 mb-0">No activity yet.</p>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($logs as $log):
                            $ip = preg_split('/\s+/', trim($log['author_name']));
                            $author_initials = strtoupper(substr($ip[0], 0, 1) . (count($ip) > 1 ? substr(end($ip), 0, 1) : ''));
                        ?>
                            <div class="d-flex gap-3">
                                <div class="avatar-circle flex-shrink-0" style="width:34px;height:34px;font-size:0.72rem;">
                                    <?= e($author_initials) ?>
                                </div>
                                <div class="min-w-0">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="fw-semibold fs-7"><?= e($log['author_name']) ?></span>
                                        <?php if ($log['status']): ?>
                                            <span class="badge bg-primary-subtle text-primary">set status: <?= e($log['status']) ?></span>
                                        <?php endif; ?>
                                        <small class="text-muted"><?= e($log['timestamp']) ?></small>
                                    </div>
                                    <p class="mb-0 fs-7 mt-1"><?= nl2br(e($log['message'])) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($ticket['status'] !== 'Closed'): ?>
                    <div class="mt-4 pt-4 border-top">
                        <form method="POST" id="commentForm">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="comment">
                            <label for="commentTextarea" class="form-label fw-medium fs-7">Add a comment</label>
                            <textarea name="message" id="commentTextarea" class="form-control mb-3" rows="3"
                                      placeholder="Type your reply..." required maxlength="2000"></textarea>

                            <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2">
                                <?php if (!empty($canned_responses)): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <label for="cannedResponseSelect" class="form-label fs-7 text-muted mb-0 flex-shrink-0">Canned reply</label>
                                        <select class="form-select form-select-sm" id="cannedResponseSelect" onchange="insertCannedResponse(this)">
                                            <option value="">Select a template...</option>
                                            <?php foreach ($canned_responses as $cr): ?>
                                                <option value="<?= e($cr['id']) ?>" data-body="<?= e($cr['body']) ?>"><?= e($cr['title']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php else: ?>
                                    <span></span>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary flex-shrink-0">Post comment</button>
                            </div>
                        </form>

                        <?php if (!empty($canned_responses)): ?>
                        <script>
                        function insertCannedResponse(select) {
                            var body = select.options[select.selectedIndex].getAttribute('data-body');
                            if (body) { document.getElementById('commentTextarea').value = body; }
                            select.selectedIndex = 0;
                        }
                        </script>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right rail -->
    <div class="col-12 col-xl-4">

        <!-- Details -->
        <div class="card mb-4">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-3">Details</h2>
                <div class="d-flex flex-column gap-3">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <span class="text-muted fs-7">Priority</span>
                        <?= priority_badge($ticket['priority']) ?>
                    </div>
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <span class="text-muted fs-7">Category</span>
                        <span class="fw-semibold fs-7"><?= e($ticket['category']) ?></span>
                    </div>
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <span class="text-muted fs-7">Department</span>
                        <?php if ($ticket['department_name']): ?>
                            <span class="badge bg-secondary-subtle text-secondary"><?= e($ticket['department_name']) ?></span>
                        <?php else: ?>
                            <span class="badge bg-warning-subtle text-warning-emphasis">Unrouted</span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <span class="text-muted fs-7">Submitted by</span>
                        <span class="fw-semibold fs-7 text-end"><?= e($ticket['submitter_name']) ?><br><code class="fs-8 fw-normal"><?= e($ticket['submitter_staff_id']) ?></code></span>
                    </div>
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <span class="text-muted fs-7">Assigned to</span>
                        <span class="fw-semibold fs-7 text-end"><?= e($ticket['technician_name'] ?? 'Unassigned') ?><?php if ($ticket['technician_staff_id']): ?><br><code class="fs-8 fw-normal"><?= e($ticket['technician_staff_id']) ?></code><?php endif; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- SLA card -->
        <?php if (!empty($ticket['sla_due_at'])): ?>
        <div class="card mb-4 <?= $sla_overdue ? 'border-danger' : '' ?>">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-clock <?= $sla_overdue ? 'text-danger' : 'text-success' ?>"></i>
                    <h2 class="h6 fw-bold mb-0 <?= $sla_overdue ? 'text-danger' : '' ?>">
                        <?= $sla_overdue ? 'SLA overdue' : ($ticket['status'] === 'Closed' ? 'SLA' : 'SLA on track') ?>
                    </h2>
                </div>
                <p class="text-muted fs-7 mb-3">
                    Due <strong class="text-body"><?= e($ticket['sla_due_at']) ?></strong>
                </p>
                <div class="progress" style="height:8px;">
                    <div class="progress-bar <?= $sla_overdue ? 'bg-danger' : 'bg-success' ?>"
                         role="progressbar"
                         style="width: <?= $sla_overdue ? '100' : '45' ?>%"
                         aria-valuenow="<?= $sla_overdue ? 100 : 45 ?>" aria-valuemin="0" aria-valuemax="100"
                         aria-label="SLA time used"></div>
                </div>
                <p class="text-muted fs-8 mt-3 mb-0">
                    Office hours: <?= e(BusinessHours::scheduleSummary()) ?>. Weekends and public holidays
                    are excluded from the SLA clock.
                </p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Status controls -->
        <?php if ($can_update_status): ?>
        <div class="card">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-3">Update status</h2>
                <form method="POST">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="update_status">
                    <label for="statusSelect" class="form-label text-muted fs-7">Status</label>
                    <select name="status" id="statusSelect" class="form-select mb-3">
                        <?php foreach (['Open', 'In Progress', 'Closed'] as $s): ?>
                            <option value="<?= $s ?>" <?= $s === $ticket['status'] ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary w-100">Save status</button>
                </form>
                <form method="POST" onsubmit="return confirm('Close this ticket?')" class="mt-2">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="close">
                    <button type="submit" class="btn btn-outline-secondary w-100">Close ticket</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
