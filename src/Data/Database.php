<?php

declare(strict_types=1);

namespace App\Data;

use PDO;

/**
 * Thin PDO/SQLite connection factory. No ORM — repositories use PDO
 * directly with prepared statements.
 */
final class Database
{
    private static ?PDO $connection = null;

    public static function connect(string $dbPath): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // WAL mode: better concurrent read/write behavior than the default
        // rollback journal.
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        self::$connection = $pdo;

        return $pdo;
    }

    /** For tests: force a fresh connection (e.g. against a temp DB file). */
    public static function reset(): void
    {
        self::$connection = null;
    }
}
