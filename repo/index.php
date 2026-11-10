<?php
require_once __DIR__ . '/includes/functions.php';

if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

switch ($_SESSION['role']) {
    case 'admin':
        header('Location: admin/dashboard.php');
        break;
    case 'technician':
        header('Location: technician/dashboard.php');
        break;
    default:
        header('Location: employee/dashboard.php');
}
exit;
