<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\Repositories\AuditLogRepository;
use App\Tests\DatabaseTestCase;

final class AuditLogRepositoryTest extends DatabaseTestCase
{
    public function testLogsAndListsErrors(): void
    {
        $repo = new AuditLogRepository($this->pdo);

        $repo->log('admin', 'admin-1', 'app.create', 'success', 'app-9');
        $repo->log('app', 'app-1', 'upload.rejected', 'error', null, [
            'reason' => 'no_storage_available',
            'errors' => [['storage_id' => 'b1', 'error' => 'quota_exceeded']],
        ]);

        $errors = $repo->listErrors();
        $this->assertCount(1, $errors);
        $this->assertSame('upload.rejected', $errors[0]['action']);
        $decoded = json_decode((string) $errors[0]['metadata'], true);
        $this->assertSame('no_storage_available', $decoded['reason']);
    }

    public function testSuccessRowsDoNotAppearInErrors(): void
    {
        $repo = new AuditLogRepository($this->pdo);
        $repo->log('admin', 'admin-1', 'app.create', 'success');
        $repo->log('admin', 'admin-1', 'storage.disable', 'success');

        $this->assertCount(0, $repo->listErrors());
        $this->assertSame(0, $repo->countErrors());
    }

    public function testCountErrorsMatchesList(): void
    {
        $repo = new AuditLogRepository($this->pdo);
        for ($i = 1; $i <= 4; $i++) {
            $repo->log('app', 'app-1', 'upload.rejected', 'error', null, ['reason' => 'no_storage_available']);
        }

        $this->assertSame(4, $repo->countErrors());
        $this->assertCount(2, $repo->listErrors(2, 0));
        $this->assertCount(2, $repo->listErrors(2, 2));
        $this->assertCount(0, $repo->listErrors(2, 4));
    }

    public function testPruneOlderThanRemovesExpiredRowsOnly(): void
    {
        $repo = new AuditLogRepository($this->pdo);

        $repo->log('admin', 'a', 'admin.login', 'success');
        // Backdate a row to 40 days ago.
        $this->pdo->exec(
            "UPDATE audit_log SET created_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now', '-40 days') WHERE action = 'admin.login'"
        );
        $repo->log('app', 'p', 'upload.rejected', 'error', null, ['reason' => 'x']);

        $totalBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
        $this->assertSame(2, $totalBefore);

        $pruned = $repo->pruneOlderThan(30);
        $this->assertSame(1, $pruned); // only the 40-day-old row

        $remaining = (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
        $this->assertSame(1, $remaining);
        $this->assertSame('upload.rejected', $this->pdo->query("SELECT action FROM audit_log")->fetchColumn());
    }
}
