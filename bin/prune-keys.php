#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Data\Database;
use App\Data\Repositories\AppRepository;
use App\Data\Repositories\AuditLogRepository;
use App\Data\Repositories\FileRepository;
use App\Support\Config;
use App\Support\OpsLock;

Config::load(__DIR__ . '/../.env');

$projectRoot = __DIR__ . '/../';
$dbPath = Config::get('DB_PATH', 'storage/db/router.sqlite');
$dbPath = str_starts_with($dbPath, '/') ? $dbPath : $projectRoot . $dbPath;

$keyStorePath = Config::get('KEK_STORE_PATH', 'storage/keys');
$keyStorePath = str_starts_with($keyStorePath, '/') ? $keyStorePath : $projectRoot . $keyStorePath;

// Deletion and backups must never overlap.
try {
    OpsLock::acquire($projectRoot . 'storage/ops.lock', 'Key pruning');
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$pdo = Database::connect($dbPath);
$apps = new AppRepository($pdo);
$files = new FileRepository($pdo);
$auditLog = new AuditLogRepository($pdo);

// Scheduled, unattended KEK retention: for every app, destroy each
// historical version that is (a) below the app's current version and
// (b) referenced by NO file of any status. This is exactly the gate the
// manual bin/delete-kek.php enforces, so the automated run and the manual
// run can never disagree (no query-logic mismatch).
$purged = 0;
$errors = 0;

foreach ($apps->listAll() as $app) {
    $appId = (string) $app['id'];
    $ref = (string) $app['kek_ref'];
    $current = (int) $app['kek_version'];

    for ($version = 1; $version < $current; $version++) {
        if ($files->countForAppVersion($appId, $version) > 0) {
            continue; // still referenced (incl. soft-deleted) — keep it
        }

        $suffix = $version > 1 ? ".v{$version}" : '';
        $target = rtrim($keyStorePath, '/') . '/' . $ref . $suffix . '.kek';

        if (!is_file($target)) {
            continue;
        }

        if (!unlink($target)) {
            fwrite(STDERR, "  FAILED to delete: {$target}" . PHP_EOL);
            $errors++;
            continue;
        }

        $auditLog->log('admin', 'scheduled:prune-keys', 'kek.delete', 'success', $appId, [
            'kek_ref' => $ref,
            'kek_version' => $version,
            'reason' => 'scheduled_purge',
        ]);

        fwrite(STDOUT, "  Purged KEK v{$version} for app {$appId}: {$target}" . PHP_EOL);
        $purged++;
    }
}

fwrite(STDOUT, "Prune complete: {$purged} historical KEK file(s) destroyed." . PHP_EOL);

// Exit non-zero whenever anything failed so a cron/monitoring wrapper sees
// "failure" and can alert — do not rely on operator memory.
if ($errors > 0) {
    fwrite(STDERR, "{$errors} deletion(s) failed — investigate before the next run." . PHP_EOL);
    exit(1);
}

exit(0);