<?php
require_once __DIR__ . '/includes/functions.php';
if (is_logged_in()) {
    audit_log($pdo, $_SESSION['user_id'], 'logout', 'User logged out');
}
session_destroy();
header('Location: login.php');
exit;
