<?php
/**
 * EcoCycle — central configuration & PDO database connection.
 *
 * Adjust DB credentials here if your XAMPP setup differs from the defaults.
 */

declare(strict_types=1);

// ---- Database credentials (XAMPP defaults) ----
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'ecocycle';
const DB_USER = 'root';
const DB_PASS = '';

// ---- App settings ----
const APP_NAME = 'EcoCycle';
const APP_TAGLINE = 'Turn everyday recycling into a rewarding community habit.';
const WALLET_MIN_WITHDRAWAL = 500;
const WALLET_POINTS_PER_PESO = 10;
const WALLET_DAILY_WITHDRAWAL_CAP = 5000;

/**
 * Return a shared PDO connection.
 *
 * @param bool $withDatabase  When false, connect to the server only (used by setup).
 */
function db(bool $withDatabase = true): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO && $withDatabase) {
        return $pdo;
    }

    // Dynamic environment check for InfinityFree live server vs Localhost
    if (isset($_SERVER['HTTP_HOST']) && (str_contains($_SERVER['HTTP_HOST'], 'infinityfreeapp.com') || str_contains($_SERVER['HTTP_HOST'], 'site.je'))) {
        $host     = 'sql212.infinityfree.com'; // Fixed typo address here!
        $port     = '3306';
        $dbname   = 'if0_42930564_echocycle_db';
        $username = 'if0_42930564';
        $password = 'EchoCycle666';
    } else {
        $host     = DB_HOST;
        $port     = DB_PORT;
        $dbname   = DB_NAME;
        $username = DB_USER;
        $password = DB_PASS;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port);
    if ($withDatabase) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbname);
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $connection = new PDO($dsn, $username, $password, $options);
    if ($withDatabase) {
        $pdo = $connection;
    }
    return $connection;
}
