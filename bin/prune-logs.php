#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Data\Database;
use App\Data\Repositories\AuditLogRepository;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$projectRoot = __DIR__ . '/../';
$dbPath = Config::get('DB_PATH', 'storage/db/router.sqlite');
$dbPath = str_starts_with($dbPath, '/') ? $dbPath : $projectRoot . $dbPath;

// Usage: php bin/prune-logs.php [days]   (default from retention policy, 30)
$days = isset($argv[1]) ? (int) $argv[1] : AuditLogRepository::DEFAULT_RETENTION_DAYS;
if ($days < 1) {
    fwrite(STDERR, "Days must be >= 1." . PHP_EOL);
    exit(1);
}

$pdo = Database::connect($dbPath);
$audit = new AuditLogRepository($pdo);

try {
    $pruned = $audit->pruneOlderThan($days);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Audit log prune failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Pruned {$pruned} audit-log row(s) older than {$days} days." . PHP_EOL);

// Deterministic scheduled path; exits zero on success so a cron/monitor can
// alert on failure. (The repository also self-prunes probabilistically on
// writes, so retention is enforced even if this never runs.)
exit(0);