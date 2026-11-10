<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
$admin = current_user();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '') {
            $error = 'Department name is required.';
        } elseif (str_len($name) > 100) {
            $error = 'Name is too long (max 100 characters).';
        } elseif (str_len($description) > 255) {
            $error = 'Description is too long (max 255 characters).';
        } else {
            $stmt = $pdo->prepare("SELECT department_id FROM departments WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetch()) {
                $error = 'A department with that name already exists.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO departments (name, description) VALUES (?, ?)");
                $stmt->execute([$name, $description !== '' ? $description : null]);
                audit_log($pdo, $admin['user_id'], 'department_created', "Created department: $name");
                flash('success', 'Department created.');
                header('Location: manage_departments.php');
                exit;
            }
        }
    }

    if ($action === 'update') {
        $id = (int) ($_POST['department_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM departments WHERE department_id = ?");
        $stmt->execute([$id]);
        $target = $stmt->fetch();

        if (!$target) {
            flash('error', 'Department not found.');
        } elseif ($name === '') {
            flash('error', 'Department name is required.');
        } elseif (str_len($name) > 100) {
            flash('error', 'Name is too long (max 100 characters).');
        } else {
            $stmt = $pdo->prepare("SELECT department_id FROM departments WHERE name = ? AND department_id != ?");
            $stmt->execute([$name, $id]);
            if ($stmt->fetch()) {
                flash('error', 'Another department already has that name.');
            } else {
                $stmt = $pdo->prepare("UPDATE departments SET name = ?, description = ? WHERE department_id = ?");
                $stmt->execute([$name, $description !== '' ? $description : null, $id]);
                audit_log($pdo, $admin['user_id'], 'department_updated', "Renamed '{$target['name']}' to '$name'");
                flash('success', 'Department updated.');
            }
        }
        header('Location: manage_departments.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['department_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM departments WHERE department_id = ?");
        $stmt->execute([$id]);
        $target = $stmt->fetch();

        if (!$target) {
            flash('error', 'Department not found.');
        } elseif ((int) $pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn() <= 1) {
            flash('error', 'Cannot delete the last remaining department.');
        } else {
            // Tickets/technicians keep existing; their department_id becomes
            // NULL via the FK, so nothing is destroyed - they just become
            // unrouted and can be reassigned.
            $stmt = $pdo->prepare("DELETE FROM departments WHERE department_id = ?");
            $stmt->execute([$id]);
            audit_log($pdo, $admin['user_id'], 'department_deleted', "Deleted department: {$target['name']}");
            flash('success', 'Department deleted. Its tickets and technicians are now unassigned.');
        }
        header('Location: manage_departments.php');
        exit;
    }
}

$departments = $pdo->query("
    SELECT d.*,
        (SELECT COUNT(*) FROM users u WHERE u.department_id = d.department_id AND u.role = 'technician') AS technician_count,
        (SELECT COUNT(*) FROM tickets t WHERE t.department_id = d.department_id) AS ticket_count,
        (SELECT COUNT(*) FROM tickets t WHERE t.department_id = d.department_id AND t.status != 'Closed') AS active_count
    FROM departments d
    ORDER BY d.name
")->fetchAll();

$page_title = 'Manage Departments';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h1 class="h3 fw-bold mb-1">Manage departments</h1>
        <p class="text-muted mb-0">Route tickets to separate support teams, each with its own technicians and queue.</p>
    </div>
    <a href="manage_users.php" class="btn btn-outline-secondary d-inline-flex align-items-center">
        <i class="bi bi-people me-2"></i> Manage users
    </a>
</div>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle me-1"></i><?= e($error) ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-lg-4">
        <div class="card p-4">
            <h5 class="fw-bold mb-3"><i class="bi bi-diagram-3 text-primary me-2"></i>New department</h5>
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="create">
                <div class="mb-3">
                    <label class="form-label fw-medium">Name</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Facilities" required maxlength="100">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-medium">Description</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="What this team handles..." maxlength="255"></textarea>
                </div>
                <button type="submit" class="btn btn-primary w-100">Create department</button>
            </form>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light fs-8 text-uppercase">
                        <tr>
                            <th>Department</th>
                            <th>Technicians</th>
                            <th>Tickets</th>
                            <th>Active</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($departments as $d): ?>
                        <tr>
                            <td>
                                <div class="fw-medium"><?= e($d['name']) ?></div>
                                <?php if ($d['description']): ?>
                                    <small class="text-muted"><?= e($d['description']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $d['technician_count'] === 0): ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis" title="Tickets here can't be assigned until a technician joins">0</span>
                                <?php else: ?>
                                    <?= (int) $d['technician_count'] ?>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $d['ticket_count'] ?></td>
                            <td><?= (int) $d['active_count'] ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editDept<?= $d['department_id'] ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" onsubmit="return confirm('Delete this department? Its tickets and technicians will become unassigned, not deleted.')" class="d-inline">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="department_id" value="<?= $d['department_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </td>
                        </tr>

                        <div class="modal fade" id="editDept<?= $d['department_id'] ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="department_id" value="<?= $d['department_id'] ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title fw-bold">Edit department</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label class="form-label fw-medium">Name</label>
                                                <input type="text" name="name" class="form-control" value="<?= e($d['name']) ?>" required maxlength="100">
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label fw-medium">Description</label>
                                                <textarea name="description" class="form-control" rows="3" maxlength="255"><?= e($d['description']) ?></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary">Save changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($departments)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No departments yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
