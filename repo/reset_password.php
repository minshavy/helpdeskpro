<?php
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = '';
$success = false;

$reset_row = $token !== '' ? find_valid_reset_token($pdo, $token) : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (!$reset_row) {
        $error = 'This reset link is invalid or has expired. Please request a new one.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (str_len($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW(), must_change_password = 0 WHERE user_id = ?");
            $stmt->execute([$hash, $reset_row['user_id']]);

            consume_password_reset_tokens($pdo, $reset_row['user_id']);

            // A successful reset proves the person owns the email inbox, so
            // it's reasonable to also clear any login lockout on their account.
            $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = ?");
            $stmt->execute([$reset_row['user_id']]);
            $user_email = $stmt->fetchColumn();
            if ($user_email) {
                clear_login_attempts($pdo, $user_email);
            }

            audit_log($pdo, $reset_row['user_id'], 'password_reset_completed', 'Password successfully reset via emailed link');

            $success = true;
        }
    }
}

$page_title = 'Reset Password';
include __DIR__ . '/includes/header.php';
?>

<div class="min-vh-100 d-flex align-items-center justify-content-center py-5">
<div class="card border-0 shadow-sm mx-auto" style="max-width: 440px; width:100%;">
<div class="card-body p-4 p-md-5">
    <h2 class="h4 fw-bold text-dark mb-4 text-center">Reset password</h2>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>Your password has been reset successfully.</div>
        <a href="login.php" class="btn btn-primary w-100 py-2 fw-medium">Go to login</a>

    <?php elseif (!$reset_row): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle me-1"></i>This reset link is invalid or has expired.</div>
        <p class="text-center small mt-3 mb-0"><a href="forgot_password.php" class="text-primary text-decoration-none">Request a new reset link</a></p>

    <?php else: ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-circle me-1"></i><?= e($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <?php csrf_field(); ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="mb-3">
                <label class="form-label fw-medium">New Password</label>
                <input type="password" name="password" class="form-control" required minlength="8" autofocus placeholder="At least 8 characters">
            </div>
            <div class="mb-3">
                <label class="form-label fw-medium">Confirm New Password</label>
                <input type="password" name="confirm_password" class="form-control" required minlength="8" placeholder="Re-enter password">
            </div>
            <button type="submit" class="btn btn-primary w-100 py-2 fw-medium">Reset password</button>
        </form>
    <?php endif; ?>
</div>
</div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
