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

$keyStorePath = Config::get('KEK_STORE_PATH', 'storage/keys');
$keyStorePath = str_starts_with($keyStorePath, '/') ? $keyStorePath : $projectRoot . $keyStorePath;

// Usage: php bin/backup.php [output_dir]  (defaults to storage/backups/<timestamp>)
$timestamp = date('Ymd-His');
$outputDir = trim((string) ($argv[1] ?? ''));
if ($outputDir === '') {
    $outputDir = rtrim($projectRoot, '/') . '/storage/backups/' . $timestamp;
}

if (!is_dir($outputDir) && !mkdir($outputDir, 0700, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Could not create backup output directory: {$outputDir}" . PHP_EOL);
    exit(1);
}

// --- Database: VACUUM INTO gives a single-file, transactionally
// consistent snapshot without needing to stop the app or hold a long
// lock — the SQLite-recommended way to back up a live database (as
// opposed to a raw file copy, which can capture a torn/inconsistent
// state if a write is in progress at the exact moment of copying).
$pdo = Database::connect($dbPath);
$dbBackupPath = $outputDir . '/router.sqlite';

try {
    $quoted = $pdo->quote($dbBackupPath);
    $pdo->exec("VACUUM INTO {$quoted}");
    fwrite(STDOUT, "Database backed up to: {$dbBackupPath}" . PHP_EOL);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Database backup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// --- KEK store: every .kek file, current and historical (rotation
// deliberately keeps old versions around — see bin/rotate-kek.php).
// Losing these without a DB backup, or vice versa, makes the other
// half useless — they must be backed up together, and this script
// intentionally does both in one run for exactly that reason.
$keyBackupDir = $outputDir . '/keys';
if (!is_dir($keyBackupDir) && !mkdir($keyBackupDir, 0700, true) && !is_dir($keyBackupDir)) {
    fwrite(STDERR, "Could not create key backup directory: {$keyBackupDir}" . PHP_EOL);
    exit(1);
}

$keyFiles = glob(rtrim($keyStorePath, '/') . '/*.kek') ?: [];
$copied = 0;
foreach ($keyFiles as $keyFile) {
    $dest = $keyBackupDir . '/' . basename($keyFile);
    if (copy($keyFile, $dest)) {
        chmod($dest, 0400);
        $copied++;
    } else {
        fwrite(STDERR, "Failed to copy key file: {$keyFile}" . PHP_EOL);
    }
}

fwrite(STDOUT, "Copied {$copied} KEK file(s) to: {$keyBackupDir}" . PHP_EOL);
fwrite(STDOUT, PHP_EOL);
fwrite(STDOUT, "Backup complete: {$outputDir}" . PHP_EOL);
fwrite(STDOUT, "Store this OFF this server — a backup sitting next to the live deployment" . PHP_EOL);
fwrite(STDOUT, "protects against nothing if the whole server is lost or compromised." . PHP_EOL);
