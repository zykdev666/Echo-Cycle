<?php
/**
 * EcoCycle — admin migration.
 *
 * Safely upgrades an EXISTING database (without dropping data):
 *   1. Adds the `is_admin` column to `users` if it is missing.
 *   2. Creates the owner/administrator account "Zyk Granada",
 *      or promotes it to admin if the email already exists.
 *
 * Run once:  php migrate_admin.php   (or open it in the browser).
 * Idempotent — safe to run multiple times.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

header('Content-Type: text/plain; charset=utf-8');

// ---- Owner details ----
const ADMIN_NAME  = 'Zyk Granada';
const ADMIN_EMAIL = 'zyk.granada@ecocycle.local';
const ADMIN_PASS  = 'passw0rd!';
const ADMIN_HOOD  = 'Greendale';

try {
    $pdo = db();

    // 1. Add is_admin column if it does not exist.
    $hasColumn = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'users'
            AND COLUMN_NAME = 'is_admin'"
    )->fetchColumn();

    if ((int) $hasColumn === 0) {
        $pdo->exec('ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER last_log_date');
        echo "[OK] Added `is_admin` column to users.\n";
    } else {
        echo "[--] `is_admin` column already present.\n";
    }

    // 2. Create or promote the owner account.
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([ADMIN_EMAIL]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $pdo->prepare('UPDATE users SET name = ?, is_admin = 1 WHERE id = ?')
            ->execute([ADMIN_NAME, $existingId]);
        echo "[OK] Promoted existing account (" . ADMIN_EMAIL . ") to administrator.\n";
    } else {
        $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, neighborhood, is_admin) VALUES (?, ?, ?, ?, 1)'
        )->execute([ADMIN_NAME, ADMIN_EMAIL, password_hash(ADMIN_PASS, PASSWORD_DEFAULT), ADMIN_HOOD]);
        echo "[OK] Created administrator account for " . ADMIN_NAME . ".\n";
    }

    echo "\nDone! Log in as the owner:\n";
    echo "   Email:    " . ADMIN_EMAIL . "\n";
    echo "   Password: " . ADMIN_PASS . "  (please change it on your profile)\n";
    echo "\nThe Admin dashboard link will appear in the top navigation once you log in.\n";
} catch (Throwable $ex) {
    http_response_code(500);
    echo "[ERROR] Migration failed: " . $ex->getMessage() . "\n";
}
