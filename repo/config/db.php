<?php
// ---------------------------------------------------------------------------
// Database configuration
// ---------------------------------------------------------------------------
// These are the defaults for a standard XAMPP / WAMP / MAMP install, so the
// project runs as soon as you create the database and import database.sql.
//
// If you deploy to a real host, change these values ON THE SERVER ONLY.
// Never commit live credentials to Git.
// ---------------------------------------------------------------------------

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');            // XAMPP ships with an empty root password
define('DB_NAME', 'helpdeskpro');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
