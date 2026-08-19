<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Tests\DatabaseTestCase;

final class MigrationTest extends DatabaseTestCase
{
    private const EXPECTED_TABLES = [
        'apps',
        'storage_backends',
        'app_storage_access',
        'files',
        'admins',
        'audit_log',
        'rate_limits',
        'schema_migrations',
    ];

    public function testAllExpectedTablesExist(): void
    {
        $tables = $this->pdo
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
            ->fetchAll(\PDO::FETCH_COLUMN);

        foreach (self::EXPECTED_TABLES as $table) {
            $this->assertContains($table, $tables, "expected table {$table} to exist");
        }
    }

    public function testMigrationsAreIdempotent(): void
    {
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        $this->assertGreaterThan(0, $before);

        // Re-running must not error (tables would already exist) and must
        // not add duplicate migration rows.
        $this->applyMigrations($this->pdo);
        $this->applyMigrations($this->pdo);

        $after = (int) $this->pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        $this->assertSame($before, $after);
    }

    public function testVersionedKekColumnsExist(): void
    {
        $this->assertContains('kek_version', $this->columnNames('apps'));
        $this->assertContains('kek_version', $this->columnNames('files'));
    }

    public function testForeignKeyConstraintsAreEnforced(): void
    {
        $this->expectException(\PDOException::class);
        // files.app_id references apps(id) with ON DELETE RESTRICT — inserting
        // a file for a non-existent app must be rejected.
        $this->pdo->exec(
            "INSERT INTO files (id, app_id, user_id, storage_id, provider_ref, encrypted_dek,
                                stream_header, size_bytes, mime_type, checksum_plaintext)
             VALUES ('f1', 'no-such-app', NULL, 'no-such-backend', 'r', 'd', 'h', 1, 't', 'c')"
        );
    }
}
