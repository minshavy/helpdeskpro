<?php
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';

    // Work out WHO is being logged in before checking the lockout. Sign-in
    // accepts either an email address or a staff ID, so failed attempts must
    // be counted against the account itself - otherwise someone could alternate
    // between the two identifiers and never trip the 5-attempt lockout.
    $user = find_user_by_identifier($pdo, $identifier);
    $lockout_key = login_lockout_key($user, $identifier);

    if (is_login_locked_out($pdo, $lockout_key)) {
        $error = 'Too many failed login attempts. Please wait 15 minutes and try again.';
    } else {
        if ($user && password_verify($password, $user['password'])) {
            clear_login_attempts($pdo, $lockout_key);
            record_login_attempt($pdo, $lockout_key, true);

            if ($user['two_factor_enabled']) {
                $_SESSION['2fa_pending_user_id'] = $user['user_id'];
                $_SESSION['2fa_attempts'] = 0;
                header('Location: two_factor_verify.php');
                exit;
            }

            complete_login($pdo, $user);
        } else {
            record_login_attempt($pdo, $lockout_key, false);
            audit_log($pdo, $user['user_id'] ?? null, 'login_failed',
                "Failed login attempt for: $identifier", 'user', $user['user_id'] ?? null);
            $error = 'Invalid login or password.';
        }
    }
}

$page_title = 'Login';
include __DIR__ . '/includes/header.php';
?>

<div class="min-vh-100 d-flex align-items-center justify-content-center py-5">
  <div class="container" style="max-width: 1000px;">
    <div class="card border-0 shadow-lg overflow-hidden auth-hero-card">
      <div class="row g-0">
        <!-- Hero Section -->
        <div class="col-lg-6 auth-hero-panel text-white p-4 p-md-5 d-flex flex-column justify-content-between">
          <div>
            <div class="d-flex align-items-center mb-4">
              <i class="bi bi-headset fs-2 me-2"></i>
              <span class="fs-4 fw-bold">HelpDeskPro</span>
            </div>
            <h1 class="h3 fw-bold mb-3">Every issue tracked.<br>Every fix accountable.</h1>
            <p class="opacity-75 mb-4">TechSolutions Maldives IT Helpdesk</p>
            <ul class="list-unstyled d-flex flex-column gap-3 mb-0 opacity-90">
              <li class="d-flex align-items-center"><i class="bi bi-check-circle-fill me-2"></i> Centralized ticket tracking for your whole team</li>
              <li class="d-flex align-items-center"><i class="bi bi-check-circle-fill me-2"></i> Live status &amp; priority visibility</li>
              <li class="d-flex align-items-center"><i class="bi bi-check-circle-fill me-2"></i> Built-in SLA escalation and reporting</li>
            </ul>
          </div>
          <div class="mt-5 pt-3 border-top border-white-50 text-white-50 fs-7">
            &copy; <?= date('Y') ?> HelpDeskPro IT Helpdesk &amp; Ticket Management System
          </div>
        </div>

        <!-- Login Form Section -->
        <div class="col-lg-6 p-4 p-md-5 bg-white">
          <h2 class="h4 fw-bold text-dark mb-1">Welcome back</h2>
          <p class="text-muted small mb-4">Log in to manage and track your support tickets.</p>

          <?php if ($error): ?>
              <div class="alert alert-danger"><i class="bi bi-exclamation-circle me-1"></i><?= e($error) ?></div>
          <?php endif; ?>

          <form method="POST">
            <?php csrf_field(); ?>
            <div class="mb-3">
              <label for="identifier" class="form-label fw-medium">Email or Staff ID</label>
              <div class="input-group">
                <span class="input-group-text bg-light border-end-0"><i class="bi bi-person text-muted"></i></span>
                <input type="text" name="identifier" id="identifier" class="form-control border-start-0"
                       placeholder="you@company.com or EMP-0001" required autofocus maxlength="150"
                       autocomplete="username" value="<?= e($identifier ?? '') ?>">
              </div>
            </div>

            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label fw-medium mb-0">Password</label>
                <a href="forgot_password.php" class="text-primary text-decoration-none small">Forgot password?</a>
              </div>
              <div class="input-group">
                <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-muted"></i></span>
                <input type="password" name="password" class="form-control border-start-0" placeholder="••••••••" required>
              </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2 fw-medium mb-3">Log in</button>
          </form>
          <p class="text-center small text-muted mb-4">No account? Ask your administrator to create one for you.
            <br><a href="help.php" class="text-primary text-decoration-none fw-medium">How it works</a></p>

          <!-- Quick Demo Accounts Widget -->
          <div class="p-3 bg-light rounded-3 border">
            <div class="fw-semibold fs-7 text-dark mb-1"><i class="bi bi-info-circle me-1 text-primary"></i> Demo accounts (password: <code>password123</code>)</div>
            <div class="d-flex flex-wrap gap-2 mt-2">
              <span class="badge bg-white text-dark border">admin@helpdeskpro.com</span>
              <span class="badge bg-white text-dark border">tech1@helpdeskpro.com</span>
              <span class="badge bg-white text-dark border">employee1@helpdeskpro.com</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
