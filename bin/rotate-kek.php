#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Crypto\KeyManager;
use App\Data\Database;
use App\Data\Repositories\AppRepository;
use App\Data\Repositories\FileRepository;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$projectRoot = __DIR__ . '/../';
$dbPath = Config::get('DB_PATH', 'storage/db/router.sqlite');
$dbPath = str_starts_with($dbPath, '/') ? $dbPath : $projectRoot . $dbPath;

$keyStorePath = Config::get('KEK_STORE_PATH', 'storage/keys');
$keyStorePath = str_starts_with($keyStorePath, '/') ? $keyStorePath : $projectRoot . $keyStorePath;

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
$failed = 0;

foreach ($activeFiles as $file) {
    $fileVersion = (int) $file['kek_version'];

    if ($fileVersion === $newVersion) {
        // Already on the target version somehow (e.g. a re-run) — nothing to do.
        $skipped++;
        continue;
    }

    try {
        // A file might already be behind by more than one rotation if
        // this script wasn't run after a prior bump — always unwrap with
        // the KEK version that actually wrapped ITS DEK, not assumed to
        // be $oldVersion.
        $fileKek = $fileVersion === $oldVersion ? $oldKek : $keyManager->getOrCreateKek($kekRef, $fileVersion);

        $dek = $keyManager->unwrapDek($fileKek, (string) $file['encrypted_dek']);
        $rewrappedDek = $keyManager->wrapDek($newKek, $dek);
        sodium_memzero($dek);

        $files->updateEncryptedDek((string) $file['id'], $rewrappedDek, $newVersion);
        $rewrapped++;
    } catch (\Throwable $e) {
        fwrite(STDERR, "  Failed to re-wrap file {$file['id']}: {$e->getMessage()}" . PHP_EOL);
        $failed++;
    }
}

sodium_memzero($oldKek);
sodium_memzero($newKek);

if ($failed > 0) {
    fwrite(STDERR, "Aborting version bump — {$failed} file(s) failed to re-wrap. App's kek_version left at v{$oldVersion}." . PHP_EOL);
    fwrite(STDERR, "Files that succeeded are already re-wrapped under v{$newVersion} and remain fully readable; re-run this script to retry the rest." . PHP_EOL);
    exit(1);
}

// Only bump the app's current version once every existing file has been
// successfully re-wrapped — otherwise new uploads would use a version
// number whose key exists, but old files could be left inconsistently
// split across versions with no way to tell from the app row alone.
$apps->updateKekVersion($appId, $newVersion);

fwrite(STDOUT, "Done. Re-wrapped: {$rewrapped}, already current: {$skipped}." . PHP_EOL);
fwrite(STDOUT, "App now on kek_version {$newVersion}. The old key file for v{$oldVersion} was NOT deleted automatically." . PHP_EOL);
fwrite(STDOUT, "Once you've confirmed everything works, deleting it is a deliberate, separate, manual step —" . PHP_EOL);
fwrite(STDOUT, "matching the requirement that KEK deletion require explicit admin confirmation, not an automated one." . PHP_EOL);
