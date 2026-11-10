<?php
$nav_user = current_user();
$current_page = basename($_SERVER['SCRIPT_NAME']);

function nav_active($page, $current) {
    return $page === $current ? ' active' : '';
}

$initials = '';
if (!empty($nav_user['name'])) {
    $parts = preg_split('/\s+/', trim($nav_user['name']));
    $initials = strtoupper(substr($parts[0], 0, 1) . (count($parts) > 1 ? substr(end($parts), 0, 1) : ''));
}

$unread_count = 0;
$recent_notifications = [];
if (is_logged_in()) {
    $unread_count = get_unread_notification_count($pdo, $nav_user['user_id']);
    $recent_notifications = get_recent_notifications($pdo, $nav_user['user_id'], 8);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? e($page_title) . ' - ' : '' ?>HelpDeskPro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <?php
    // Cache-bust the stylesheet using its last-modified time, so browsers
    // always pick up CSS changes instead of serving a stale cached copy.
    $css_path = __DIR__ . '/../assets/css/style.css';
    $css_version = file_exists($css_path) ? filemtime($css_path) : time();
    ?>
    <link href="<?= base_url('assets/css/style.css') ?>?v=<?= $css_version ?>" rel="stylesheet">
    <script>
        // Apply saved theme before paint to avoid a flash of the wrong theme
        (function () {
            var theme = localStorage.getItem('hdp-theme') || 'light';
            document.documentElement.setAttribute('data-bs-theme', theme);
        })();
    </script>
</head>
<body>
<?php if (is_logged_in()):
    $dash_path = ($nav_user['role'] === 'admin' ? 'admin' : ($nav_user['role'] === 'technician' ? 'technician' : 'employee')) . '/dashboard.php';
?>
<div class="app-shell">
  <div class="sidebar-overlay" id="sidebarOverlay"></div>
  <aside class="app-sidebar" id="appSidebar">
    <a href="<?= base_url($dash_path) ?>" class="sidebar-brand text-decoration-none">
        <i class="bi bi-headset brand-icon me-2"></i>HelpDesk<span class="text-dark">Pro</span>
    </a>
    <nav class="sidebar-nav">
        <?php if ($nav_user['role'] === 'employee'): ?>
          <a class="nav-link<?= nav_active('dashboard.php', $current_page) ?>" href="<?= base_url('employee/dashboard.php') ?>"><i class="bi bi-speedometer2"></i> Dashboard</a>
          <a class="nav-link<?= nav_active('submit_ticket.php', $current_page) ?>" href="<?= base_url('employee/submit_ticket.php') ?>"><i class="bi bi-plus-circle"></i> Submit Ticket</a>
          <a class="nav-link<?= nav_active('my_tickets.php', $current_page) ?>" href="<?= base_url('employee/my_tickets.php') ?>"><i class="bi bi-ticket-detailed"></i> My Tickets</a>
          <a class="nav-link<?= nav_active('knowledge_base.php', $current_page) ?>" href="<?= base_url('knowledge_base.php') ?>"><i class="bi bi-book"></i> Knowledge Base</a>
        <?php elseif ($nav_user['role'] === 'technician'): ?>
          <a class="nav-link<?= nav_active('dashboard.php', $current_page) ?>" href="<?= base_url('technician/dashboard.php') ?>"><i class="bi bi-speedometer2"></i> Dashboard</a>
          <a class="nav-link<?= nav_active('tickets.php', $current_page) ?>" href="<?= base_url('technician/tickets.php') ?>"><i class="bi bi-ticket-detailed"></i> Assigned Tickets</a>
          <a class="nav-link<?= nav_active('canned_responses.php', $current_page) ?>" href="<?= base_url('technician/canned_responses.php') ?>"><i class="bi bi-chat-square-text"></i> Canned Responses</a>
          <a class="nav-link<?= nav_active('knowledge_base.php', $current_page) ?>" href="<?= base_url('knowledge_base.php') ?>"><i class="bi bi-book"></i> Knowledge Base</a>
        <?php elseif ($nav_user['role'] === 'admin'): ?>
          <a class="nav-link<?= nav_active('dashboard.php', $current_page) ?>" href="<?= base_url('admin/dashboard.php') ?>"><i class="bi bi-speedometer2"></i> Dashboard</a>
          <a class="nav-link<?= nav_active('analytics.php', $current_page) ?>" href="<?= base_url('admin/analytics.php') ?>"><i class="bi bi-bar-chart-line"></i> Analytics</a>
          <a class="nav-link<?= nav_active('all_tickets.php', $current_page) ?>" href="<?= base_url('admin/all_tickets.php') ?>"><i class="bi bi-ticket-perforated"></i> All Tickets</a>
          <a class="nav-link<?= nav_active('assign_ticket.php', $current_page) ?>" href="<?= base_url('admin/assign_ticket.php') ?>"><i class="bi bi-person-check"></i> Assign Tickets</a>
          <a class="nav-link<?= nav_active('manage_users.php', $current_page) ?>" href="<?= base_url('admin/manage_users.php') ?>"><i class="bi bi-people"></i> Manage Users</a>
          <a class="nav-link<?= nav_active('manage_departments.php', $current_page) ?>" href="<?= base_url('admin/manage_departments.php') ?>"><i class="bi bi-diagram-3"></i> Departments</a>
          <a class="nav-link<?= nav_active('backup.php', $current_page) ?>" href="<?= base_url('admin/backup.php') ?>"><i class="bi bi-database-down"></i> Backup</a>
          <div class="sidebar-section-label">Self-Service</div>
          <a class="nav-link<?= nav_active('canned_responses.php', $current_page) ?>" href="<?= base_url('technician/canned_responses.php') ?>"><i class="bi bi-chat-square-text"></i> Canned Responses</a>
          <a class="nav-link<?= nav_active('knowledge_base.php', $current_page) ?>" href="<?= base_url('knowledge_base.php') ?>"><i class="bi bi-book"></i> Knowledge Base</a>
        <?php endif; ?>
        <div class="sidebar-section-label">Account</div>
        <a class="nav-link<?= nav_active('help.php', $current_page) ?>" href="<?= base_url('help.php') ?>"><i class="bi bi-question-circle"></i> Help &amp; Guide</a>
        <?php if (in_array($nav_user['role'], ['admin', 'technician'])): ?>
          <a class="nav-link<?= nav_active('two_factor_setup.php', $current_page) ?>" href="<?= base_url('two_factor_setup.php') ?>"><i class="bi bi-shield-lock"></i> Security (2FA)</a>
        <?php endif; ?>
        <a class="nav-link" href="<?= base_url('logout.php') ?>"><i class="bi bi-box-arrow-right"></i> Log out</a>
    </nav>
  </aside>

  <div class="app-main">
    <div class="app-topbar">
      <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggle" aria-label="Toggle menu"><i class="bi bi-list"></i></button>
      <div class="flex-grow-1"></div>

      <button class="theme-toggle-btn btn btn-sm border-0" id="themeToggle" title="Toggle dark mode">
        <i class="bi bi-moon-stars" id="themeIcon"></i>
      </button>

      <div class="dropdown">
        <button class="notif-bell-btn" data-bs-toggle="dropdown" aria-label="Notifications">
          <i class="bi bi-bell"></i>
          <?php if ($unread_count > 0): ?>
            <span class="notif-badge"><?= $unread_count > 99 ? '99+' : $unread_count ?></span>
          <?php endif; ?>
        </button>
        <div class="dropdown-menu dropdown-menu-end notif-dropdown">
          <div class="notif-head">
            <span class="fw-semibold fs-7">Notifications</span>
            <?php if ($unread_count > 0): ?>
              <form method="POST" action="<?= base_url('notifications.php') ?>" class="m-0">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="mark_all">
                <button type="submit" class="btn btn-link btn-sm p-0 fs-8 text-decoration-none">Mark all as read</button>
              </form>
            <?php endif; ?>
          </div>
          <?php if (empty($recent_notifications)): ?>
            <div class="text-center text-muted py-4 fs-7">No notifications yet.</div>
          <?php else: ?>
            <?php foreach ($recent_notifications as $n): ?>
              <a href="<?= base_url('notification_open.php?id=' . (int) $n['id']) ?>"
                 class="notif-item text-decoration-none <?= !$n['is_read'] ? 'unread' : '' ?>">
                <?= e($n['message']) ?>
                <br><small><?= e(time_ago($n['created_at'])) ?></small>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
          <a href="<?= base_url('notifications.php') ?>" class="notif-item text-center text-primary fw-medium text-decoration-none">View all notifications</a>
        </div>
      </div>

      <div class="d-flex align-items-center gap-2 ms-1">
        <div class="avatar-circle"><?= e($initials ?: '?') ?></div>
        <div class="d-none d-sm-block">
          <div class="fw-semibold lh-1 fs-7"><?= e($nav_user['name']) ?></div>
          <small class="text-muted"><?= e(ucfirst($nav_user['role'])) ?></small>
        </div>
      </div>
    </div>

    <div class="container-fluid px-4 py-4">
<?php
$flash_success = flash('success');
$flash_error = flash('error');
if ($flash_success): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i><?= e($flash_success) ?></div>
<?php endif;
if ($flash_error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-circle me-1"></i><?= e($flash_error) ?></div>
<?php endif;
else: ?>
<div class="container">
<?php
$flash_success = flash('success');
$flash_error = flash('error');
if ($flash_success): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i><?= e($flash_success) ?></div>
<?php endif;
if ($flash_error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-circle me-1"></i><?= e($flash_error) ?></div>
<?php endif;
endif; ?>
