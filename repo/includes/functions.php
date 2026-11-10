<?php
// ---------------------------------------------------------
// Secure session configuration (must run BEFORE session_start())
// ---------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $is_https,   // only send the cookie over HTTPS once your site has it
        'httponly' => true,      // JavaScript can't read the session cookie (blocks XSS session theft)
        'samesite' => 'Lax',     // blocks the cookie being sent along on cross-site requests
    ]);
    ini_set('session.use_strict_mode', 1);
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/business_hours.php';
require_once __DIR__ . '/staff_id.php';

// ---------------------------------------------------------
// Auth helpers
// ---------------------------------------------------------
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: ' . base_url('login.php'));
        exit;
    }
    enforce_session_timeout();
}

function require_role($roles) {
    require_login();
    if (!in_array($_SESSION['role'], (array) $roles)) {
        // Attempted access to a page above the user's privilege level is a
        // security event in its own right - auditors want these recorded.
        global $pdo;
        if (isset($pdo)) {
            audit_log($pdo, $_SESSION['user_id'] ?? null, 'access_denied',
                'Blocked access to ' . basename($_SERVER['SCRIPT_NAME']) . ' (requires: ' . implode('/', (array) $roles) . ')',
                'page', null, ['role' => $_SESSION['role'] ?? null], ['required' => (array) $roles]);
        }
        header('Location: ' . base_url('index.php'));
        exit;
    }
}

function current_user() {
    return [
        // Cast to int: depending on the PHP/PDO build, MySQL can return
        // integer columns as strings. Without this, strict comparisons like
        // ($user_id === $admin['user_id']) silently fail and guards such as
        // "you cannot delete your own account" stop working.
        'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        'name'    => $_SESSION['name'] ?? null,
        'role'    => $_SESSION['role'] ?? null,
    ];
}

// Figures out the relative path back to the project root from any subfolder
function base_url($path = '') {
    // Detect if we're inside a subfolder (employee/, technician/, admin/)
    $script = $_SERVER['SCRIPT_NAME'];
    $depth  = substr_count(trim(dirname($script), '/'), '/');
    $inSub  = preg_match('#/(employee|technician|admin)/#', $script);
    $prefix = $inSub ? '../' : '';
    return $prefix . $path;
}

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/** mb_strlen with a safe fallback, in case a host doesn't have the mbstring extension enabled. */
function str_len($str) {
    return function_exists('mb_strlen') ? mb_strlen($str) : strlen($str);
}

/**
 * Render an inline preview for a ticket attachment based on its extension:
 * images show a thumbnail, PDFs embed inline, plain text files show their
 * content directly, anything else falls back to a plain download link.
 */
function render_attachment_preview($filename, $upload_url) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $image_exts = ['jpg', 'jpeg', 'png', 'gif'];

    ob_start();
    if (in_array($ext, $image_exts)) {
        ?>
        <a href="<?= e($upload_url) ?>" target="_blank">
            <img src="<?= e($upload_url) ?>" alt="Attachment preview" class="img-fluid rounded border" style="max-height: 400px;">
        </a>
        <?php
    } elseif ($ext === 'pdf') {
        ?>
        <embed src="<?= e($upload_url) ?>" type="application/pdf" width="100%" height="500" class="rounded border">
        <div class="mt-2"><a href="<?= e($upload_url) ?>" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i>Open in new tab</a></div>
        <?php
    } elseif ($ext === 'txt') {
        $path = __DIR__ . '/../uploads/' . basename($filename);
        $contents = is_readable($path) ? file_get_contents($path, false, null, 0, 5000) : null;
        if ($contents !== null) {
            ?>
            <pre class="border rounded p-3 bg-light" style="max-height: 300px; overflow-y: auto; font-size: 0.85rem;"><?= e($contents) ?></pre>
            <a href="<?= e($upload_url) ?>" target="_blank"><i class="bi bi-download me-1"></i>Download</a>
            <?php
        } else {
            ?>
            <a href="<?= e($upload_url) ?>" target="_blank"><i class="bi bi-paperclip me-1"></i>View attachment</a>
            <?php
        }
    } else {
        $icon = in_array($ext, ['doc', 'docx']) ? 'bi-file-earmark-word' : 'bi-file-earmark';
        ?>
        <a href="<?= e($upload_url) ?>" target="_blank" class="d-inline-flex align-items-center gap-2 text-decoration-none border rounded p-2">
            <i class="bi <?= $icon ?> fs-3 text-primary"></i>
            <span><?= e($filename) ?><br><small class="text-muted">Click to open/download</small></span>
        </a>
        <?php
    }
    return ob_get_clean();
}

function flash($key, $message = null) {
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return;
    }
    if (!empty($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
    return null;
}

function status_badge($status) {
    $map = [
        'Open' => 'badge-open',
        'In Progress' => 'badge-progress',
        'Closed' => 'badge-closed',
    ];
    $class = $map[$status] ?? 'badge-open';
    return "<span class=\"badge $class\">" . e($status) . "</span>";
}

function priority_badge($priority) {
    $map = [
        'Low' => 'bg-info-subtle text-info-emphasis',
        'Medium' => 'bg-warning-subtle text-warning-emphasis',
        'High' => 'bg-danger-subtle text-danger',
    ];
    $class = $map[$priority] ?? 'bg-secondary-subtle text-secondary';
    return "<span class=\"badge $class\">" . e($priority) . "</span>";
}

function escalated_badge($escalated) {
    if (!$escalated) {
        return '';
    }
    return ' <span class="badge badge-escalated ms-1">Escalated</span>';
}

/**
 * Render a Bootstrap pagination control, preserving the current query
 * string filters (only swapping the "page" parameter).
 */
function render_pagination($current_page, $total_pages, $extra_params = []) {
    if ($total_pages <= 1) {
        return;
    }
    $build_url = function ($page) use ($extra_params) {
        $params = array_merge($extra_params, ['page' => $page]);
        return '?' . http_build_query($params);
    };

    echo '<nav aria-label="Page navigation"><ul class="pagination pagination-sm justify-content-center mb-0">';

    $prev_disabled = $current_page <= 1 ? ' disabled' : '';
    echo '<li class="page-item' . $prev_disabled . '"><a class="page-link" href="' . e($build_url(max(1, $current_page - 1))) . '">Previous</a></li>';

    $start = max(1, $current_page - 2);
    $end = min($total_pages, $current_page + 2);
    if ($start > 1) {
        echo '<li class="page-item"><a class="page-link" href="' . e($build_url(1)) . '">1</a></li>';
        if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
    }
    for ($p = $start; $p <= $end; $p++) {
        $active = $p === $current_page ? ' active' : '';
        echo '<li class="page-item' . $active . '"><a class="page-link" href="' . e($build_url($p)) . '">' . $p . '</a></li>';
    }
    if ($end < $total_pages) {
        if ($end < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        echo '<li class="page-item"><a class="page-link" href="' . e($build_url($total_pages)) . '">' . $total_pages . '</a></li>';
    }

    $next_disabled = $current_page >= $total_pages ? ' disabled' : '';
    echo '<li class="page-item' . $next_disabled . '"><a class="page-link" href="' . e($build_url(min($total_pages, $current_page + 1))) . '">Next</a></li>';

    echo '</ul></nav>';
}

// Log a ticket status change / comment into ticket_logs
function log_ticket_action($pdo, $ticket_id, $user_id, $status, $message) {
    $stmt = $pdo->prepare("INSERT INTO ticket_logs (ticket_id, user_id, status, message) VALUES (?, ?, ?, ?)");
    $stmt->execute([$ticket_id, $user_id, $status, $message]);
}
