<?php
/**
 * Security helpers: login rate-limiting + audit logging + 2FA/session/
 * password-policy support.
 */

require_once __DIR__ . '/../config/security_policy.php';
require_once __DIR__ . '/totp.php';

function client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Returns true if this email has had too many failed login attempts recently.
 * Locks out after 5 failed attempts within 15 minutes (per email address).
 */
function is_login_locked_out($pdo, $email) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM login_attempts
        WHERE email = ? AND success = 0 AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)
    ");
    $stmt->execute([$email]);
    return $stmt->fetchColumn() >= 5;
}

/**
 * Find the account for whatever was typed in the sign-in box: an email
 * address OR a staff ID. Returns false if there's no match.
 */
function find_user_by_identifier($pdo, $identifier) {
    $identifier = trim($identifier);
    if ($identifier === '') {
        return false;
    }
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR staff_id = ? LIMIT 1");
    $stmt->execute([$identifier, $identifier]);
    return $stmt->fetch();
}

/**
 * Which key failed sign-in attempts are counted against.
 *
 * Because people can sign in with EITHER their email or their staff ID,
 * attempts must be counted against the account itself. Counting against the
 * typed text would let someone alternate between the two identifiers and
 * get 5 attempts on each, never tripping the lockout.
 */
function login_lockout_key($user, $identifier) {
    return $user ? $user['email'] : trim($identifier);
}

function record_login_attempt($pdo, $email, $success) {
    $stmt = $pdo->prepare("INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, ?)");
    $stmt->execute([$email, client_ip(), $success ? 1 : 0]);
}

/** Clear failed-attempt history for an email after a successful login. */
function clear_login_attempts($pdo, $email) {
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE email = ?");
    $stmt->execute([$email]);
}

/**
 * Write an entry to the audit log.
 * ---------------------------------------------------------
 * Designed for a real audit: as well as who/what/when, it records the
 * actor's name and role AT THE TIME (denormalised, so the trail stays
 * readable even after the account is deleted), which record was touched,
 * and the before/after state of the change.
 *
 * @param mixed  $old  state before the change (array/string/null)
 * @param mixed  $new  state after the change (array/string/null)
 */
function audit_log($pdo, $user_id, $action, $details = '', $entity_type = null, $entity_id = null, $old = null, $new = null) {
    // Resolve the actor's name/role so the record is self-contained.
    $actor_name = null;
    $actor_role = null;
    if (!empty($_SESSION['name']) && (int) ($_SESSION['user_id'] ?? 0) === (int) $user_id) {
        $actor_name = $_SESSION['name'];
        $actor_role = $_SESSION['role'] ?? null;
    } elseif ($user_id) {
        try {
            $s = $pdo->prepare("SELECT name, role FROM users WHERE user_id = ?");
            $s->execute([$user_id]);
            if ($row = $s->fetch()) {
                $actor_name = $row['name'];
                $actor_role = $row['role'];
            }
        } catch (PDOException $e) {
            // never let auditing break the action being audited
        }
    }

    $encode = function ($v) {
        if ($v === null) return null;
        if (is_scalar($v)) return (string) $v;
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    };

    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_log
                (user_id, actor_name, actor_role, action, entity_type, entity_id, old_value, new_value, details, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id, $actor_name, $actor_role, $action, $entity_type, $entity_id,
            $encode($old), $encode($new), $details, client_ip(), $ua,
        ]);
    } catch (PDOException $e) {
        // Auditing must never take the application down.
        error_log('audit_log failed: ' . $e->getMessage());
    }
}

/**
 * Compare two associative arrays and return only the fields that changed,
 * as ['field' => ['from' => x, 'to' => y]] - keeps the audit trail precise
 * instead of dumping whole records.
 */
function audit_diff($before, $after, $fields) {
    $changes = [];
    foreach ($fields as $f) {
        $b = $before[$f] ?? null;
        $a = $after[$f] ?? null;
        if ((string) $b !== (string) $a) {
            $changes[$f] = ['from' => $b, 'to' => $a];
        }
    }
    return $changes;
}

/**
 * Forgot Password: create a one-time reset token for a user.
 * Returns the RAW token (to put in the emailed link) - only the SHA-256
 * hash of it is stored in the database, so a database leak alone can't be
 * used to reset anyone's password.
 */
function create_password_reset_token($pdo, $user_id) {
    $raw_token = bin2hex(random_bytes(32));
    $token_hash = hash('sha256', $raw_token);
    $expires_at = date('Y-m-d H:i:s', time() + 1800); // valid for 30 minutes

    $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$user_id, $token_hash, $expires_at]);

    return $raw_token;
}

/**
 * Look up a still-valid (unused, unexpired) reset token.
 * Returns the password_resets row (with user_id) or false if invalid/expired.
 */
function find_valid_reset_token($pdo, $raw_token) {
    $token_hash = hash('sha256', $raw_token);
    $stmt = $pdo->prepare("
        SELECT * FROM password_resets
        WHERE token_hash = ? AND used = 0 AND expires_at > NOW()
    ");
    $stmt->execute([$token_hash]);
    return $stmt->fetch();
}

/** Mark a reset token (and any other outstanding ones for the same user) as used. */
function consume_password_reset_tokens($pdo, $user_id) {
    $stmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0");
    $stmt->execute([$user_id]);
}

/** Basic cooldown: has this user already requested a reset in the last 60 seconds? */
function recently_requested_reset($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM password_resets
        WHERE user_id = ? AND created_at >= (NOW() - INTERVAL 60 SECOND)
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Session Auto-Logout
 * ---------------------------------------------------------
 * Called at the top of every protected page (via require_login()). If the
 * session has been idle longer than SESSION_TIMEOUT_SECONDS, the session is
 * destroyed and the person is bounced back to login with an explanation.
 */
function enforce_session_timeout() {
    if (!isset($_SESSION['user_id'])) {
        return;
    }
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT_SECONDS) {
        session_unset();
        session_destroy();
        session_start();
        flash('error', 'You have been logged out due to inactivity. Please log in again.');
        header('Location: ' . base_url('login.php'));
        exit;
    }
    $_SESSION['last_activity'] = time();
}

/**
 * Password Expiry Policy
 * ---------------------------------------------------------
 * Only applies to Admin accounts, and only when PASSWORD_EXPIRY_ENABLED
 * is true in config/security_policy.php.
 */
/**
 * Does this user have to set a new password before they can continue?
 *
 * Two reasons, checked in order:
 *   1. An administrator chose their password (new account, or an admin
 *      reset) - the admin knows it, so the user must replace it. Applies
 *      to EVERY role.
 *   2. The optional expiry policy (admins only, off by default).
 */
function password_change_reason($user) {
    if (!empty($user['must_change_password'])) {
        return 'admin_set';
    }
    if (PASSWORD_EXPIRY_ENABLED && ($user['role'] ?? null) === 'admin') {
        if (empty($user['password_changed_at'])) {
            return 'expired';
        }
        $age_days = (time() - strtotime($user['password_changed_at'])) / 86400;
        if ($age_days > PASSWORD_EXPIRY_DAYS) {
            return 'expired';
        }
    }
    return null;
}

function needs_password_change($user) {
    return password_change_reason($user) !== null;
}

/**
 * Finish logging a user in once credentials (and 2FA, if enabled) have been
 * verified. Handles the password-expiry gate, then grants the full session.
 * Shared by login.php, two_factor_verify.php, and force_password_change.php
 * so there's exactly one place that actually "completes" a login.
 */
function complete_login($pdo, $user) {
    if (needs_password_change($user)) {
        $_SESSION['force_pw_change_user_id'] = $user['user_id'];
        header('Location: ' . base_url('force_password_change.php'));
        exit;
    }
    grant_full_session($pdo, $user);
}

/** Actually establish the logged-in session. Only call once all gates (2FA, password expiry) have passed. */
function grant_full_session($pdo, $user) {
    session_regenerate_id(true); // prevent session fixation

    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['last_activity'] = time();

    unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_attempts'], $_SESSION['force_pw_change_user_id']);

    audit_log($pdo, $user['user_id'], 'login_success', "Logged in as {$user['role']}");

    header('Location: ' . base_url('index.php'));
    exit;
}
