<?php

declare(strict_types=1);

namespace App\Tests;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that need a working SQLite schema. Every migration is
 * applied against a fresh in-memory database, so repository tests never
 * touch real storage/ data and each test starts clean.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected ?PDO $pdo = null;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->applyMigrations($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->pdo = null;
    }

    /** Applies every migration in src/Data/Migrations, tracking applied ones in schema_migrations. */
    protected function applyMigrations(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                migration TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
            )"
        );

        $dir = dirname(__DIR__) . '/src/Data/Migrations';
        $files = glob($dir . '/*.sql');
        sort($files);

        foreach ($files as $file) {
            $name = basename($file);

            $check = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = :m');
            $check->execute([':m' => $name]);
            if ((int) $check->fetchColumn() > 0) {
                continue;
            }

            $pdo->exec((string) file_get_contents($file));
            $insert = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:m)');
            $insert->execute([':m' => $name]);
        }
    }

    /** @return list<string> */
    protected function columnNames(string $table): array
    {
        $stmt = $this->pdo->prepare('PRAGMA table_info(' . $table . ')');
        $stmt->execute();

        return array_column($stmt->fetchAll(), 'name');
    }
}
