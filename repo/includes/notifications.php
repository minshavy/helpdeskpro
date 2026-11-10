<?php
/**
 * In-App Notifications
 * ---------------------------------------------------------
 * These work regardless of whether email (PHPMailer/MAIL_ENABLED) is
 * configured - the bell icon always shows real-time activity.
 */

function create_notification($pdo, $user_id, $ticket_id, $message) {
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, ticket_id, message) VALUES (?, ?, ?)");
    $stmt->execute([$user_id, $ticket_id, $message]);
}

function get_unread_notification_count($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    return (int) $stmt->fetchColumn();
}

function get_recent_notifications($pdo, $user_id, $limit = 8) {
    $limit = (int) $limit;
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT $limit");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function mark_all_notifications_read($pdo, $user_id) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    return $stmt->rowCount();
}

/**
 * Mark ONE notification read. The user_id condition is what stops someone
 * marking another person's notifications by guessing ids - it's an
 * ownership check built into the query itself.
 */
function mark_notification_read($pdo, $user_id, $notification_id) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([(int) $notification_id, $user_id]);
    return $stmt->rowCount() > 0;
}

/** Fetch one notification, only if it belongs to this user. */
function get_own_notification($pdo, $user_id, $notification_id) {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->execute([(int) $notification_id, $user_id]);
    return $stmt->fetch();
}

/** Human-friendly "X minutes/hours/days ago" for notification timestamps. */
function time_ago($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', strtotime($datetime));
}
