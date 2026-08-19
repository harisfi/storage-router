#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Crypto\KeyManager;
use App\Data\Database;
use App\Data\Repositories\AppRepository;
use App\Data\Repositories\FileRepository;
use App\Support\Config;
use App\Support\OpsLock;

Config::load(__DIR__ . '/../.env');

$projectRoot = __DIR__ . '/../';
$dbPath = Config::get('DB_PATH', 'storage/db/router.sqlite');
$dbPath = str_starts_with($dbPath, '/') ? $dbPath : $projectRoot . $dbPath;

$keyStorePath = Config::get('KEK_STORE_PATH', 'storage/keys');
$keyStorePath = str_starts_with($keyStorePath, '/') ? $keyStorePath : $projectRoot . $keyStorePath;

// Rotations and backups must never interleave (a backup could capture the
// new KEK but not all re-wrapped DEKs). Serialize via the shared ops lock.
try {
    OpsLock::acquire(rtrim($projectRoot, '/') . '/storage/ops.lock', 'KEK rotation');
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$pdo = Database::connect($dbPath);
$apps = new AppRepository($pdo);
$files = new FileRepository($pdo);
$keyManager = new KeyManager($keyStorePath);

// Usage: php bin/rotate-kek.php <app_id>
$appId = trim((string) ($argv[1] ?? ''));
if ($appId === '') {
    fwrite(STDERR, 'Usage: php bin/rotate-kek.php <app_id>' . PHP_EOL);
    exit(1);
}

$app = $apps->findById($appId);
if ($app === null) {
    fwrite(STDERR, "App not found: {$appId}" . PHP_EOL);
    exit(1);
}

$oldVersion = (int) $app['kek_version'];
$newVersion = $oldVersion + 1;
$kekRef = (string) $app['kek_ref'];

fwrite(STDOUT, "Rotating KEK for app '{$app['name']}' ({$appId}): v{$oldVersion} -> v{$newVersion}" . PHP_EOL);

// Rotation re-wraps that app's DEKs — only the stored wrapped-DEK
// changes. The encrypted file content in storage is never touched, since
// only the small wrapped-DEK changes, not the ciphertext it protects.
$oldKek = $keyManager->getOrCreateKek($kekRef, $oldVersion);
$newKek = $keyManager->getOrCreateKek($kekRef, $newVersion);

$activeFiles = $files->listAllActiveForApp($appId);
$rewrapped = 0;
$skipped = 0;

// Atomic bulk re-wrap: all per-file DEK re-wraps AND the app's version bump
// commit as one transaction. If any file fails, everything rolls back — no
// file ends up on the new version — so the app is never left in a mixed
// old/new state and the operation can simply be re-run from scratch.
$pdo->beginTransaction();

try {
    foreach ($activeFiles as $file) {
        $fileVersion = (int) $file['kek_version'];

        if ($fileVersion === $newVersion) {
            // Already on the target version somehow — nothing to do.
            $skipped++;
            continue;
        }

        $fileKek = $fileVersion === $oldVersion ? $oldKek : $keyManager->getOrCreateKek($kekRef, $fileVersion);

        $dek = $keyManager->unwrapDek($fileKek, (string) $file['encrypted_dek']);
        $rewrappedDek = $keyManager->wrapDek($newKek, $dek);
        sodium_memzero($dek);

        $files->updateEncryptedDek((string) $file['id'], $rewrappedDek, $newVersion);
        $rewrapped++;
    }
} catch (\Throwable $e) {
    $pdo->rollBack();
    sodium_memzero($oldKek);
    sodium_memzero($newKek);
    fwrite(STDERR, 'Rotation aborted and rolled back — no DEKs were changed: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, 'App remains on kek_version v' . $oldVersion . '. Re-run bin/rotate-kek.php to retry.' . PHP_EOL);
    OpsLock::release();
    exit(1);
}

// Only bump the app's current version once every file has been successfully
// re-wrapped — atomic with the re-wraps above, so the app row and the files
// always move together.
$apps->updateKekVersion($appId, $newVersion);
$pdo->commit();
OpsLock::release();

sodium_memzero($oldKek);
sodium_memzero($newKek);

fwrite(STDOUT, "Done. Re-wrapped: {$rewrapped}, already current: {$skipped}. App now on kek_version {$newVersion}." . PHP_EOL);
fwrite(STDOUT, 'The old key file for v' . $oldVersion . ' was NOT deleted automatically. Purge it after ' . PHP_EOL);
fwrite(STDOUT, 'confirming nothing needs it via a scheduled `php bin/prune-keys.php` or the manual `bin/delete-kek.php`.' . PHP_EOL);
