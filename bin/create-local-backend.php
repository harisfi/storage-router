#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Data\Database;
use App\Data\Repositories\AppRepository;
use App\Data\Repositories\AppStorageAccessRepository;
use App\Data\Repositories\StorageBackendRepository;
use App\Support\Config;
use App\Support\UuidGenerator;

Config::load(__DIR__ . '/../.env');

$projectRoot = __DIR__ . '/../';
$dbPath = Config::get('DB_PATH', 'storage/db/router.sqlite');
$dbPath = str_starts_with($dbPath, '/') ? $dbPath : $projectRoot . $dbPath;

$pdo = Database::connect($dbPath);
$backends = new StorageBackendRepository($pdo);
$access = new AppStorageAccessRepository($pdo);
$apps = new AppRepository($pdo);

// Usage: php bin/create-local-backend.php "Label" <capacity_cap_bytes> [app_id]
$label = trim((string) ($argv[1] ?? 'Local backend'));
$capacityCapBytes = (int) ($argv[2] ?? 5 * 1024 * 1024 * 1024); // default 5GB cap
$appId = trim((string) ($argv[3] ?? ''));

$storageId = UuidGenerator::generate();
$basePath = rtrim($projectRoot, '/') . '/storage/local-backends/' . $storageId;

if (!is_dir($basePath) && !mkdir($basePath, 0700, true) && !is_dir($basePath)) {
    fwrite(STDERR, "Could not create base path: {$basePath}" . PHP_EOL);
    exit(1);
}

$backends->create($storageId, $label, 'local', [
    'base_path' => $basePath,
    'capacity_cap_bytes' => $capacityCapBytes,
], $capacityCapBytes);

fwrite(STDOUT, 'Local backend created.' . PHP_EOL);
fwrite(STDOUT, "  storage_id: {$storageId}" . PHP_EOL);
fwrite(STDOUT, "  base_path:  {$basePath}" . PHP_EOL);
fwrite(STDOUT, "  cap:        {$capacityCapBytes} bytes" . PHP_EOL);

if ($appId !== '') {
    $app = $apps->findById($appId);
    if ($app === null) {
        fwrite(STDERR, "Warning: app '{$appId}' not found — backend created but not assigned." . PHP_EOL);
    } else {
        $access->setAccess($appId, $storageId, 100, true);
        fwrite(STDOUT, "Assigned to app '{$appId}' (priority 100, enabled)." . PHP_EOL);
    }
} else {
    fwrite(STDOUT, 'Not assigned to any app yet. Re-run with an app_id as the 3rd argument to assign it.' . PHP_EOL);
}
