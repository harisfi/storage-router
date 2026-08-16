#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Data\Database;
use App\Data\Repositories\AppRepository;
use App\Data\Repositories\AppStorageAccessRepository;
use App\Data\Repositories\StorageBackendRepository;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$projectRoot = __DIR__ . '/../';
$dbPath = Config::get('DB_PATH', 'storage/db/router.sqlite');
$dbPath = str_starts_with($dbPath, '/') ? $dbPath : $projectRoot . $dbPath;

$pdo = Database::connect($dbPath);
$apps = new AppRepository($pdo);
$backends = new StorageBackendRepository($pdo);
$access = new AppStorageAccessRepository($pdo);

// Usage: php bin/assign-backend.php <app_id> <storage_id> [priority]
$appId = trim((string) ($argv[1] ?? ''));
$storageId = trim((string) ($argv[2] ?? ''));
$priority = (int) ($argv[3] ?? 100);

if ($appId === '' || $storageId === '') {
    fwrite(STDERR, 'Usage: php bin/assign-backend.php <app_id> <storage_id> [priority]' . PHP_EOL);
    exit(1);
}

if ($apps->findById($appId) === null) {
    fwrite(STDERR, "App not found: {$appId}" . PHP_EOL);
    exit(1);
}

if ($backends->findById($storageId) === null) {
    fwrite(STDERR, "Storage backend not found: {$storageId}" . PHP_EOL);
    exit(1);
}

$access->setAccess($appId, $storageId, $priority, true);

fwrite(STDOUT, "Assigned backend {$storageId} to app {$appId} (priority {$priority}, enabled)." . PHP_EOL);
