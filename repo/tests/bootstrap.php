<?php
/**
 * Test bootstrap
 * ---------------------------------------------------------
 * Builds a SEPARATE, disposable test database from database.sql so tests
 * never touch your real data. Edit the credentials below if your local
 * MySQL differs.
 *
 * IMPORTANT: point this at a LOCAL database (XAMPP), not your live host.
 */

// ---- Test database connection settings ----
define('TEST_DB_HOST', getenv('HDP_TEST_HOST') ?: 'localhost');
define('TEST_DB_USER', getenv('HDP_TEST_USER') ?: 'root');
define('TEST_DB_PASS', getenv('HDP_TEST_PASS') !== false ? getenv('HDP_TEST_PASS') : '');
define('TEST_DB_NAME', getenv('HDP_TEST_NAME') ?: 'helpdeskpro_test');

$APP_ROOT = dirname(__DIR__);

if (session_status() === PHP_SESSION_NONE) { @session_start(); }
if (!isset($_SESSION)) { $_SESSION = []; }

// Safety guard: refuse to run against anything that looks like production.
if (stripos(TEST_DB_HOST, 'infinityfree') !== false || stripos(TEST_DB_NAME, '_test') === false) {
    fwrite(STDERR, "REFUSING TO RUN: tests must target a local database whose name contains '_test'.\n");
    fwrite(STDERR, "Current target: " . TEST_DB_NAME . " @ " . TEST_DB_HOST . "\n");
    exit(1);
}

/** Drop and rebuild the test schema from database.sql. Called before each test group that needs a clean slate. */
function reset_test_database() {
    global $APP_ROOT;

    $root = new PDO(
        "mysql:host=" . TEST_DB_HOST . ";charset=utf8mb4",
        TEST_DB_USER, TEST_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $root->exec("DROP DATABASE IF EXISTS `" . TEST_DB_NAME . "`");
    $root->exec("CREATE DATABASE `" . TEST_DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $sql = file_get_contents($APP_ROOT . '/database.sql');
    // database.sql targets the 'helpdeskpro' database; strip those statements
    // so it applies cleanly to our test database instead.
    $sql = preg_replace('/CREATE DATABASE[^;]+;/i', '', $sql);
    $sql = preg_replace('/USE\s+[^;]+;/i', '', $sql);

    $pdo = new PDO(
        "mysql:host=" . TEST_DB_HOST . ";dbname=" . TEST_DB_NAME . ";charset=utf8mb4",
        TEST_DB_USER, TEST_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo->exec($sql);
    return $pdo;
}

/** Fresh PDO handle on the test database. */
function test_pdo() {
    return new PDO(
        "mysql:host=" . TEST_DB_HOST . ";dbname=" . TEST_DB_NAME . ";charset=utf8mb4",
        TEST_DB_USER, TEST_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

/** Look up a seeded user by email. */
function test_user($pdo, $email) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    return $stmt->fetch();
}

/** Insert a ticket directly, returning its id. */
function make_ticket($pdo, $opts = []) {
    $d = array_merge([
        'user_id' => 3, 'assigned_to' => null, 'department_id' => null, 'title' => 'Test ticket',
        'description' => 'desc', 'category' => 'Other', 'priority' => 'Medium',
        'status' => 'Open', 'escalated' => 0, 'created_at' => date('Y-m-d H:i:s'),
    ], $opts);

    $stmt = $pdo->prepare("
        INSERT INTO tickets (user_id, assigned_to, department_id, title, description, category, priority, status, escalated, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $d['user_id'], $d['assigned_to'], $d['department_id'], $d['title'], $d['description'], $d['category'],
        $d['priority'], $d['status'], $d['escalated'], $d['created_at'],
        $d['updated_at'] ?? $d['created_at'],
    ]);
    return (int) $pdo->lastInsertId();
}

// ---- Load the pieces of the app under test ----
// These files are pure logic with no session/output side effects.
require_once $APP_ROOT . '/includes/totp.php';
require_once $APP_ROOT . '/includes/business_hours.php';
// security.php is self-contained (it declares client_ip() itself and only
// pulls in config + totp), so we test the REAL audit_log()/audit_diff()
// rather than a copy that could drift out of sync.
require_once $APP_ROOT . '/includes/security.php';
require_once $APP_ROOT . '/includes/backup.php';
require_once $APP_ROOT . '/includes/notifications.php';
require_once $APP_ROOT . '/includes/staff_id.php';
