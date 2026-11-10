<?php
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['force_pw_change_user_id'])) {
    header('Location: login.php');
    exit;
}

$pending_user_id = $_SESSION['force_pw_change_user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$pending_user_id]);
$user = $stmt->fetch();

if (!$user) {
    unset($_SESSION['force_pw_change_user_id']);
    header('Location: login.php');
    exit;
}

$reason = password_change_reason($user) ?? 'expired';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (str_len($password) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (password_verify($password, $user['password'])) {
        // Re-using the admin-issued (or expired) password defeats the point.
        $error = 'Choose a different password from the one you signed in with.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW(), must_change_password = 0 WHERE user_id = ?");
        $stmt->execute([$hash, $user['user_id']]);
        audit_log($pdo, $user['user_id'], 'password_changed_forced',
            $reason === 'admin_set'
                ? 'Replaced administrator-issued password on first sign-in'
                : 'Password changed due to expiry policy',
            'user', $user['user_id']);

        // Re-fetch so complete_login() sees the freshly-updated password_changed_at
        $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$user['user_id']]);
        $user = $stmt->fetch();

        grant_full_session($pdo, $user);
    }
}

$page_title = 'Password Update Required';
include __DIR__ . '/includes/header.php';
?>

<div class="min-vh-100 d-flex align-items-center justify-content-center py-5">
<div class="card border-0 shadow-sm mx-auto" style="max-width: 440px; width:100%;">
<div class="card-body p-4 p-md-5">
    <div class="text-center mb-4">
        <?php if ($reason === 'admin_set'): ?>
            <i class="bi bi-key text-primary" style="font-size: 2.5rem;"></i>
            <h2 class="h4 fw-bold text-dark mt-2 mb-1">Set your own password</h2>
            <p class="text-muted small mb-0">Welcome, <?= e($user['name']) ?>. Your password was set by an administrator, so please choose a new one that only you know.</p>
        <?php else: ?>
            <i class="bi bi-shield-exclamation text-warning" style="font-size: 2.5rem;"></i>
            <h2 class="h4 fw-bold text-dark mt-2 mb-1">Password update required</h2>
            <p class="text-muted small mb-0">Your password has expired under this organization's security policy. Please set a new one to continue.</p>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle me-1"></i><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <?php csrf_field(); ?>
        <div class="mb-3">
            <label class="form-label fw-medium">New Password</label>
            <input type="password" name="password" class="form-control" required minlength="8" autofocus placeholder="At least 8 characters">
        </div>
        <div class="mb-3">
            <label class="form-label fw-medium">Confirm New Password</label>
            <input type="password" name="confirm_password" class="form-control" required minlength="8" placeholder="Re-enter password">
        </div>
        <button type="submit" class="btn btn-primary w-100 py-2 fw-medium">Set new password &amp; continue</button>
    </form>
</div>
</div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
