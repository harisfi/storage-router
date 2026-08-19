#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Support\BackupCipher;

// Usage:
//   php bin/restore-backup.php <backup.enc> <restore_dir>
//
// Passphrase: BACKUP_PASSWORD env or an interactive prompt.
if (count($argv) < 3) {
    fwrite(STDERR, "Usage: php bin/restore-backup.php <backup.enc> <restore_dir>" . PHP_EOL);
    exit(1);
}

$encPath = $argv[1];
$restoreDir = rtrim($argv[2], '/');

if (!is_file($encPath)) {
    fwrite(STDERR, "Backup file not found: {$encPath}" . PHP_EOL);
    exit(1);
}

$passphrase = (string) (getenv('BACKUP_PASSWORD') ?: '');
if ($passphrase === '') {
    echo 'Enter the backup passphrase: ';
    $passphrase = rtrim((string) fgets(STDIN), "\r\n");
    if ($passphrase === '') {
        fwrite(STDERR, 'A non-empty passphrase is required.' . PHP_EOL);
        exit(1);
    }
}

if (!is_dir($restoreDir) && !mkdir($restoreDir, 0700, true) && !is_dir($restoreDir)) {
    fwrite(STDERR, "Could not create restore directory: {$restoreDir}" . PHP_EOL);
    exit(1);
}

$blob = (string) file_get_contents($encPath);

try {
    $files = BackupCipher::decrypt($blob, $passphrase);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Restore failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
sodium_memzero($passphrase);

foreach ($files as $name => $content) {
    $target = $restoreDir . '/' . $name;
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        fwrite(STDERR, "Could not create directory: {$dir}" . PHP_EOL);
        exit(1);
    }
    if (file_put_contents($target, $content) === false) {
        fwrite(STDERR, "Failed to write: {$target}" . PHP_EOL);
        exit(1);
    }
    // Key files are secrets — restore with 0400 to match the live store.
    if (str_starts_with($name, 'keys/')) {
        chmod($target, 0400);
    }
    fwrite(STDOUT, "Restored: {$target}" . PHP_EOL);
}

fwrite(STDOUT, PHP_EOL);
fwrite(STDOUT, "Restore complete into: {$restoreDir}" . PHP_EOL);
fwrite(STDOUT, 'Move keys/ back into storage/keys/ (0400) and router.sqlite into storage/db/ ' . PHP_EOL);
fwrite(STDOUT, 'before starting the app — never leave both in a web-accessible path.' . PHP_EOL);