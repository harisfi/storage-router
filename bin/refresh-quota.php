#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Crypto\KeyManager;
use App\Data\Database;
use App\Data\Repositories\FileRepository;
use App\Data\Repositories\StorageBackendRepository;
use App\Storage\GoogleDriveClient;
use App\Storage\GoogleDriveProvider;
use App\Storage\LocalProvider;
use App\Storage\StorageProviderRegistry;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$projectRoot = __DIR__ . '/../';
$dbPath = Config::get('DB_PATH', 'storage/db/router.sqlite');
$dbPath = str_starts_with($dbPath, '/') ? $dbPath : $projectRoot . $dbPath;

$keyStorePath = Config::get('KEK_STORE_PATH', 'storage/keys');
$keyStorePath = str_starts_with($keyStorePath, '/') ? $keyStorePath : $projectRoot . $keyStorePath;

$pdo = Database::connect($dbPath);
$backends = new StorageBackendRepository($pdo);
$files = new FileRepository($pdo);
$keyManager = new KeyManager($keyStorePath);

$googleArgs = [];
foreach (['GOOGLE_OAUTH_TOKEN_URL', 'GOOGLE_USERINFO_URL', 'GOOGLE_DRIVE_API_BASE_URL', 'GOOGLE_DRIVE_UPLOAD_BASE_URL'] as $key) {
    $value = Config::get($key);
    if ($value !== null) {
        $googleArgs[] = $value;
    }
}

$providers = new StorageProviderRegistry([
    'local' => new LocalProvider($files),
    'google_drive' => new GoogleDriveProvider(
        new GoogleDriveClient(...$googleArgs),
        $keyManager,
        Config::get('GOOGLE_OAUTH_CLIENT_ID', ''),
        Config::get('GOOGLE_OAUTH_CLIENT_SECRET', '')
    ),
]);

// Usage: php bin/refresh-quota.php [storage_id]  (omit to refresh all backends)
$targetId = trim((string) ($argv[1] ?? ''));
$targets = $targetId !== '' ? array_filter([$backends->findById($targetId)]) : $backends->listAll();

if ($targets === []) {
    fwrite(STDERR, 'No matching storage backend(s) found.' . PHP_EOL);
    exit(1);
}

foreach ($targets as $backend) {
    $provider = $providers->forBackend($backend);

    try {
        $quota = $provider->getQuota($backend);
    } catch (\Throwable $e) {
        fwrite(STDERR, "Failed to refresh quota for {$backend['id']} ({$backend['label']}): {$e->getMessage()}" . PHP_EOL);
        continue;
    }

    $backends->updateQuota($backend['id'], $quota['used'], $quota['total']);
    fwrite(STDOUT, sprintf(
        "%s (%s): used=%d total=%d%s" . PHP_EOL,
        $backend['id'],
        $backend['label'],
        $quota['used'],
        $quota['total'],
        $quota['total'] === 0 ? ' (uncapped/unknown)' : ''
    ));
}
