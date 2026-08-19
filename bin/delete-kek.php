#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Data\Database;
use App\Data\Repositories\AppRepository;
use App\Data\Repositories\AuditLogRepository;
use App\Data\Repositories\FileRepository;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$projectRoot = __DIR__ . '/../';
$dbPath = Config::get('DB_PATH', 'storage/db/router.sqlite');
$dbPath = str_starts_with($dbPath, '/') ? $dbPath : $projectRoot . $dbPath;

$keyStorePath = Config::get('KEK_STORE_PATH', 'storage/keys');
$keyStorePath = str_starts_with($keyStorePath, '/') ? $keyStorePath : $projectRoot . $keyStorePath;

// Usage: php bin/delete-kek.php <app_id> <version>
$appId = trim((string) ($argv[1] ?? ''));
$version = (int) ($argv[2] ?? 0);
if ($appId === '' || $version < 1) {
    fwrite(STDERR, 'Usage: php bin/delete-kek.php <app_id> <kek_version>' . PHP_EOL);
    exit(1);
}

$pdo = Database::connect($dbPath);
$apps = new AppRepository($pdo);
$files = new FileRepository($pdo);
$auditLog = new AuditLogRepository($pdo);

$app = $apps->findById($appId);
if ($app === null) {
    fwrite(STDERR, "App not found: {$appId}" . PHP_EOL);
    exit(1);
}

$kekRef = (string) $app['kek_ref'];
$currentVersion = (int) $app['kek_version'];

// A strict policy: only an OBSOLETE version (one below the app's current
// KEK version) may be destroyed — and only if no ACTIVE file is still
// wrapped under it. Deleting a current key would break new uploads;
// deleting a referenced one would make an active file permanently
// undecryptable.
if ($version >= $currentVersion) {
    fwrite(STDERR, "Refusing to delete KEK v{$version}: it is not below the app's current version (v{$currentVersion})." . PHP_EOL);
    exit(1);
}

$referenced = $files->countActiveForAppVersion($appId, $version);
if ($referenced > 0) {
    fwrite(STDERR, "Refusing to delete KEK v{$version}: {$referenced} active file(s) are still wrapped under it. Rotate and re-wrap them first (bin/rotate-kek.php)." . PHP_EOL);
    exit(1);
}

$target = rtrim($keyStorePath, '/') . '/' . $kekRef . $suffix . '.kek'; // {ref}.kek (v1) or {ref}.vN.kek

if (!is_file($target)) {
    fwrite(STDERR, "Key file not found: {$target}" . PHP_EOL);
    exit(1);
}

$auditLog->log('admin', 'cli:delete-kek', 'kek.delete', 'success', $appId, [
    'kek_ref' => $kekRef,
    'kek_version' => $version,
    'reason' => 'obsolete_version_purge',
]);

if (unlink($target)) {
    fwrite(STDOUT, "Deleted historical KEK v{$version} for app '{$app['name']}' ({$appId}): {$target}" . PHP_EOL);
    fwrite(STDOUT, 'No active file referenced this version, so no data is lost. Ensure a recent backup' . PHP_EOL);
    fwrite(STDOUT, 'exists whose DB was produced AFTER the relevant rotation re-wrapped all files.' . PHP_EOL);
    exit(0);
}

fwrite(STDERR, "Failed to delete key file: {$target}" . PHP_EOL);
exit(1);