#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Data\Database;
use App\Data\Repositories\StorageBackendRepository;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$projectRoot = __DIR__ . '/../';
$dbPath = Config::get('DB_PATH', 'storage/db/router.sqlite');
$dbPath = str_starts_with($dbPath, '/') ? $dbPath : $projectRoot . $dbPath;

$pdo = Database::connect($dbPath);
$backends = new StorageBackendRepository($pdo);

foreach ($backends->listAll() as $backend) {
    fwrite(STDOUT, sprintf(
        "%s  [%s]  %-10s  %s  used=%d total=%d%s" . PHP_EOL,
        $backend['id'],
        $backend['status'],
        $backend['provider_type'],
        $backend['label'],
        (int) $backend['quota_used_bytes'],
        (int) $backend['quota_total_bytes'],
        isset($backend['provider_config']['google_account_email']) && $backend['provider_config']['google_account_email'] !== ''
            ? ' (' . $backend['provider_config']['google_account_email'] . ')'
            : ''
    ));
}
