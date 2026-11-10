<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$user = current_user();

// ---------------------------------------------------------
// Actions
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $return = ($_POST['return'] ?? '') === 'unread' ? '?filter=unread' : '';

    if ($action === 'mark_one') {
        mark_notification_read($pdo, $user['user_id'], (int) ($_POST['id'] ?? 0));
    } elseif ($action === 'mark_all') {
        $n = mark_all_notifications_read($pdo, $user['user_id']);
        flash('success', $n === 1 ? '1 notification marked as read.' : "$n notifications marked as read.");
    }
    header('Location: notifications.php' . $return);
    exit;
}

// ---------------------------------------------------------
// Listing - viewing the page no longer marks everything read, so you
// stay in control of what's been dealt with.
// ---------------------------------------------------------
$filter = ($_GET['filter'] ?? '') === 'unread' ? 'unread' : 'all';

$per_page = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = "WHERE user_id = ?";
if ($filter === 'unread') {
    $where .= " AND is_read = 0";
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications $where");
$stmt->execute([$user['user_id']]);
$total = (int) $stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare("SELECT * FROM notifications $where ORDER BY created_at DESC, id DESC LIMIT $per_page OFFSET $offset");
$stmt->execute([$user['user_id']]);
$notifications = $stmt->fetchAll();

$unread_total = get_unread_notification_count($pdo, $user['user_id']);

$page_title = 'Notifications';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h1 class="h3 fw-bold mb-1">Notifications</h1>
        <p class="text-muted mb-0">
            <?php if ($unread_total > 0): ?>
                You have <strong><?= $unread_total ?></strong> unread notification<?= $unread_total === 1 ? '' : 's' ?>.
            <?php else: ?>
                You're all caught up.
            <?php endif; ?>
        </p>
    </div>
    <?php if ($unread_total > 0): ?>
        <form method="POST">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="mark_all">
            <input type="hidden" name="return" value="<?= $filter ?>">
            <button type="submit" class="btn btn-outline-primary">
                <i class="bi bi-check2-all me-1"></i>Mark all as read
            </button>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header bg-white py-3 border-0">
        <ul class="nav nav-pills gap-1">
            <li class="nav-item">
                <a class="nav-link <?= $filter === 'all' ? 'active' : '' ?>" href="notifications.php">All</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $filter === 'unread' ? 'active' : '' ?>" href="notifications.php?filter=unread">
                    Unread <?php if ($unread_total > 0): ?><span class="badge bg-danger ms-1"><?= $unread_total ?></span><?php endif; ?>
                </a>
            </li>
        </ul>
    </div>

    <div class="list-group list-group-flush">
        <?php foreach ($notifications as $n): ?>
            <div class="list-group-item py-3 d-flex align-items-start gap-3 <?= !$n['is_read'] ? 'notif-row-unread' : '' ?>">
                <span class="notif-dot <?= $n['is_read'] ? 'is-read' : '' ?>" aria-hidden="true"></span>

                <a href="notification_open.php?id=<?= (int) $n['id'] ?>" class="flex-grow-1 min-w-0 text-decoration-none text-body">
                    <div class="<?= !$n['is_read'] ? 'fw-semibold' : '' ?>"><?= e($n['message']) ?></div>
                    <small class="text-muted"><?= e(time_ago($n['created_at'])) ?><?= $n['ticket_id'] ? ' &middot; Ticket #' . (int) $n['ticket_id'] : '' ?></small>
                </a>

                <?php if (!$n['is_read']): ?>
                    <form method="POST" class="flex-shrink-0">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="mark_one">
                        <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                        <input type="hidden" name="return" value="<?= $filter ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Mark as read">
                            <i class="bi bi-check2"></i><span class="d-none d-sm-inline ms-1">Mark as read</span>
                        </button>
                    </form>
                <?php else: ?>
                    <span class="text-muted fs-8 flex-shrink-0 pt-1"><i class="bi bi-check2"></i> Read</span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if (empty($notifications)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-bell-slash d-block mb-2" style="font-size:1.5rem;"></i>
                <?= $filter === 'unread' ? 'No unread notifications.' : 'No notifications yet.' ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="card-footer bg-white py-3">
        <?php render_pagination($page, $total_pages, $filter === 'unread' ? ['filter' => 'unread'] : []); ?>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
