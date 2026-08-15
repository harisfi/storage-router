#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Data\Database;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$projectRoot = __DIR__ . '/../';
$dbPath = Config::get('DB_PATH', 'storage/db/router.sqlite');
$dbPath = str_starts_with($dbPath, '/') ? $dbPath : $projectRoot . $dbPath;

$pdo = Database::connect($dbPath);

// Bootstrap the tracking table itself before running any real migrations.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        migration TEXT PRIMARY KEY,
        applied_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
    )"
);

$migrationsDir = __DIR__ . '/../src/Data/Migrations';
$files = glob($migrationsDir . '/*.sql');
sort($files);

$applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

$ranCount = 0;

foreach ($files as $file) {
    $name = basename($file);

    if (in_array($name, $applied, true)) {
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "Could not read {$name}." . PHP_EOL);
        exit(1);
    }

    fwrite(STDOUT, "Applying {$name}..." . PHP_EOL);

    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
        $stmt->execute([':migration' => $name]);
        $pdo->commit();
        $ranCount++;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "Failed applying {$name}: " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

if ($ranCount === 0) {
    fwrite(STDOUT, 'Already up to date.' . PHP_EOL);
} else {
    fwrite(STDOUT, "Applied {$ranCount} migration(s)." . PHP_EOL);
}
