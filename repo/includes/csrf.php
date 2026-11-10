<?php
/**
 * CSRF Protection
 * ---------------------------------------------------------
 * Every form that changes data (login, submit ticket, comment, assign,
 * delete user, etc.) includes a hidden token generated per-session.
 * On POST, we verify the submitted token matches before doing anything.
 */

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Echo a hidden <input> field carrying the current CSRF token. Put inside every <form>. */
function csrf_field() {
    echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Call at the top of every POST handler. Halts the request with a 403 if the
 * token is missing or doesn't match - this blocks cross-site request forgery
 * (a malicious page tricking a logged-in user's browser into submitting
 * this form without them knowing).
 */
function csrf_verify() {
    $submitted = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        // A failed CSRF check may indicate an attack - record it.
        global $pdo;
        if (isset($pdo) && function_exists('audit_log')) {
            audit_log($pdo, $_SESSION['user_id'] ?? null, 'csrf_failure',
                'Rejected request to ' . basename($_SERVER['SCRIPT_NAME']) . ' - invalid CSRF token', 'security');
        }
        http_response_code(403);
        die('Security check failed (invalid or expired form token). Please go back, refresh the page, and try again.');
    }
}
