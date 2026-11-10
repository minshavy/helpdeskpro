<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
$admin = current_user();

$error = '';

function count_admins($pdo) {
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'employee';
        $department_id = $_POST['department_id'] ?? '';
        $department_id = ($role === 'technician' && $department_id !== '') ? (int) $department_id : null;

        if ($name === '' || $email === '' || $password === '') {
            $error = 'All fields are required.';
        } elseif (str_len($name) > 100) {
            $error = 'Name is too long (max 100 characters).';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || str_len($email) > 150) {
            $error = 'Please enter a valid email address.';
        } elseif (str_len($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif (!in_array($role, ['admin', 'technician', 'employee'])) {
            $error = 'Invalid role.';
        } else {
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'A user with that email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                // must_change_password = 1: the admin chose this password, so the
                // user is made to replace it the first time they sign in.
                // Staff ID is issued automatically from the role
                $created = create_user_with_staff_id($pdo, $name, $email, $hash, $role, $department_id);
                $staff_id = $created['staff_id'];
                audit_log($pdo, $admin['user_id'], 'user_created', "Created user $email ($staff_id) with role $role",
                    'user', $created['user_id'], null, ['email' => $email, 'staff_id' => $staff_id, 'role' => $role]);
                flash('success', "User created. Their Staff ID is $staff_id.");
                header('Location: manage_users.php');
                exit;
            }
        }
    }

    if ($action === 'update_user') {
        $user_id = (int) ($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? ''; // optional - blank means keep existing password
        $role = $_POST['role'] ?? 'employee';
        $department_id = $_POST['department_id'] ?? '';
        $department_id = ($role === 'technician' && $department_id !== '') ? (int) $department_id : null;

        $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $target = $stmt->fetch();

        if (!$target) {
            flash('error', 'User not found.');
        } elseif ($name === '' || $email === '') {
            flash('error', 'Name and email are required.');
        } elseif (str_len($name) > 100) {
            flash('error', 'Name is too long (max 100 characters).');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || str_len($email) > 150) {
            flash('error', 'Please enter a valid email address.');
        } elseif ($password !== '' && str_len($password) < 8) {
            flash('error', 'New password must be at least 8 characters (or leave it blank to keep the current password).');
        } elseif (!in_array($role, ['admin', 'technician', 'employee'])) {
            flash('error', 'Invalid role.');
        } elseif ($target['role'] === 'admin' && $role !== 'admin' && count_admins($pdo) <= 1) {
            flash('error', 'Cannot change this role - at least one Admin account must remain.');
        } else {
            // Email must stay unique (excluding this user's own current row)
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $stmt->execute([$email, $user_id]);
            if ($stmt->fetch()) {
                flash('error', 'Another user already has that email address.');
            } else {
                $changes = [];
                if ($target['name'] !== $name) $changes[] = 'name';
                if ($target['email'] !== $email) $changes[] = 'email';
                if ($target['role'] !== $role) $changes[] = "role ({$target['role']} \u{2192} $role)";

                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    // An admin resetting SOMEONE ELSE's password knows it, so that
                    // person must replace it. Changing your own doesn't need that.
                    $force = ((int) $user_id !== (int) $admin['user_id']) ? 1 : 0;
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, password = ?, role = ?, department_id = ?, password_changed_at = NOW(), must_change_password = ? WHERE user_id = ?");
                    $stmt->execute([$name, $email, $hash, $role, $department_id, $force, $user_id]);
                    $changes[] = 'password';
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, department_id = ? WHERE user_id = ?");
                    $stmt->execute([$name, $email, $role, $department_id, $user_id]);
                }

                $summary = $changes ? ('Updated ' . implode(', ', $changes) . " for {$target['email']}") : "No changes made to {$target['email']}";
                audit_log($pdo, $admin['user_id'], 'user_updated', $summary);
                flash('success', 'User updated successfully.');
            }
        }
        header('Location: manage_users.php');
        exit;
    }

    if ($action === 'delete') {
        $user_id = (int) ($_POST['user_id'] ?? 0);

        if ($user_id === $admin['user_id']) {
            flash('error', 'You cannot delete your own account.');
        } else {
            $stmt = $pdo->prepare("SELECT role, email FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $target = $stmt->fetch();

            if (!$target) {
                flash('error', 'User not found.');
            } elseif ($target['role'] === 'admin' && count_admins($pdo) <= 1) {
                flash('error', 'Cannot delete the last remaining Admin account.');
            } else {
                $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
                $stmt->execute([$user_id]);
                audit_log($pdo, $admin['user_id'], 'user_deleted', "Deleted user {$target['email']} (role: {$target['role']})");
                flash('success', 'User deleted.');
            }
        }
        header('Location: manage_users.php');
        exit;
    }
}

$departments = $pdo->query("SELECT department_id, name FROM departments ORDER BY name")->fetchAll();

// What the next auto-issued ID looks like for each role, so the form can
// show it before the account is created.
$next_ids = [
    'employee'   => next_staff_id($pdo, 'employee'),
    'technician' => next_staff_id($pdo, 'technician'),
    'admin'      => next_staff_id($pdo, 'admin'),
];
$users = $pdo->query("
    SELECT u.*, d.name AS department_name
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.department_id
    ORDER BY u.role, u.name
")->fetchAll();

$role_badge_map = [
    'admin' => 'bg-primary-subtle text-primary',
    'technician' => 'bg-info-subtle text-info-emphasis',
    'employee' => 'bg-secondary-subtle text-secondary',
];

$page_title = 'Manage Users';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h1 class="h3 fw-bold mb-1">Manage users</h1>
        <p class="text-muted mb-0">Add accounts, edit details, change roles, and review activity.</p>
    </div>
    <a href="audit_log.php" class="btn btn-outline-secondary d-inline-flex align-items-center">
        <i class="bi bi-journal-text me-2"></i> View audit log
    </a>
</div>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle me-1"></i><?= e($error) ?></div><?php endif; ?>

<div class="row g-4">
    <!-- Add User Form -->
    <div class="col-12 col-lg-4">
        <div class="card p-4">
            <h5 class="fw-bold mb-3"><i class="bi bi-person-plus text-primary me-2"></i>Add new user</h5>
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="create">
                <div class="mb-3">
                    <label class="form-label fw-medium">Name</label>
                    <input type="text" name="name" class="form-control" placeholder="Full name" required maxlength="100">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-medium">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="user@helpdeskpro.com" required maxlength="150">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-medium">Staff ID</label>
                    <div class="form-control bg-light d-flex align-items-center gap-2" aria-live="polite">
                        <i class="bi bi-hash text-muted"></i>
                        <code id="nextStaffId" class="fw-semibold"><?= e($next_ids['employee']) ?></code>
                    </div>
                    <div class="form-text">Issued automatically from the role. They can sign in with this or their email.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-medium">Password</label>
                    <input type="password" name="password" class="form-control" placeholder="••••••••" required minlength="8">
                    <div class="form-text">At least 8 characters. They'll be asked to choose their own password the first time they sign in.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-medium">Role</label>
                    <select name="role" class="form-select" id="newUserRole"
                            data-next='<?= e(json_encode($next_ids)) ?>'>
                        <option value="employee" selected>Employee</option>
                        <option value="technician">Technician</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-medium">Department</label>
                    <select name="department_id" class="form-select">
                        <option value="">&mdash; None &mdash;</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['department_id'] ?>"><?= e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Only applies to technicians. Admins work across all departments.</div>
                </div>
                <button type="submit" class="btn btn-primary w-100">Create user</button>
            </form>
        </div>
    </div>

    <!-- User List Table -->
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light fs-8 text-uppercase">
                        <tr>
                            <th>Name</th>
                            <th>Staff ID</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Department</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td class="fw-medium"><?= e($u['name']) ?></td>
                            <td><code class="fs-8"><?= e($u['staff_id']) ?></code></td>
                            <td><?= e($u['email']) ?></td>
                            <td><span class="badge <?= $role_badge_map[$u['role']] ?? 'bg-secondary-subtle text-secondary' ?>"><?= e(ucfirst($u['role'])) ?></span></td>
                            <td>
                                <?php if ($u['department_name']): ?>
                                    <span class="badge bg-secondary-subtle text-secondary"><?= e($u['department_name']) ?></span>
                                <?php elseif ($u['role'] === 'technician'): ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis">Unassigned</span>
                                <?php else: ?>
                                    <span class="text-muted fs-8">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editModal<?= $u['user_id'] ?>">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                                <?php if ($u['user_id'] === $admin['user_id']): ?>
                                    <button class="btn btn-sm btn-link text-muted disabled" title="You can't delete your own account"><i class="bi bi-shield-lock"></i></button>
                                <?php else: ?>
                                    <form method="POST" onsubmit="return confirm('Delete this user?')" class="d-inline">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <!-- Edit User Modal -->
                        <div class="modal fade" id="editModal<?= $u['user_id'] ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="update_user">
                                        <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i>Edit user</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label class="form-label fw-medium">Name</label>
                                                <input type="text" name="name" class="form-control" value="<?= e($u['name']) ?>" required maxlength="100">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-medium">Email</label>
                                                <input type="email" name="email" class="form-control" value="<?= e($u['email']) ?>" required maxlength="150">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-medium">Staff ID</label>
                                                <input type="text" class="form-control bg-light" value="<?= e($u['staff_id']) ?>" readonly disabled>
                                                <div class="form-text">Issued when the account was created and kept for life, so past tickets and audit records still match &mdash; it doesn't change if the role changes.</div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-medium">New Password</label>
                                                <input type="password" name="password" class="form-control" placeholder="Leave blank to keep current password" minlength="8">
                                                <div class="form-text">Only fill this in if you want to change the password (min 8 characters).</div>
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label fw-medium">Role</label>
                                                <select name="role" class="form-select" <?= $u['user_id'] === $admin['user_id'] ? 'disabled' : '' ?>>
                                                    <option value="employee" <?= $u['role'] === 'employee' ? 'selected' : '' ?>>Employee</option>
                                                    <option value="technician" <?= $u['role'] === 'technician' ? 'selected' : '' ?>>Technician</option>
                                                    <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                </select>
                                                <?php if ($u['user_id'] === $admin['user_id']): ?>
                                                    <div class="form-text">You can't change your own role.</div>
                                                    <input type="hidden" name="role" value="<?= e($u['role']) ?>">
                                                <?php endif; ?>
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label fw-medium">Department</label>
                                                <select name="department_id" class="form-select">
                                                    <option value="">&mdash; None &mdash;</option>
                                                    <?php foreach ($departments as $d): ?>
                                                        <option value="<?= $d['department_id'] ?>" <?= (int) $u['department_id'] === (int) $d['department_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="form-text">Only applies to technicians.</div>
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
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var role = document.getElementById('newUserRole');
    var out = document.getElementById('nextStaffId');
    if (!role || !out) return;
    var next = JSON.parse(role.getAttribute('data-next'));
    role.addEventListener('change', function () {
        out.textContent = next[role.value] || '';
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
