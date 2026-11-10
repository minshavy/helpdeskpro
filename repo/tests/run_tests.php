<?php
/**
 * HelpDeskPro - Automated Test Suite
 * ---------------------------------------------------------
 * Run with:  php tests/run_tests.php
 *
 * Covers the logic that matters most and is easiest to break silently:
 * authentication, rate limiting, 2FA, password resets, role permissions,
 * the ticket lifecycle, SLA escalation, notifications, and the knowledge base.
 */

require_once __DIR__ . '/TestRunner.php';
require_once __DIR__ . '/bootstrap.php';

$t = new TestRunner();
$APP_ROOT = dirname(__DIR__);

// =========================================================
$t->group('Password hashing & verification');

$t->test('bcrypt hash verifies against the correct password', function ($t) {
    $hash = password_hash('password123', PASSWORD_BCRYPT);
    $t->assertTrue(password_verify('password123', $hash));
});

$t->test('bcrypt hash rejects an incorrect password', function ($t) {
    $hash = password_hash('password123', PASSWORD_BCRYPT);
    $t->assertFalse(password_verify('wrongpassword', $hash));
});

$t->test('seeded demo accounts accept the documented password', function ($t) {
    $pdo = reset_test_database();
    foreach (['admin@helpdeskpro.com', 'tech1@helpdeskpro.com', 'employee1@helpdeskpro.com'] as $email) {
        $u = test_user($pdo, $email);
        $t->assertNotEmpty($u, "Seed user $email should exist");
        $t->assertTrue(password_verify('password123', $u['password']), "$email should accept 'password123'");
    }
});

// =========================================================
$t->group('Login rate limiting');

$t->test('account is NOT locked out before 5 failed attempts', function ($t) {
    $pdo = reset_test_database();
    $stmt = $pdo->prepare("INSERT INTO login_attempts (email, ip_address, success) VALUES (?, '127.0.0.1', 0)");
    for ($i = 0; $i < 4; $i++) { $stmt->execute(['admin@helpdeskpro.com']); }

    $count = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)");
    $count->execute(['admin@helpdeskpro.com']);
    $t->assertTrue((int) $count->fetchColumn() < 5, 'Should still be under the lockout threshold');
});

$t->test('account IS locked out at 5 failed attempts', function ($t) {
    $pdo = reset_test_database();
    $stmt = $pdo->prepare("INSERT INTO login_attempts (email, ip_address, success) VALUES (?, '127.0.0.1', 0)");
    for ($i = 0; $i < 5; $i++) { $stmt->execute(['admin@helpdeskpro.com']); }

    $count = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)");
    $count->execute(['admin@helpdeskpro.com']);
    $t->assertTrue((int) $count->fetchColumn() >= 5, 'Should have hit the lockout threshold');
});

$t->test('old failed attempts outside the 15-minute window do not count', function ($t) {
    $pdo = reset_test_database();
    $stmt = $pdo->prepare("INSERT INTO login_attempts (email, ip_address, success, attempted_at) VALUES (?, '127.0.0.1', 0, NOW() - INTERVAL 20 MINUTE)");
    for ($i = 0; $i < 5; $i++) { $stmt->execute(['admin@helpdeskpro.com']); }

    $count = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)");
    $count->execute(['admin@helpdeskpro.com']);
    $t->assertEquals(0, (int) $count->fetchColumn(), 'Stale attempts should have aged out');
});

// =========================================================
$t->group('Two-factor authentication (TOTP)');

$t->test('generated secret is valid base32 of expected length', function ($t) {
    $secret = TOTP::generateSecret();
    $t->assertEquals(16, strlen($secret));
    $t->assertEquals(1, preg_match('/^[A-Z2-7]+$/', $secret), 'Secret should be base32');
});

$t->test('current code verifies successfully', function ($t) {
    $secret = TOTP::generateSecret();
    $code = TOTP::getCode($secret);
    $t->assertTrue(TOTP::verifyCode($secret, $code));
});

$t->test('code is always 6 digits', function ($t) {
    for ($i = 0; $i < 20; $i++) {
        $code = TOTP::getCode(TOTP::generateSecret());
        $t->assertEquals(1, preg_match('/^\d{6}$/', $code), "Got malformed code: $code");
    }
});

$t->test('wrong code is rejected', function ($t) {
    $secret = TOTP::generateSecret();
    $real = TOTP::getCode($secret);
    $wrong = str_pad((string) ((((int) $real) + 1) % 1000000), 6, '0', STR_PAD_LEFT);
    $t->assertFalse(TOTP::verifyCode($secret, $wrong));
});

$t->test('tolerates +/- 1 time step of clock drift', function ($t) {
    $secret = TOTP::generateSecret();
    $slice = (int) floor(time() / 30);
    $t->assertTrue(TOTP::verifyCode($secret, TOTP::getCode($secret, $slice - 1)), 'previous step should pass');
    $t->assertTrue(TOTP::verifyCode($secret, TOTP::getCode($secret, $slice + 1)), 'next step should pass');
});

$t->test('rejects codes far outside the drift window', function ($t) {
    $secret = TOTP::generateSecret();
    $slice = (int) floor(time() / 30);
    $t->assertFalse(TOTP::verifyCode($secret, TOTP::getCode($secret, $slice - 10)));
});

$t->test('rejects malformed input (letters, wrong length, empty)', function ($t) {
    $secret = TOTP::generateSecret();
    foreach (['', 'abcdef', '12345', '1234567', 'ABC123'] as $bad) {
        $t->assertFalse(TOTP::verifyCode($secret, $bad), "Should reject '$bad'");
    }
});

$t->test('different secrets produce different codes', function ($t) {
    $a = TOTP::getCode('AAAAAAAAAAAAAAAA');
    $b = TOTP::getCode('BBBBBBBBBBBBBBBB');
    $t->assertNotEquals($a, $b);
});

$t->test('provisioning URI is well-formed for authenticator apps', function ($t) {
    $uri = TOTP::getProvisioningUri('JBSWY3DPEHPK3PXP', 'user@example.com', 'HelpDeskPro');
    $t->assertContains('otpauth://totp/', $uri);
    $t->assertContains('secret=JBSWY3DPEHPK3PXP', $uri);
    $t->assertContains('issuer=HelpDeskPro', $uri);
});

// =========================================================
$t->group('Password reset tokens');

$t->test('token is stored hashed, never in plaintext', function ($t) {
    $pdo = reset_test_database();
    $raw = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (1, ?, NOW() + INTERVAL 30 MINUTE)")
        ->execute([$hash]);

    $stored = $pdo->query("SELECT token_hash FROM password_resets")->fetchColumn();
    $t->assertNotEquals($raw, $stored, 'Raw token must not be stored');
    $t->assertEquals($hash, $stored);
});

$t->test('valid unexpired token is found', function ($t) {
    $pdo = reset_test_database();
    $raw = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (1, ?, NOW() + INTERVAL 30 MINUTE)")
        ->execute([hash('sha256', $raw)]);

    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > NOW()");
    $stmt->execute([hash('sha256', $raw)]);
    $t->assertNotEmpty($stmt->fetch());
});

$t->test('expired token is rejected', function ($t) {
    $pdo = reset_test_database();
    $raw = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (1, ?, NOW() - INTERVAL 1 HOUR)")
        ->execute([hash('sha256', $raw)]);

    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > NOW()");
    $stmt->execute([hash('sha256', $raw)]);
    $t->assertEmpty($stmt->fetch(), 'Expired token must not be usable');
});

$t->test('used token cannot be reused', function ($t) {
    $pdo = reset_test_database();
    $raw = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, used) VALUES (1, ?, NOW() + INTERVAL 30 MINUTE, 1)")
        ->execute([hash('sha256', $raw)]);

    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > NOW()");
    $stmt->execute([hash('sha256', $raw)]);
    $t->assertEmpty($stmt->fetch(), 'Consumed token must not be reusable');
});

// =========================================================
$t->group('Ticket lifecycle');

$t->test('new ticket defaults to Open, unassigned, not escalated', function ($t) {
    $pdo = reset_test_database();
    // Insert with ONLY the required columns so the database's own DEFAULTs
    // are what get exercised here (passing status explicitly would mask a
    // wrong schema default).
    $pdo->prepare("INSERT INTO tickets (user_id, title, description) VALUES (3, 'Printer broken', 'desc')")->execute();
    $id = (int) $pdo->lastInsertId();
    $ticket = $pdo->query("SELECT * FROM tickets WHERE ticket_id = $id")->fetch();

    $t->assertEquals('Open', $ticket['status'], 'Schema default for status should be Open');
    $t->assertEquals('Medium', $ticket['priority'], 'Schema default for priority should be Medium');
    $t->assertEquals('Other', $ticket['category'], 'Schema default for category should be Other');
    $t->assertNull($ticket['assigned_to']);
    $t->assertEquals(0, (int) $ticket['escalated']);
});

$t->test('assigning a ticket sets technician and In Progress', function ($t) {
    $pdo = reset_test_database();
    $id = make_ticket($pdo);
    $tech = test_user($pdo, 'tech1@helpdeskpro.com');
    $pdo->prepare("UPDATE tickets SET assigned_to = ?, status = 'In Progress' WHERE ticket_id = ?")
        ->execute([$tech['user_id'], $id]);

    $ticket = $pdo->query("SELECT * FROM tickets WHERE ticket_id = $id")->fetch();
    $t->assertEquals($tech['user_id'], $ticket['assigned_to']);
    $t->assertEquals('In Progress', $ticket['status']);
});

$t->test('ticket_logs records the activity trail', function ($t) {
    $pdo = reset_test_database();
    $id = make_ticket($pdo);
    $stmt = $pdo->prepare("INSERT INTO ticket_logs (ticket_id, user_id, status, message) VALUES (?, ?, ?, ?)");
    $stmt->execute([$id, 3, 'Open', 'Ticket submitted.']);
    $stmt->execute([$id, 2, null, 'Looking into it']);
    $stmt->execute([$id, 2, 'Closed', 'Ticket closed.']);

    $logs = $pdo->query("SELECT * FROM ticket_logs WHERE ticket_id = $id ORDER BY log_id")->fetchAll();
    $t->assertCount(3, $logs);
    $t->assertEquals('Ticket submitted.', $logs[0]['message']);
    $t->assertEquals('Closed', $logs[2]['status']);
});

$t->test('deleting a ticket cascades to its logs', function ($t) {
    $pdo = reset_test_database();
    $id = make_ticket($pdo);
    $pdo->prepare("INSERT INTO ticket_logs (ticket_id, user_id, status, message) VALUES (?, 3, 'Open', 'x')")->execute([$id]);
    $pdo->exec("DELETE FROM tickets WHERE ticket_id = $id");

    $remaining = $pdo->query("SELECT COUNT(*) FROM ticket_logs WHERE ticket_id = $id")->fetchColumn();
    $t->assertEquals(0, (int) $remaining, 'Orphaned logs should be cleaned up by the FK cascade');
});

$t->test('only valid enum values are accepted for status/priority/category', function ($t) {
    $pdo = reset_test_database();
    $pdo->exec("SET sql_mode = 'STRICT_ALL_TABLES'");
    $rejected = false;
    try {
        make_ticket($pdo, ['priority' => 'Critical']); // not a valid enum member
    } catch (PDOException $e) {
        $rejected = true;
    }
    $t->assertTrue($rejected, 'Invalid enum value should be rejected in strict mode');
});

// =========================================================
$t->group('SLA escalation logic');

$t->test('ticket inside its SLA window is NOT flagged as breaching', function ($t) {
    $pdo = reset_test_database();
    // High priority = 24h threshold; this one is only 2h old
    make_ticket($pdo, ['priority' => 'High', 'created_at' => date('Y-m-d H:i:s', time() - 2 * 3600)]);

    $sla = "CASE priority WHEN 'High' THEN 24 WHEN 'Medium' THEN 72 ELSE 120 END";
    $count = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status != 'Closed' AND escalated = 0 AND TIMESTAMPDIFF(HOUR, created_at, NOW()) > ($sla)")->fetchColumn();
    $t->assertEquals(0, (int) $count);
});

$t->test('High priority ticket past 24h IS flagged as breaching', function ($t) {
    $pdo = reset_test_database();
    make_ticket($pdo, ['priority' => 'High', 'created_at' => date('Y-m-d H:i:s', time() - 30 * 3600)]);

    $sla = "CASE priority WHEN 'High' THEN 24 WHEN 'Medium' THEN 72 ELSE 120 END";
    $count = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status != 'Closed' AND escalated = 0 AND TIMESTAMPDIFF(HOUR, created_at, NOW()) > ($sla)")->fetchColumn();
    $t->assertEquals(1, (int) $count);
});

$t->test('thresholds differ correctly by priority', function ($t) {
    $pdo = reset_test_database();
    // 80h old: breaches High(24) and Medium(72), but NOT Low(120)
    $age = date('Y-m-d H:i:s', time() - 80 * 3600);
    make_ticket($pdo, ['priority' => 'High', 'created_at' => $age]);
    make_ticket($pdo, ['priority' => 'Medium', 'created_at' => $age]);
    make_ticket($pdo, ['priority' => 'Low', 'created_at' => $age]);

    $sla = "CASE priority WHEN 'High' THEN 24 WHEN 'Medium' THEN 72 ELSE 120 END";
    $count = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status != 'Closed' AND escalated = 0 AND TIMESTAMPDIFF(HOUR, created_at, NOW()) > ($sla)")->fetchColumn();
    $t->assertEquals(2, (int) $count, 'High and Medium should breach; Low should not');
});

$t->test('already-escalated tickets are not escalated twice', function ($t) {
    $pdo = reset_test_database();
    make_ticket($pdo, ['priority' => 'High', 'escalated' => 1, 'created_at' => date('Y-m-d H:i:s', time() - 50 * 3600)]);

    $sla = "CASE priority WHEN 'High' THEN 24 WHEN 'Medium' THEN 72 ELSE 120 END";
    $count = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status != 'Closed' AND escalated = 0 AND TIMESTAMPDIFF(HOUR, created_at, NOW()) > ($sla)")->fetchColumn();
    $t->assertEquals(0, (int) $count, 'escalated=1 should exclude it from re-escalation');
});

$t->test('closed tickets are never escalated', function ($t) {
    $pdo = reset_test_database();
    make_ticket($pdo, ['priority' => 'High', 'status' => 'Closed', 'created_at' => date('Y-m-d H:i:s', time() - 99 * 3600)]);

    $sla = "CASE priority WHEN 'High' THEN 24 WHEN 'Medium' THEN 72 ELSE 120 END";
    $count = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status != 'Closed' AND escalated = 0 AND TIMESTAMPDIFF(HOUR, created_at, NOW()) > ($sla)")->fetchColumn();
    $t->assertEquals(0, (int) $count);
});

// =========================================================
$t->group('SLA compliance reporting');

$t->test('compliance is 100% when no closed ticket was escalated', function ($t) {
    $pdo = reset_test_database();
    make_ticket($pdo, ['status' => 'Closed', 'escalated' => 0]);
    make_ticket($pdo, ['status' => 'Closed', 'escalated' => 0]);

    $row = $pdo->query("SELECT SUM(escalated = 0) AS compliant, COUNT(*) AS total FROM tickets WHERE status = 'Closed'")->fetch();
    $t->assertEquals(100.0, round($row['compliant'] / $row['total'] * 100, 1));
});

$t->test('compliance percentage is computed correctly with breaches', function ($t) {
    $pdo = reset_test_database();
    // 3 compliant, 1 escalated => 75%
    make_ticket($pdo, ['status' => 'Closed', 'escalated' => 0]);
    make_ticket($pdo, ['status' => 'Closed', 'escalated' => 0]);
    make_ticket($pdo, ['status' => 'Closed', 'escalated' => 0]);
    make_ticket($pdo, ['status' => 'Closed', 'escalated' => 1]);

    $row = $pdo->query("SELECT SUM(escalated = 0) AS compliant, COUNT(*) AS total FROM tickets WHERE status = 'Closed'")->fetch();
    $t->assertEquals(75.0, round($row['compliant'] / $row['total'] * 100, 1));
});

// =========================================================
$t->group('Role-based access control (data layer)');

$t->test('seeded roles are exactly admin/technician/employee', function ($t) {
    $pdo = reset_test_database();
    $t->assertEquals('admin', test_user($pdo, 'admin@helpdeskpro.com')['role']);
    $t->assertEquals('technician', test_user($pdo, 'tech1@helpdeskpro.com')['role']);
    $t->assertEquals('employee', test_user($pdo, 'employee1@helpdeskpro.com')['role']);
});

$t->test('employee ticket query only returns their own tickets', function ($t) {
    $pdo = reset_test_database();
    $emp = test_user($pdo, 'employee1@helpdeskpro.com');
    make_ticket($pdo, ['user_id' => $emp['user_id'], 'title' => 'Mine']);

    // Create a second employee with a ticket of their own
    $pdo->prepare("INSERT INTO users (name, email, staff_id, password, role) VALUES ('Other Emp', 'other@helpdeskpro.com', 'EMP-901', 'x', 'employee')")->execute();
    $otherId = $pdo->lastInsertId();
    make_ticket($pdo, ['user_id' => $otherId, 'title' => 'Theirs']);

    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE user_id = ?");
    $stmt->execute([$emp['user_id']]);
    $rows = $stmt->fetchAll();

    $t->assertCount(1, $rows, 'Employee should only see their own ticket');
    $t->assertEquals('Mine', $rows[0]['title']);
});

$t->test('technician query only returns tickets assigned to them', function ($t) {
    $pdo = reset_test_database();
    $tech = test_user($pdo, 'tech1@helpdeskpro.com');
    make_ticket($pdo, ['assigned_to' => $tech['user_id'], 'title' => 'Assigned to me']);
    make_ticket($pdo, ['assigned_to' => null, 'title' => 'Unassigned']);

    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE assigned_to = ?");
    $stmt->execute([$tech['user_id']]);
    $t->assertCount(1, $stmt->fetchAll());
});

$t->test('deleting a user cascades to their tickets', function ($t) {
    $pdo = reset_test_database();
    $pdo->prepare("INSERT INTO users (name, email, staff_id, password, role) VALUES ('Temp', 'temp@helpdeskpro.com', 'EMP-902', 'x', 'employee')")->execute();
    $uid = $pdo->lastInsertId();
    make_ticket($pdo, ['user_id' => $uid]);

    $pdo->exec("DELETE FROM users WHERE user_id = $uid");
    $t->assertEquals(0, (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE user_id = $uid")->fetchColumn());
});

$t->test('deleting a technician nulls assignment but keeps the ticket', function ($t) {
    $pdo = reset_test_database();
    $pdo->prepare("INSERT INTO users (name, email, staff_id, password, role) VALUES ('TempTech', 'temptech@helpdeskpro.com', 'TEC-902', 'x', 'technician')")->execute();
    $tid = $pdo->lastInsertId();
    $ticketId = make_ticket($pdo, ['assigned_to' => $tid]);

    $pdo->exec("DELETE FROM users WHERE user_id = $tid");
    $ticket = $pdo->query("SELECT * FROM tickets WHERE ticket_id = $ticketId")->fetch();

    $t->assertNotEmpty($ticket, 'Ticket should survive technician deletion');
    $t->assertNull($ticket['assigned_to'], 'Assignment should be set to NULL');
});

// =========================================================
$t->group('Notifications');

$t->test('notification is created unread by default', function ($t) {
    $pdo = reset_test_database();
    $id = make_ticket($pdo);
    $pdo->prepare("INSERT INTO notifications (user_id, ticket_id, message) VALUES (1, ?, 'New ticket')")->execute([$id]);

    $n = $pdo->query("SELECT * FROM notifications")->fetch();
    $t->assertEquals(0, (int) $n['is_read']);
});

$t->test('unread count reflects only unread rows for that user', function ($t) {
    $pdo = reset_test_database();
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, ticket_id, message, is_read) VALUES (?, NULL, 'msg', ?)");
    $stmt->execute([1, 0]);
    $stmt->execute([1, 0]);
    $stmt->execute([1, 1]); // already read
    $stmt->execute([2, 0]); // different user

    $c = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $c->execute([1]);
    $t->assertEquals(2, (int) $c->fetchColumn());
});

$t->test('marking all read clears the unread count', function ($t) {
    $pdo = reset_test_database();
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, ticket_id, message) VALUES (1, NULL, 'msg')");
    $stmt->execute(); $stmt->execute();

    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([1]);
    $c = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $c->execute([1]);
    $t->assertEquals(0, (int) $c->fetchColumn());
});

// =========================================================
$t->group('Knowledge Base');

$t->test('sample articles are seeded', function ($t) {
    $pdo = reset_test_database();
    $count = (int) $pdo->query("SELECT COUNT(*) FROM kb_articles")->fetchColumn();
    $t->assertTrue($count >= 5, "Expected at least 5 seeded articles, got $count");
});

$t->test('search matches article titles', function ($t) {
    $pdo = reset_test_database();
    $stmt = $pdo->prepare("SELECT * FROM kb_articles WHERE (title LIKE ? OR body LIKE ?)");
    $stmt->execute(['%printer%', '%printer%']);
    $t->assertNotEmpty($stmt->fetchAll(), 'Searching "printer" should match the printer article');
});

$t->test('search for nonsense returns nothing', function ($t) {
    $pdo = reset_test_database();
    $stmt = $pdo->prepare("SELECT * FROM kb_articles WHERE (title LIKE ? OR body LIKE ?)");
    $stmt->execute(['%zzzzznotathing%', '%zzzzznotathing%']);
    $t->assertEmpty($stmt->fetchAll());
});

$t->test('category filter narrows results correctly', function ($t) {
    $pdo = reset_test_database();
    $stmt = $pdo->prepare("SELECT * FROM kb_articles WHERE category = ?");
    $stmt->execute(['Network']);
    foreach ($stmt->fetchAll() as $row) {
        $t->assertEquals('Network', $row['category']);
    }
});

$t->test('helpful votes increment independently', function ($t) {
    $pdo = reset_test_database();
    $id = (int) $pdo->query("SELECT id FROM kb_articles ORDER BY id LIMIT 1")->fetchColumn();
    $before = $pdo->query("SELECT helpful_yes, helpful_no FROM kb_articles WHERE id = $id")->fetch();

    $pdo->exec("UPDATE kb_articles SET helpful_yes = helpful_yes + 1 WHERE id = $id");
    $after = $pdo->query("SELECT helpful_yes, helpful_no FROM kb_articles WHERE id = $id")->fetch();

    $t->assertEquals($before['helpful_yes'] + 1, $after['helpful_yes']);
    $t->assertEquals($before['helpful_no'], $after['helpful_no'], 'The "no" count should be untouched');
});

// =========================================================
$t->group('Audit log');

$t->test('audit entries persist with action and IP', function ($t) {
    $pdo = reset_test_database();
    $pdo->prepare("INSERT INTO audit_log (user_id, action, details, ip_address) VALUES (1, 'login_success', 'Logged in as admin', '203.0.113.5')")->execute();

    $row = $pdo->query("SELECT * FROM audit_log")->fetch();
    $t->assertEquals('login_success', $row['action']);
    $t->assertEquals('203.0.113.5', $row['ip_address']);
});

$t->test('failed logins can be logged without a user_id', function ($t) {
    $pdo = reset_test_database();
    $pdo->prepare("INSERT INTO audit_log (user_id, action, details, ip_address) VALUES (NULL, 'login_failed', 'bad@example.com', '203.0.113.9')")->execute();

    $row = $pdo->query("SELECT * FROM audit_log")->fetch();
    $t->assertNull($row['user_id'], 'Anonymous failed logins should still be recorded');
});

$t->test('deleting a user preserves their audit history', function ($t) {
    $pdo = reset_test_database();
    $pdo->prepare("INSERT INTO users (name, email, staff_id, password, role) VALUES ('Gone', 'gone@helpdeskpro.com', 'EMP-903', 'x', 'employee')")->execute();
    $uid = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO audit_log (user_id, action, details, ip_address) VALUES (?, 'login_success', 'x', '127.0.0.1')")->execute([$uid]);

    $pdo->exec("DELETE FROM users WHERE user_id = $uid");
    $count = (int) $pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
    $t->assertEquals(1, $count, 'Audit trail must survive user deletion (ON DELETE SET NULL)');
});

// =========================================================
$t->group('Pagination & filtering');

$t->test('LIMIT/OFFSET paginates correctly', function ($t) {
    $pdo = reset_test_database();
    for ($i = 1; $i <= 25; $i++) { make_ticket($pdo, ['title' => "Ticket $i"]); }

    $page1 = $pdo->query("SELECT * FROM tickets ORDER BY ticket_id LIMIT 15 OFFSET 0")->fetchAll();
    $page2 = $pdo->query("SELECT * FROM tickets ORDER BY ticket_id LIMIT 15 OFFSET 15")->fetchAll();

    $t->assertCount(15, $page1);
    $t->assertCount(10, $page2);
    $t->assertNotEquals($page1[0]['ticket_id'], $page2[0]['ticket_id'], 'Pages must not overlap');
});

$t->test('total page count math is correct', function ($t) {
    $t->assertEquals(2, (int) ceil(25 / 15));
    $t->assertEquals(1, (int) ceil(15 / 15));
    $t->assertEquals(1, max(1, (int) ceil(0 / 15)), 'Empty result should still be page 1');
});

$t->test('combined filters narrow results cumulatively', function ($t) {
    $pdo = reset_test_database();
    make_ticket($pdo, ['title' => 'Printer jam', 'status' => 'Open', 'priority' => 'High']);
    make_ticket($pdo, ['title' => 'Printer jam', 'status' => 'Closed', 'priority' => 'High']);
    make_ticket($pdo, ['title' => 'VPN down', 'status' => 'Open', 'priority' => 'High']);

    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE title LIKE ? AND status = ? AND priority = ?");
    $stmt->execute(['%Printer%', 'Open', 'High']);
    $t->assertCount(1, $stmt->fetchAll());
});

// =========================================================
$t->group('Departments (multi-team routing)');

$t->test('default departments are seeded', function ($t) {
    $pdo = reset_test_database();
    $names = $pdo->query("SELECT name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $t->assertTrue(count($names) >= 3, 'Expected at least 3 seeded departments');
    $t->assertTrue(in_array('IT Support', $names), 'IT Support should be seeded');
});

$t->test('department names must be unique', function ($t) {
    $pdo = reset_test_database();
    $rejected = false;
    try {
        $pdo->prepare("INSERT INTO departments (name) VALUES ('IT Support')")->execute();
    } catch (PDOException $e) {
        $rejected = true;
    }
    $t->assertTrue($rejected, 'Duplicate department name should violate the UNIQUE constraint');
});

$t->test('demo technician belongs to IT Support', function ($t) {
    $pdo = reset_test_database();
    $tech = test_user($pdo, 'tech1@helpdeskpro.com');
    $dept = $pdo->query("SELECT name FROM departments WHERE department_id = " . (int) $tech['department_id'])->fetchColumn();
    $t->assertEquals('IT Support', $dept);
});

$t->test('admins are global (no department)', function ($t) {
    $pdo = reset_test_database();
    $t->assertNull(test_user($pdo, 'admin@helpdeskpro.com')['department_id']);
});

$t->test('tickets can be routed to a department', function ($t) {
    $pdo = reset_test_database();
    $deptId = (int) $pdo->query("SELECT department_id FROM departments WHERE name = 'Facilities'")->fetchColumn();
    $id = make_ticket($pdo, ['department_id' => $deptId]);

    $ticket = $pdo->query("SELECT * FROM tickets WHERE ticket_id = $id")->fetch();
    $t->assertEquals($deptId, (int) $ticket['department_id']);
});

$t->test('department queue only returns that team\'s tickets', function ($t) {
    $pdo = reset_test_database();
    $it = (int) $pdo->query("SELECT department_id FROM departments WHERE name = 'IT Support'")->fetchColumn();
    $fac = (int) $pdo->query("SELECT department_id FROM departments WHERE name = 'Facilities'")->fetchColumn();
    make_ticket($pdo, ['department_id' => $it, 'title' => 'IT thing']);
    make_ticket($pdo, ['department_id' => $it, 'title' => 'Another IT thing']);
    make_ticket($pdo, ['department_id' => $fac, 'title' => 'Broken chair']);

    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE department_id = ?");
    $stmt->execute([$fac]);
    $rows = $stmt->fetchAll();
    $t->assertCount(1, $rows);
    $t->assertEquals('Broken chair', $rows[0]['title']);
});

$t->test('eligible technicians for a ticket are limited to its department', function ($t) {
    $pdo = reset_test_database();
    $it = (int) $pdo->query("SELECT department_id FROM departments WHERE name = 'IT Support'")->fetchColumn();
    $fac = (int) $pdo->query("SELECT department_id FROM departments WHERE name = 'Facilities'")->fetchColumn();

    $pdo->prepare("INSERT INTO users (name, email, staff_id, password, role, department_id) VALUES ('Fac Tech', 'factech@x.com', 'TEC-903', 'x', 'technician', ?)")->execute([$fac]);

    // A Facilities ticket should only offer Facilities technicians
    $stmt = $pdo->prepare("SELECT name FROM users WHERE role = 'technician' AND department_id = ?");
    $stmt->execute([$fac]);
    $eligible = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $t->assertCount(1, $eligible);
    $t->assertEquals('Fac Tech', $eligible[0]);
    $t->assertFalse(in_array('Ahmed Technician', $eligible), 'IT technician must not be eligible for a Facilities ticket');
});

$t->test('deleting a department unroutes but never deletes its tickets', function ($t) {
    $pdo = reset_test_database();
    $fac = (int) $pdo->query("SELECT department_id FROM departments WHERE name = 'Facilities'")->fetchColumn();
    $ticketId = make_ticket($pdo, ['department_id' => $fac]);

    $pdo->exec("DELETE FROM departments WHERE department_id = $fac");
    $ticket = $pdo->query("SELECT * FROM tickets WHERE ticket_id = $ticketId")->fetch();

    $t->assertNotEmpty($ticket, 'Ticket must survive department deletion');
    $t->assertNull($ticket['department_id'], 'Ticket should become unrouted, not orphaned');
});

$t->test('deleting a department unassigns its technicians but keeps the accounts', function ($t) {
    $pdo = reset_test_database();
    $fac = (int) $pdo->query("SELECT department_id FROM departments WHERE name = 'Facilities'")->fetchColumn();
    $pdo->prepare("INSERT INTO users (name, email, staff_id, password, role, department_id) VALUES ('Fac Tech', 'factech@x.com', 'TEC-903', 'x', 'technician', ?)")->execute([$fac]);

    $pdo->exec("DELETE FROM departments WHERE department_id = $fac");
    $u = test_user($pdo, 'factech@x.com');

    $t->assertNotEmpty($u, 'Technician account must survive department deletion');
    $t->assertNull($u['department_id']);
});

$t->test('per-department stats aggregate correctly', function ($t) {
    $pdo = reset_test_database();
    $it = (int) $pdo->query("SELECT department_id FROM departments WHERE name = 'IT Support'")->fetchColumn();
    make_ticket($pdo, ['department_id' => $it, 'status' => 'Closed', 'escalated' => 0]);
    make_ticket($pdo, ['department_id' => $it, 'status' => 'Closed', 'escalated' => 1]);
    make_ticket($pdo, ['department_id' => $it, 'status' => 'Open',   'escalated' => 0]);

    $row = $pdo->query("
        SELECT COUNT(t.ticket_id) AS total,
               SUM(t.status != 'Closed') AS active,
               SUM(t.status = 'Closed') AS closed,
               SUM(t.escalated = 1) AS breached
        FROM departments d
        LEFT JOIN tickets t ON t.department_id = d.department_id
        WHERE d.department_id = $it
    ")->fetch();

    $t->assertEquals(3, (int) $row['total']);
    $t->assertEquals(1, (int) $row['active']);
    $t->assertEquals(2, (int) $row['closed']);
    $t->assertEquals(1, (int) $row['breached']);
});

// =========================================================
$t->group('Business-hours SLA (Maldives government hours)');

$t->test('Sunday-Thursday are workdays; Friday/Saturday are not', function ($t) {
    $tz = new DateTimeZone(BUSINESS_TIMEZONE);
    // 2026-09-13 is a Sunday
    $t->assertTrue(BusinessHours::isWorkday(new DateTime('2026-09-13', $tz)), 'Sunday should be a workday');
    $t->assertTrue(BusinessHours::isWorkday(new DateTime('2026-09-17', $tz)), 'Thursday should be a workday');
    $t->assertFalse(BusinessHours::isWorkday(new DateTime('2026-09-18', $tz)), 'Friday is the weekend');
    $t->assertFalse(BusinessHours::isWorkday(new DateTime('2026-09-19', $tz)), 'Saturday is the weekend');
});

$t->test('public holidays are not workdays', function ($t) {
    $tz = new DateTimeZone(BUSINESS_TIMEZONE);
    // 2026-11-03 Victory Day falls on a Tuesday (normally a workday)
    $t->assertFalse(BusinessHours::isWorkday(new DateTime('2026-11-03', $tz)), 'Victory Day should be excluded');
});

$t->test('a working day is 6 hours (08:00-14:00)', function ($t) {
    $t->assertEquals(6, BusinessHours::secondsPerDay() / 3600);
});

$t->test('weekend hours do NOT count toward the SLA clock', function ($t) {
    $tz = new DateTimeZone(BUSINESS_TIMEZONE);
    // Thu 13:00 -> Sun 09:00 = 1h Thursday + 0 weekend + 1h Sunday = 2h
    $hours = BusinessHours::hoursBetween(
        new DateTime('2026-09-17 13:00:00', $tz),
        new DateTime('2026-09-20 09:00:00', $tz)
    );
    $t->assertEquals(2, $hours, 'Friday and Saturday must contribute zero hours');
});

$t->test('SLA filed near closing time rolls to the next working day', function ($t) {
    // Sunday 13:00 + 3 working hours: only 1h left today, so 2h spill to Monday
    $due = BusinessHours::slaDueAt('2026-09-20 13:00:00', 3);
    $t->assertEquals('2026-09-21 10:00:00', $due);
});

$t->test('ticket filed outside hours starts its clock at next opening', function ($t) {
    // Sunday 22:00 (closed) + 2 working hours -> Monday 08:00 + 2h = 10:00
    $due = BusinessHours::slaDueAt('2026-09-20 22:00:00', 2);
    $t->assertEquals('2026-09-21 10:00:00', $due);
});

$t->test('a Thursday afternoon High-priority ticket is not due over the weekend', function ($t) {
    // 24 working hours from Thu 13:00 = 1h Thu + 6h Sun + 6h Mon + 6h Tue + 5h Wed
    $due = BusinessHours::slaDueAt('2026-09-17 13:00:00', 24);
    $tz = new DateTimeZone(BUSINESS_TIMEZONE);
    $dueDt = new DateTime($due, $tz);

    $t->assertEquals('2026-09-23 13:00:00', $due);
    $t->assertTrue(BusinessHours::isWorkday($dueDt), 'A deadline must never land on a weekend');
});

$t->test('SLA deadline always falls on a working day and inside hours', function ($t) {
    $tz = new DateTimeZone(BUSINESS_TIMEZONE);
    // Try every hour across a full week, at each priority
    foreach ([24, 72, 120] as $slaHours) {
        for ($d = 13; $d <= 19; $d++) {
            foreach ([3, 9, 13, 20] as $hour) {
                $created = sprintf('2026-09-%02d %02d:00:00', $d, $hour);
                $due = BusinessHours::slaDueAt($created, $slaHours);
                $dueDt = new DateTime($due, $tz);
                $t->assertTrue(BusinessHours::isWorkday($dueDt), "Deadline $due (from $created) landed on a non-working day");

                $hourOfDay = (int) $dueDt->format('G');
                $t->assertTrue($hourOfDay >= 8 && $hourOfDay <= 14, "Deadline $due is outside office hours");
            }
        }
    }
});

$t->test('business-hours deadline is later than a naive calendar deadline', function ($t) {
    $tz = new DateTimeZone(BUSINESS_TIMEZONE);
    $created = '2026-09-17 13:00:00'; // Thursday
    $business = new DateTime(BusinessHours::slaDueAt($created, 24), $tz);
    $naive = (new DateTime($created, $tz))->modify('+24 hours');

    $t->assertTrue($business > $naive, 'Business-hours SLA should be more generous than raw calendar hours');
});

// =========================================================
$t->group('Audit trail (auditor-grade detail)');

$t->test('audit entry captures actor name and role at the time', function ($t) {
    $pdo = reset_test_database();
    audit_log($pdo, 1, 'login_success', 'Logged in', 'user', 1);

    $row = $pdo->query("SELECT * FROM audit_log ORDER BY id DESC LIMIT 1")->fetch();
    $t->assertEquals('System Admin', $row['actor_name']);
    $t->assertEquals('admin', $row['actor_role']);
});

$t->test('audit entry records the record type and id acted upon', function ($t) {
    $pdo = reset_test_database();
    audit_log($pdo, 1, 'ticket_closed', 'Closed ticket #42', 'ticket', 42);

    $row = $pdo->query("SELECT * FROM audit_log ORDER BY id DESC LIMIT 1")->fetch();
    $t->assertEquals('ticket', $row['entity_type']);
    $t->assertEquals(42, (int) $row['entity_id']);
});

$t->test('before/after state is stored for changes', function ($t) {
    $pdo = reset_test_database();
    audit_log($pdo, 1, 'ticket_status_changed', 'Status change', 'ticket', 7,
        ['status' => 'Open'], ['status' => 'Closed']);

    $row = $pdo->query("SELECT * FROM audit_log ORDER BY id DESC LIMIT 1")->fetch();
    $t->assertContains('Open', $row['old_value']);
    $t->assertContains('Closed', $row['new_value']);
});

$t->test('audit_diff reports only the fields that actually changed', function ($t) {
    $before = ['title' => 'Old title', 'priority' => 'Low', 'category' => 'Other'];
    $after  = ['title' => 'New title', 'priority' => 'Low', 'category' => 'Other'];
    $diff = audit_diff($before, $after, ['title', 'priority', 'category']);

    $t->assertCount(1, $diff, 'Only the changed field should be reported');
    $t->assertEquals('Old title', $diff['title']['from']);
    $t->assertEquals('New title', $diff['title']['to']);
});

$t->test('actor name survives deletion of the user account', function ($t) {
    $pdo = reset_test_database();
    $pdo->prepare("INSERT INTO users (name, email, staff_id, password, role) VALUES ('Temp Auditor', 'temp@x.com', 'TEC-904', 'x', 'technician')")->execute();
    $uid = (int) $pdo->lastInsertId();
    audit_log($pdo, $uid, 'login_success', 'Logged in', 'user', $uid);

    $pdo->exec("DELETE FROM users WHERE user_id = $uid");
    $row = $pdo->query("SELECT * FROM audit_log ORDER BY id DESC LIMIT 1")->fetch();

    $t->assertNull($row['user_id'], 'FK should null out');
    $t->assertEquals('Temp Auditor', $row['actor_name'], 'But the name must remain readable for the audit trail');
});

$t->test('audit log survives even if a field is oversized (never breaks the app)', function ($t) {
    $pdo = reset_test_database();
    // Deliberately oversized action value - audit_log must swallow the error
    $threw = false;
    try {
        audit_log($pdo, 1, str_repeat('x', 500), 'overlong action');
    } catch (Throwable $e) {
        $threw = true;
    }
    $t->assertFalse($threw, 'Auditing must never throw into the calling action');
});

// =========================================================
$t->group('Database backup');

$t->test('backup covers every table in the database', function ($t) {
    $pdo = reset_test_database();
    $all = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $ordered = backup_table_order($pdo);
    sort($all);
    $sorted = $ordered;
    sort($sorted);
    $t->assertEquals($all, $sorted, 'Backup must include exactly the tables that exist');
});

$t->test('tables are ordered so parents come before children', function ($t) {
    $pdo = reset_test_database();
    $order = array_flip(backup_table_order($pdo));
    $t->assertTrue($order['departments'] < $order['users'], 'departments before users');
    $t->assertTrue($order['users'] < $order['tickets'], 'users before tickets');
    $t->assertTrue($order['tickets'] < $order['ticket_logs'], 'tickets before ticket_logs');
});

$t->test('backup restores to a byte-identical database (round trip)', function ($t) {
    $pdo = reset_test_database();

    // Awkward data: quotes, backslashes, newlines, NULLs, non-Latin script,
    // and text that looks like SQL.
    make_ticket($pdo, ['title' => "Quote's \"double\" \\ backslash", 'description' => "Line one\nLine two"]);
    make_ticket($pdo, ['title' => 'ދިވެހި Dhivehi text', 'description' => 'Unicode — dashes']);
    make_ticket($pdo, ['title' => 'x; DROP TABLE users; --', 'department_id' => null]);

    $sql = '';
    $summary = generate_sql_backup($pdo, function ($chunk) use (&$sql) { $sql .= $chunk; }, 'test');
    $t->assertTrue($summary['rows'] > 0, 'Backup should contain rows');

    // Restore into a SEPARATE scratch database
    $restoreDb = TEST_DB_NAME . '_restore';
    $root = new PDO("mysql:host=" . TEST_DB_HOST . ";charset=utf8mb4", TEST_DB_USER, TEST_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `$restoreDb`");
    $root->exec("CREATE DATABASE `$restoreDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $root->exec("USE `$restoreDb`");
    $root->exec($sql);

    // Compare every table's full content via CHECKSUM TABLE
    foreach (backup_table_order($pdo) as $table) {
        $a = $pdo->query("CHECKSUM TABLE `" . TEST_DB_NAME . "`.`$table`")->fetch(PDO::FETCH_NUM)[1];
        $b = $root->query("CHECKSUM TABLE `$restoreDb`.`$table`")->fetch(PDO::FETCH_NUM)[1];
        $t->assertEquals($a, $b, "Table '$table' differs after restore");
    }

    // SQL-looking text must have stayed as text, not executed
    $users = (int) $root->query("SELECT COUNT(*) FROM `$restoreDb`.users")->fetchColumn();
    $t->assertTrue($users > 0, 'users table must survive a title containing DROP TABLE');

    $root->exec("DROP DATABASE IF EXISTS `$restoreDb`");
});

$t->test('backup can be restored twice without errors (re-runnable)', function ($t) {
    $pdo = reset_test_database();
    $sql = '';
    generate_sql_backup($pdo, function ($chunk) use (&$sql) { $sql .= $chunk; }, 'test');

    $restoreDb = TEST_DB_NAME . '_restore2';
    $root = new PDO("mysql:host=" . TEST_DB_HOST . ";charset=utf8mb4", TEST_DB_USER, TEST_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `$restoreDb`");
    $root->exec("CREATE DATABASE `$restoreDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $root->exec("USE `$restoreDb`");
    $root->exec($sql);
    $root->exec($sql); // second time over existing tables

    $count = (int) $root->query("SELECT COUNT(*) FROM `$restoreDb`.users")->fetchColumn();
    $original = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $t->assertEquals($original, $count, 'Restoring twice must not duplicate rows');
    $root->exec("DROP DATABASE IF EXISTS `$restoreDb`");
});

// =========================================================
$t->group('Notification read state');

$t->test('marking one notification read leaves the others unread', function ($t) {
    $pdo = reset_test_database();
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, ticket_id, message) VALUES (1, NULL, ?)");
    $stmt->execute(['first']); $first = (int) $pdo->lastInsertId();
    $stmt->execute(['second']);

    mark_notification_read($pdo, 1, $first);
    $t->assertEquals(1, get_unread_notification_count($pdo, 1), 'Only the chosen one should be marked');
});

$t->test('a user cannot mark someone else\'s notification as read', function ($t) {
    $pdo = reset_test_database();
    $pdo->prepare("INSERT INTO notifications (user_id, ticket_id, message) VALUES (2, NULL, 'belongs to user 2')")->execute();
    $theirs = (int) $pdo->lastInsertId();

    $changed = mark_notification_read($pdo, 1, $theirs); // user 1 tries it
    $t->assertFalse($changed, 'Must refuse to modify another user\'s notification');
    $t->assertEquals(1, get_unread_notification_count($pdo, 2), 'User 2\'s notification must stay unread');
});

$t->test('a user cannot read someone else\'s notification by id', function ($t) {
    $pdo = reset_test_database();
    $pdo->prepare("INSERT INTO notifications (user_id, ticket_id, message) VALUES (2, NULL, 'private')")->execute();
    $theirs = (int) $pdo->lastInsertId();
    $t->assertEmpty(get_own_notification($pdo, 1, $theirs));
});

$t->test('mark all read returns how many were changed', function ($t) {
    $pdo = reset_test_database();
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, ticket_id, message) VALUES (1, NULL, 'm')");
    $stmt->execute(); $stmt->execute(); $stmt->execute();
    $t->assertEquals(3, mark_all_notifications_read($pdo, 1));
    $t->assertEquals(0, mark_all_notifications_read($pdo, 1), 'Second call has nothing left to mark');
});

// =========================================================
$t->group('First sign-in password change');

$t->test('seeded demo accounts are NOT forced to change password', function ($t) {
    $pdo = reset_test_database();
    foreach (['admin@helpdeskpro.com', 'tech1@helpdeskpro.com', 'employee1@helpdeskpro.com'] as $email) {
        $u = test_user($pdo, $email);
        $t->assertEquals(0, (int) $u['must_change_password'], "$email must be able to sign in straight away");
        $t->assertNull(password_change_reason($u), "$email should have no pending password change");
    }
});

$t->test('new accounts default to no forced change unless flagged', function ($t) {
    $pdo = reset_test_database();
    $pdo->prepare("INSERT INTO users (name, email, staff_id, password, role) VALUES ('X', 'x@x.com', 'EMP-904', 'h', 'employee')")->execute();
    $t->assertEquals(0, (int) test_user($pdo, 'x@x.com')['must_change_password']);
});

$t->test('an admin-set password forces a change for EVERY role', function ($t) {
    foreach (['employee', 'technician', 'admin'] as $role) {
        $reason = password_change_reason(['role' => $role, 'must_change_password' => 1, 'password_changed_at' => date('Y-m-d H:i:s')]);
        $t->assertEquals('admin_set', $reason, "$role with an admin-set password must be forced to change it");
    }
});

$t->test('no flag and expiry disabled means no forced change', function ($t) {
    $reason = password_change_reason(['role' => 'employee', 'must_change_password' => 0, 'password_changed_at' => date('Y-m-d H:i:s')]);
    $t->assertNull($reason);
});

$t->test('the admin-set reason takes priority over expiry', function ($t) {
    // Even an admin whose password is ancient is told the more specific reason
    $reason = password_change_reason(['role' => 'admin', 'must_change_password' => 1, 'password_changed_at' => '2000-01-01 00:00:00']);
    $t->assertEquals('admin_set', $reason);
});

// =========================================================
$t->group('Staff ID & sign-in identifiers');

$t->test('every seeded account has a staff ID', function ($t) {
    $pdo = reset_test_database();
    $blank = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE staff_id IS NULL OR staff_id = ''")->fetchColumn();
    $t->assertEquals(0, $blank);
});

$t->test('staff IDs must be unique', function ($t) {
    $pdo = reset_test_database();
    $existing = $pdo->query("SELECT staff_id FROM users LIMIT 1")->fetchColumn();
    $rejected = false;
    try {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, staff_id, password, role) VALUES ('Dup', 'dup@x.com', ?, 'h', 'employee')");
        $stmt->execute([$existing]);
    } catch (PDOException $e) {
        $rejected = true;
    }
    $t->assertTrue($rejected, 'Duplicate staff ID must violate the UNIQUE constraint');
});

$t->test('sign-in finds the account by email', function ($t) {
    $pdo = reset_test_database();
    $u = find_user_by_identifier($pdo, 'tech1@helpdeskpro.com');
    $t->assertNotEmpty($u);
    $t->assertEquals('TEC-0001', $u['staff_id']);
});

$t->test('sign-in finds the same account by staff ID', function ($t) {
    $pdo = reset_test_database();
    $u = find_user_by_identifier($pdo, 'TEC-0001');
    $t->assertNotEmpty($u);
    $t->assertEquals('tech1@helpdeskpro.com', $u['email']);
});

$t->test('staff ID sign-in is not case sensitive', function ($t) {
    $pdo = reset_test_database();
    $u = find_user_by_identifier($pdo, 'tec-0001');
    $t->assertNotEmpty($u, 'Lowercase staff ID should still match');
    $t->assertEquals('tech1@helpdeskpro.com', $u['email']);
});

$t->test('an unknown identifier finds nothing', function ($t) {
    $pdo = reset_test_database();
    $t->assertEmpty(find_user_by_identifier($pdo, 'nobody@nowhere.com'));
    $t->assertEmpty(find_user_by_identifier($pdo, 'ZZZ-9999'));
    $t->assertEmpty(find_user_by_identifier($pdo, ''));
});

$t->test('lockout counts against the ACCOUNT, whichever identifier is typed', function ($t) {
    $pdo = reset_test_database();
    $byEmail = find_user_by_identifier($pdo, 'tech1@helpdeskpro.com');
    $byStaff = find_user_by_identifier($pdo, 'TEC-0001');
    $t->assertEquals(
        login_lockout_key($byEmail, 'tech1@helpdeskpro.com'),
        login_lockout_key($byStaff, 'TEC-0001'),
        'Both identifiers must resolve to the same lockout key'
    );
});

$t->test('alternating email and staff ID cannot dodge the 5-attempt lockout', function ($t) {
    $pdo = reset_test_database();

    // Three tries with the email, two with the staff ID - five in total
    foreach (['tech1@helpdeskpro.com', 'TEC-0001', 'tech1@helpdeskpro.com', 'TEC-0001', 'tech1@helpdeskpro.com'] as $typed) {
        $user = find_user_by_identifier($pdo, $typed);
        record_login_attempt($pdo, login_lockout_key($user, $typed), false);
    }

    $user = find_user_by_identifier($pdo, 'TEC-0001');
    $t->assertTrue(is_login_locked_out($pdo, login_lockout_key($user, 'TEC-0001')), 'Account must be locked out');
    $t->assertTrue(is_login_locked_out($pdo, login_lockout_key($user, 'tech1@helpdeskpro.com')), 'Locked out by email too');
});

$t->test('unknown identifiers are still rate limited', function ($t) {
    $pdo = reset_test_database();
    for ($i = 0; $i < 5; $i++) {
        record_login_attempt($pdo, login_lockout_key(false, 'GUESS-001'), false);
    }
    $t->assertTrue(is_login_locked_out($pdo, login_lockout_key(false, 'GUESS-001')));
});

$t->test('locking one account does not lock another', function ($t) {
    $pdo = reset_test_database();
    for ($i = 0; $i < 5; $i++) {
        $u = find_user_by_identifier($pdo, 'TEC-0001');
        record_login_attempt($pdo, login_lockout_key($u, 'TEC-0001'), false);
    }
    $other = find_user_by_identifier($pdo, 'EMP-0001');
    $t->assertFalse(is_login_locked_out($pdo, login_lockout_key($other, 'EMP-0001')));
});

// =========================================================
$t->group('Automatic staff ID generation');

$t->test('prefix matches the role', function ($t) {
    $t->assertEquals('ADM', staff_id_prefix('admin'));
    $t->assertEquals('TEC', staff_id_prefix('technician'));
    $t->assertEquals('EMP', staff_id_prefix('employee'));
});

$t->test('next ID follows the seeded accounts', function ($t) {
    $pdo = reset_test_database();
    // seeds are ADM-0001 / TEC-0001 / EMP-0001
    $t->assertEquals('ADM-0002', next_staff_id($pdo, 'admin'));
    $t->assertEquals('TEC-0002', next_staff_id($pdo, 'technician'));
    $t->assertEquals('EMP-0002', next_staff_id($pdo, 'employee'));
});

$t->test('IDs are issued in order within a role', function ($t) {
    $pdo = reset_test_database();
    $issued = [];
    for ($i = 0; $i < 3; $i++) {
        $r = create_user_with_staff_id($pdo, "Emp $i", "emp$i@x.com", 'h', 'employee', null);
        $issued[] = $r['staff_id'];
    }
    $t->assertEquals(['EMP-0002', 'EMP-0003', 'EMP-0004'], $issued);
});

$t->test('each role is numbered independently', function ($t) {
    $pdo = reset_test_database();
    $e = create_user_with_staff_id($pdo, 'E', 'e@x.com', 'h', 'employee', null);
    $tech = create_user_with_staff_id($pdo, 'T', 't@x.com', 'h', 'technician', null);
    $a = create_user_with_staff_id($pdo, 'A', 'a@x.com', 'h', 'admin', null);
    $t->assertEquals('EMP-0002', $e['staff_id']);
    $t->assertEquals('TEC-0002', $tech['staff_id'], 'Adding an employee must not advance the technician numbering');
    $t->assertEquals('ADM-0002', $a['staff_id']);
});

$t->test('IDs are zero-padded to four digits and keep going past 9', function ($t) {
    $pdo = reset_test_database();
    $pdo->exec("UPDATE users SET staff_id = 'EMP-0009' WHERE staff_id = 'EMP-0001'");
    $t->assertEquals('EMP-0010', next_staff_id($pdo, 'employee'));
    $pdo->exec("UPDATE users SET staff_id = 'EMP-0099' WHERE staff_id = 'EMP-0009'");
    $t->assertEquals('EMP-0100', next_staff_id($pdo, 'employee'));
});

$t->test('a deleted account never has its ID reused', function ($t) {
    $pdo = reset_test_database();
    $a = create_user_with_staff_id($pdo, 'A', 'a@x.com', 'h', 'employee', null);
    $b = create_user_with_staff_id($pdo, 'B', 'b@x.com', 'h', 'employee', null);
    $t->assertEquals('EMP-0003', $b['staff_id']);

    $pdo->exec("DELETE FROM users WHERE email = 'b@x.com'");
    $c = create_user_with_staff_id($pdo, 'C', 'c@x.com', 'h', 'employee', null);
    $t->assertEquals('EMP-0004', $c['staff_id'], 'Must continue past the gap, not reuse EMP-0003');
    $t->assertNotEquals($b['staff_id'], $c['staff_id']);
});

$t->test('numbering is based on issued IDs, not the number of rows', function ($t) {
    $pdo = reset_test_database();
    $pdo->exec("DELETE FROM notifications");
    $pdo->exec("DELETE FROM tickets");
    $pdo->exec("UPDATE users SET staff_id = 'EMP-0050' WHERE staff_id = 'EMP-0001'");
    $r = create_user_with_staff_id($pdo, 'N', 'n@x.com', 'h', 'employee', null);
    $t->assertEquals('EMP-0051', $r['staff_id']);
});

$t->test('a clashing ID is retried rather than failing', function ($t) {
    $pdo = reset_test_database();
    // Simulate another admin having just taken the next ID
    $pdo->prepare("INSERT INTO users (name, email, staff_id, password, role) VALUES ('Race', 'race@x.com', 'EMP-0002', 'h', 'employee')")->execute();
    $r = create_user_with_staff_id($pdo, 'After', 'after@x.com', 'h', 'employee', null);
    $t->assertEquals('EMP-0003', $r['staff_id'], 'Should move past the taken ID');
});

$t->test('a duplicate email still raises an error (not silently retried)', function ($t) {
    $pdo = reset_test_database();
    $threw = false;
    try {
        create_user_with_staff_id($pdo, 'Dup', 'employee1@helpdeskpro.com', 'h', 'employee', null);
    } catch (PDOException $e) {
        $threw = true;
    }
    $t->assertTrue($threw, 'Duplicate email must surface, not be swallowed by the retry loop');
});

$t->test('new accounts are created needing a password change', function ($t) {
    $pdo = reset_test_database();
    $r = create_user_with_staff_id($pdo, 'P', 'p@x.com', 'h', 'employee', null);
    $u = test_user($pdo, 'p@x.com');
    $t->assertEquals(1, (int) $u['must_change_password']);
    $t->assertEquals($r['staff_id'], $u['staff_id']);
});

// =========================================================
$t->group('Edit / withdraw permissions');

// Mirrors the rule used by ticket.php and employee/edit_ticket.php
$unclaimed = function ($ticket) {
    return $ticket['status'] === 'Open' && $ticket['assigned_to'] === null;
};

$t->test('an unassigned Open ticket can be edited and withdrawn', function ($t) use ($unclaimed) {
    $pdo = reset_test_database();
    $id = make_ticket($pdo, ['status' => 'Open', 'assigned_to' => null]);
    $ticket = $pdo->query("SELECT * FROM tickets WHERE ticket_id = $id")->fetch();
    $t->assertTrue($unclaimed($ticket));
});

$t->test('an ASSIGNED ticket cannot be edited or withdrawn even while Open', function ($t) use ($unclaimed) {
    $pdo = reset_test_database();
    // A technician can set an assigned ticket back to Open - the submitter
    // must still not be able to change or cancel it.
    $id = make_ticket($pdo, ['status' => 'Open', 'assigned_to' => 2]);
    $ticket = $pdo->query("SELECT * FROM tickets WHERE ticket_id = $id")->fetch();
    $t->assertFalse($unclaimed($ticket), 'Open + assigned must NOT count as unclaimed');
});

$t->test('an In Progress ticket cannot be edited or withdrawn', function ($t) use ($unclaimed) {
    $pdo = reset_test_database();
    $id = make_ticket($pdo, ['status' => 'In Progress', 'assigned_to' => 2]);
    $ticket = $pdo->query("SELECT * FROM tickets WHERE ticket_id = $id")->fetch();
    $t->assertFalse($unclaimed($ticket));
});

$t->test('a Closed ticket cannot be edited or withdrawn', function ($t) use ($unclaimed) {
    $pdo = reset_test_database();
    $id = make_ticket($pdo, ['status' => 'Closed', 'assigned_to' => null]);
    $ticket = $pdo->query("SELECT * FROM tickets WHERE ticket_id = $id")->fetch();
    $t->assertFalse($unclaimed($ticket));
});

// =========================================================
exit($t->summary());
