<?php
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$submitted = false;
$dev_reset_link = null; // only ever populated (and shown) when MAIL_ENABLED is false, for local testing

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim($_POST['email'] ?? '');

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Deliberately show the same message whether or not the account
        // exists, so this form can't be used to check which emails are registered.
        if ($user && !recently_requested_reset($pdo, $user['user_id'])) {
            $raw_token = create_password_reset_token($pdo, $user['user_id']);
            $reset_link = APP_URL . '/reset_password.php?token=' . $raw_token;

            audit_log($pdo, $user['user_id'], 'password_reset_requested', "Requested for email: $email");

            $sent = send_password_reset_email($user['email'], $user['name'], $reset_link);

            if (!$sent) {
                // Email isn't configured (MAIL_ENABLED=false) - show the link
                // directly so the flow is still testable locally/in development.
                $dev_reset_link = $reset_link;
            }
        }
    }

    $submitted = true;
}

$page_title = 'Forgot Password';
include __DIR__ . '/includes/header.php';
?>

<div class="min-vh-100 d-flex align-items-center justify-content-center py-5">
<div class="card border-0 shadow-sm mx-auto" style="max-width: 440px; width:100%;">
<div class="card-body p-4 p-md-5">
    <h2 class="h4 fw-bold text-dark mb-1 text-center">Forgot password</h2>
    <p class="text-muted small text-center mb-4">We'll help you get back into your account.</p>

    <?php if ($submitted): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>
            If an account exists with that email, a password reset link has been sent.
            The link expires in 30 minutes.
        </div>

        <?php if ($dev_reset_link): ?>
            <div class="alert alert-warning">
                <strong>Email is not configured on this installation</strong> (<code>MAIL_ENABLED</code>
                is <code>false</code> in <code>config/mail.php</code>), so here's your reset link directly
                for testing:<br>
                <a href="<?= e($dev_reset_link) ?>"><?= e($dev_reset_link) ?></a>
            </div>
        <?php endif; ?>

        <p class="text-center small mt-3 mb-0"><a href="login.php" class="text-primary text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Back to login</a></p>
    <?php else: ?>
        <p class="text-muted small mb-3">Enter the email address associated with your account, and we'll send you a link to reset your password.</p>
        <form method="POST">
            <?php csrf_field(); ?>
            <div class="mb-3">
                <label class="form-label fw-medium">Email</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope text-muted"></i></span>
                    <input type="email" name="email" class="form-control border-start-0" required maxlength="150" autofocus placeholder="you@company.com">
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100 py-2 fw-medium">Send reset link</button>
        </form>
        <p class="text-center small mt-3 mb-0"><a href="login.php" class="text-primary text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Back to login</a></p>
    <?php endif; ?>
</div>
</div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
