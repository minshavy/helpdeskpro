<?php
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

// Must arrive here via a successful password check on login.php
if (empty($_SESSION['2fa_pending_user_id'])) {
    header('Location: login.php');
    exit;
}

$pending_user_id = $_SESSION['2fa_pending_user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$pending_user_id]);
$user = $stmt->fetch();

if (!$user || !$user['two_factor_enabled']) {
    // Account state changed since the password check - safest to restart login
    unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_attempts']);
    header('Location: login.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $code = trim($_POST['code'] ?? '');

    if (($_SESSION['2fa_attempts'] ?? 0) >= 5) {
        unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_attempts']);
        flash('error', 'Too many invalid codes. Please log in again.');
        header('Location: login.php');
        exit;
    }

    if (TOTP::verifyCode($user['two_factor_secret'], $code)) {
        audit_log($pdo, $user['user_id'], '2fa_verified', 'Two-factor code verified successfully');
        complete_login($pdo, $user);
    } else {
        $_SESSION['2fa_attempts'] = ($_SESSION['2fa_attempts'] ?? 0) + 1;
        audit_log($pdo, $user['user_id'], '2fa_failed', 'Incorrect two-factor code entered');
        $error = 'Incorrect code. Please try again.';
    }
}

$page_title = 'Two-Factor Verification';
include __DIR__ . '/includes/header.php';
?>

<div class="min-vh-100 d-flex align-items-center justify-content-center py-5">
<div class="card border-0 shadow-sm mx-auto" style="max-width: 440px; width:100%;">
<div class="card-body p-4 p-md-5">
    <div class="text-center mb-4">
        <i class="bi bi-shield-lock text-primary" style="font-size: 2.5rem;"></i>
        <h2 class="h4 fw-bold text-dark mt-2 mb-1">Two-factor verification</h2>
        <p class="text-muted small mb-0">Enter the 6-digit code from your authenticator app.</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle me-1"></i><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <?php csrf_field(); ?>
        <div class="mb-3">
            <input type="text" name="code" class="form-control form-control-lg text-center" style="letter-spacing: 0.3em; font-size: 1.4rem;"
                   inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="000000" required autofocus autocomplete="one-time-code">
        </div>
        <button type="submit" class="btn btn-primary w-100 py-2 fw-medium">Verify</button>
    </form>
    <p class="text-center small mt-3 mb-0"><a href="login.php" class="text-muted text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Back to login</a></p>
</div>
</div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
