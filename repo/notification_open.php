<?php
/**
 * Clicking a notification lands here: mark that one notification read,
 * then send the person on to the ticket it's about.
 *
 * This is a GET because it's reached by a plain link. That's acceptable
 * here because the only effect is flipping the viewer's OWN notification
 * to read - the query refuses to touch anyone else's, and it's harmless
 * if repeated.
 */
require_once __DIR__ . '/includes/functions.php';
require_login();
$user = current_user();

$id = (int) ($_GET['id'] ?? 0);
$notification = get_own_notification($pdo, $user['user_id'], $id);

if (!$notification) {
    // Not found, or it belongs to someone else - treat both the same so
    // the response doesn't reveal which ids exist.
    header('Location: ' . base_url('notifications.php'));
    exit;
}

mark_notification_read($pdo, $user['user_id'], $id);

$target = $notification['ticket_id']
    ? base_url('ticket.php?id=' . (int) $notification['ticket_id'])
    : base_url('notifications.php');

header('Location: ' . $target);
exit;
