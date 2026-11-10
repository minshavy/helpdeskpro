<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
$admin = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $ticket_id = (int) ($_POST['ticket_id'] ?? 0);
    $technician_id = (int) ($_POST['technician_id'] ?? 0);

    // Confirm the target is actually a technician (not an arbitrary user_id)
    $stmt = $pdo->prepare("SELECT user_id, name, department_id FROM users WHERE user_id = ? AND role = 'technician'");
    $stmt->execute([$technician_id]);
    $tech = $stmt->fetch();

    if (!$tech) {
        flash('error', 'Invalid technician selected.');
        header('Location: assign_ticket.php');
        exit;
    }
    $techName = $tech['name'];

    // A ticket must go to a technician on the team that owns it. Without this
    // check, a tampered form could route e.g. an HR ticket to an IT technician.
    $stmt = $pdo->prepare("SELECT department_id FROM tickets WHERE ticket_id = ?");
    $stmt->execute([$ticket_id]);
    $ticket_dept = $stmt->fetchColumn();

    if ($ticket_dept !== null && $ticket_dept !== false
        && (int) $tech['department_id'] !== (int) $ticket_dept) {
        audit_log($pdo, $admin['user_id'], 'assignment_blocked',
            "Blocked cross-department assignment of ticket #$ticket_id to $techName",
            'ticket', $ticket_id, null,
            ['attempted_technician' => $technician_id, 'ticket_department' => $ticket_dept, 'technician_department' => $tech['department_id']]);
        flash('error', "$techName isn't on the team that owns this ticket.");
        header('Location: assign_ticket.php');
        exit;
    }

    $stmt = $pdo->prepare("UPDATE tickets SET assigned_to = ?, status = 'In Progress' WHERE ticket_id = ?");
    $stmt->execute([$technician_id, $ticket_id]);

    log_ticket_action($pdo, $ticket_id, $admin['user_id'], 'In Progress', "Assigned to technician: $techName");
    audit_log($pdo, $admin['user_id'], 'ticket_assigned',
        "Assigned ticket #$ticket_id to $techName",
        'ticket', $ticket_id,
        ['assigned_to' => null, 'status' => 'Open'],
        ['assigned_to' => $technician_id, 'assigned_to_name' => $techName, 'status' => 'In Progress']);
    notify_ticket_stakeholders(
        $pdo, $ticket_id, 'Ticket Assigned',
        "This ticket has been assigned to <strong>" . htmlspecialchars($techName) . "</strong> and is now In Progress.",
        $admin['user_id']
    );
    flash('success', "Ticket #$ticket_id assigned to $techName.");
    header('Location: assign_ticket.php');
    exit;
}

$unassigned = $pdo->query("
    SELECT t.*, u.name AS submitter_name, d.name AS department_name
    FROM tickets t
    JOIN users u ON t.user_id = u.user_id
    LEFT JOIN departments d ON t.department_id = d.department_id
    WHERE t.assigned_to IS NULL
    ORDER BY t.priority = 'High' DESC, t.created_at ASC
")->fetchAll();

// Group technicians by department so each ticket only offers its own team.
$all_technicians = $pdo->query("SELECT user_id, name, department_id FROM users WHERE role = 'technician' ORDER BY name")->fetchAll();
$techs_by_department = [];
foreach ($all_technicians as $techRow) {
    $key = $techRow['department_id'] === null ? 'none' : (int) $techRow['department_id'];
    $techs_by_department[$key][] = $techRow;
}

$page_title = 'Assign Tickets';
include __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
    <h1 class="h3 fw-bold mb-1">Assign unassigned tickets</h1>
    <p class="text-muted mb-0">Route incoming tickets to the right technician.</p>
</div>

<?php if (empty($unassigned)): ?>
<!-- Empty Queue Card -->
<div class="card p-5 text-center">
    <div class="py-4">
        <i class="bi bi-check-circle-fill text-success display-4 mb-3"></i>
        <h4 class="fw-bold">No unassigned tickets</h4>
        <p class="text-muted mb-0">All tickets have been routed to a technician. You're all caught up!</p>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light fs-8 text-uppercase">
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Department</th>
                    <th>Submitter</th>
                    <th>Priority</th>
                    <th colspan="2">Assign to</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($unassigned as $t):
                $deptKey = $t['department_id'] === null ? 'none' : (int) $t['department_id'];
                $eligible = $techs_by_department[$deptKey] ?? [];
            ?>
                <tr>
                    <td class="fw-semibold text-muted">#<?= $t['ticket_id'] ?></td>
                    <td class="fw-medium"><a href="../ticket.php?id=<?= $t['ticket_id'] ?>" class="text-decoration-none"><?= e($t['title']) ?></a></td>
                    <td>
                        <?php if ($t['department_name']): ?>
                            <span class="badge bg-secondary-subtle text-secondary"><?= e($t['department_name']) ?></span>
                        <?php else: ?>
                            <span class="badge bg-warning-subtle text-warning-emphasis">Unrouted</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($t['submitter_name']) ?></td>
                    <td><?= priority_badge($t['priority']) ?></td>
                    <td colspan="2">
                        <?php if (empty($eligible)): ?>
                            <span class="text-muted fs-7">
                                <i class="bi bi-exclamation-triangle me-1"></i>No technicians in this department yet &mdash;
                                <a href="manage_users.php">assign one</a>.
                            </span>
                        <?php else: ?>
                        <form method="POST" class="d-flex gap-2">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="ticket_id" value="<?= $t['ticket_id'] ?>">
                            <select name="technician_id" class="form-select form-select-sm" required>
                                <option value="">Select technician...</option>
                                <?php foreach ($eligible as $tech): ?>
                                    <option value="<?= $tech['user_id'] ?>"><?= e($tech['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-primary btn-sm">Assign</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
