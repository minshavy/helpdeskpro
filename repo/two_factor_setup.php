<?php
require_once __DIR__ . '/includes/functions.php';
require_role(['admin', 'technician']);
$user = current_user();

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user['user_id']]);
$account = $stmt->fetch();

$error = '';
$success = '';

// If 2FA isn't enabled yet, generate (or reuse) a pending secret to show the QR code for.
// The secret isn't actually turned on until they verify a code from their app.
if (!$account['two_factor_enabled']) {
    if (empty($_SESSION['pending_2fa_secret'])) {
        $_SESSION['pending_2fa_secret'] = TOTP::generateSecret();
    }
    $pending_secret = $_SESSION['pending_2fa_secret'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'enable') {
        $code = trim($_POST['code'] ?? '');
        $secret = $_SESSION['pending_2fa_secret'] ?? '';

        if ($secret === '' || !TOTP::verifyCode($secret, $code)) {
            $error = 'Incorrect code. Please check your authenticator app and try again.';
        } else {
            $stmt = $pdo->prepare("UPDATE users SET two_factor_secret = ?, two_factor_enabled = 1 WHERE user_id = ?");
            $stmt->execute([$secret, $user['user_id']]);
            unset($_SESSION['pending_2fa_secret']);
            audit_log($pdo, $user['user_id'], '2fa_enabled', 'Two-factor authentication enabled');
            flash('success', 'Two-factor authentication is now enabled on your account.');
            header('Location: two_factor_setup.php');
            exit;
        }
    }

    if ($action === 'disable') {
        $password = $_POST['password'] ?? '';
        if (!password_verify($password, $account['password'])) {
            $error = 'Incorrect password.';
        } else {
            $stmt = $pdo->prepare("UPDATE users SET two_factor_secret = NULL, two_factor_enabled = 0 WHERE user_id = ?");
            $stmt->execute([$user['user_id']]);
            audit_log($pdo, $user['user_id'], '2fa_disabled', 'Two-factor authentication disabled');
            flash('success', 'Two-factor authentication has been disabled.');
            header('Location: two_factor_setup.php');
            exit;
        }
    }
}

$page_title = 'Two-Factor Authentication';
include __DIR__ . '/includes/header.php';
?>

<div class="mb-4">
    <h1 class="h3 fw-bold mb-1">Two-factor authentication</h1>
    <p class="text-muted mb-0">Add an extra layer of security to your account using an authenticator app.</p>
</div>

<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle me-1"></i><?= e($error) ?></div><?php endif; ?>

<div class="card p-4" style="max-width: 560px;">
<?php if ($account['two_factor_enabled']): ?>
    <div class="d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-shield-check text-success" style="font-size: 1.5rem;"></i>
        <div>
            <h5 class="fw-bold mb-0">2FA is enabled</h5>
            <small class="text-muted">Your account is protected with an authenticator app code at login.</small>
        </div>
    </div>
    <hr>
    <h6 class="fw-bold">Disable two-factor authentication</h6>
    <p class="text-muted small">Enter your password to confirm.</p>
    <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="disable">
        <div class="mb-3">
            <input type="password" name="password" class="form-control" placeholder="Your current password" required>
        </div>
        <button type="submit" class="btn btn-outline-danger">Disable 2FA</button>
    </form>
<?php else: ?>
    <h5 class="fw-bold mb-3"><i class="bi bi-shield-plus text-primary me-2"></i>Set up two-factor authentication</h5>
    <ol class="small text-muted mb-4" style="padding-left: 1.2rem;">
        <li>Install an authenticator app (Google Authenticator, Authy, Microsoft Authenticator, etc.) on your phone.</li>
        <li>Scan the QR code below, or enter the setup key manually.</li>
        <li>Enter the 6-digit code your app shows to confirm setup.</li>
    </ol>

    <div class="text-center mb-3">
        <?php
        $otpauth_uri = TOTP::getProvisioningUri($pending_secret, $account['email'], 'HelpDeskPro');
        $qr_img_url = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($otpauth_uri);
        ?>
        <img src="<?= e($qr_img_url) ?>" alt="2FA QR code" width="200" height="200" class="border rounded p-2">
    </div>
    <div class="mb-4 text-center">
        <small class="text-muted d-block mb-1">Can't scan? Enter this key manually:</small>
        <code class="fs-6"><?= e(chunk_split($pending_secret, 4, ' ')) ?></code>
    </div>

    <form method="POST">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="enable">
        <div class="mb-3">
            <label class="form-label fw-medium">Enter the 6-digit code from your app</label>
            <input type="text" name="code" class="form-control form-control-lg text-center" style="letter-spacing: 0.3em;"
                   inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="000000" required autocomplete="one-time-code">
        </div>
        <button type="submit" class="btn btn-primary w-100">Enable 2FA</button>
    </form>
<?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
