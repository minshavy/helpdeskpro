<?php
/**
 * Staff ID generation
 * ---------------------------------------------------------
 * Staff IDs are issued automatically from the person's role, numbered in
 * order within that role:
 *
 *   Admin      ADM-0001, ADM-0002, ...
 *   Technician TEC-0001, TEC-0002, ...
 *   Employee   EMP-0001, EMP-0002, ...
 *
 * In a real deployment HR would issue these numbers; generating them keeps
 * account creation to one step here while still producing a sensible,
 * readable identifier.
 */

/** Prefix used for each role. */
function staff_id_prefix($role) {
    switch ($role) {
        case 'admin':      return 'ADM';
        case 'technician': return 'TEC';
        default:           return 'EMP';
    }
}

/**
 * Make sure the counter table exists and is level with the accounts already
 * in the database.
 *
 * This is self-healing on purpose: if an upgrade is applied only partly (for
 * example phpMyAdmin stops at the first failed statement), the application
 * repairs itself instead of failing with a missing-table error. It seeds from
 * the highest ID already issued, so the no-reuse guarantee still holds.
 */
function ensure_staff_id_sequence($pdo) {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS staff_id_sequence (
            prefix VARCHAR(8) NOT NULL PRIMARY KEY,
            last_number INT NOT NULL DEFAULT 0
        )
    ");

    foreach (['ADM', 'TEC', 'EMP'] as $prefix) {
        $stmt = $pdo->prepare("
            INSERT INTO staff_id_sequence (prefix, last_number)
            SELECT ?, COALESCE(MAX(CAST(SUBSTRING(staff_id, ?) AS UNSIGNED)), 0)
            FROM users WHERE staff_id LIKE ?
            ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number))
        ");
        $stmt->execute([$prefix, strlen($prefix) + 2, $prefix . '-%']);
    }
}

/** Highest number already present in the users table for a prefix. */
function highest_issued_number($pdo, $prefix) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING(staff_id, ?) AS UNSIGNED)), 0)
        FROM users WHERE staff_id LIKE ?
    ");
    $stmt->execute([strlen($prefix) + 2, $prefix . '-%']);
    return (int) $stmt->fetchColumn();
}

/**
 * What the NEXT ID will be, without consuming it. Used to preview the ID on
 * the create-user form.
 */
function next_staff_id($pdo, $role) {
    ensure_staff_id_sequence($pdo);
    $prefix = staff_id_prefix($role);

    $stmt = $pdo->prepare("SELECT last_number FROM staff_id_sequence WHERE prefix = ?");
    $stmt->execute([$prefix]);
    $counter = (int) $stmt->fetchColumn();

    // Take whichever is higher: the counter, or what's actually in the users
    // table. The second case covers a database restored from a backup taken
    // before the counter existed.
    $floor = max($counter, highest_issued_number($pdo, $prefix));
    return sprintf('%s-%04d', $prefix, $floor + 1);
}

/**
 * Claim the next ID for a role and move the counter forward.
 *
 * The counter only ever increases, so deleting an account does NOT free its
 * ID for reuse. The whole thing is a single statement, so two admins adding
 * people at the same moment can't be handed the same number.
 */
function allocate_staff_id($pdo, $role) {
    ensure_staff_id_sequence($pdo);
    $prefix = staff_id_prefix($role);
    $floor = max(0, highest_issued_number($pdo, $prefix));

    $stmt = $pdo->prepare("
        INSERT INTO staff_id_sequence (prefix, last_number)
        VALUES (?, LAST_INSERT_ID(? + 1))
        ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(GREATEST(last_number, ?) + 1)
    ");
    $stmt->execute([$prefix, $floor, $floor]);

    return sprintf('%s-%04d', $prefix, (int) $pdo->lastInsertId());
}

/**
 * Create a user, assigning the next staff ID for their role.
 *
 * Two admins adding people at the same moment could both compute the same
 * next ID. The UNIQUE constraint on staff_id stops a duplicate being stored,
 * and this retries with a freshly computed ID rather than failing.
 *
 * @return array ['user_id' => int, 'staff_id' => string]
 */
function create_user_with_staff_id($pdo, $name, $email, $password_hash, $role, $department_id, $must_change_password = 1) {
    $attempts = 0;
    while (true) {
        $attempts++;
        $staff_id = allocate_staff_id($pdo, $role);
        try {
            $stmt = $pdo->prepare("
                INSERT INTO users (name, email, staff_id, password, role, department_id, must_change_password)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $email, $staff_id, $password_hash, $role, $department_id, $must_change_password ? 1 : 0]);
            return ['user_id' => (int) $pdo->lastInsertId(), 'staff_id' => $staff_id];
        } catch (PDOException $e) {
            // 23000 = integrity constraint violation. If it was the staff ID
            // racing another admin, recompute and retry; anything else (a
            // duplicate email, say) is a real error the caller must see.
            $isDuplicate = $e->getCode() === '23000' && stripos($e->getMessage(), 'staff_id') !== false;
            if (!$isDuplicate || $attempts >= 5) {
                throw $e;
            }
        }
    }
}
