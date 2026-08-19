#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Data\Database;
use App\Support\BackupCipher;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$projectRoot = __DIR__ . '/../';
$dbPath = Config::get('DB_PATH', 'storage/db/router.sqlite');
$dbPath = str_starts_with($dbPath, '/') ? $dbPath : $projectRoot . $dbPath;

$keyStorePath = Config::get('KEK_STORE_PATH', 'storage/keys');
$keyStorePath = str_starts_with($keyStorePath, '/') ? $keyStorePath : $projectRoot . $keyStorePath;

// Usage:
//   php bin/backup.php [output_dir] [--encrypt]
$args = array_slice($argv, 1);
$encrypt = in_array('--encrypt', $args, true);
$args = array_values(array_filter($args, static fn ($a) => $a !== '--encrypt'));

// Passphrase: BACKUP_PASSWORD env (e.g. cron) or an interactive prompt.
$passphrase = (string) (getenv('BACKUP_PASSWORD') ?: '');

$timestamp = date('Ymd-His');
$outputDir = trim((string) ($args[0] ?? ''));
if ($outputDir === '') {
    $outputDir = rtrim($projectRoot, '/') . '/storage/backups/' . $timestamp;
}

if ($encrypt && $passphrase === '') {
    echo 'Enter a passphrase to encrypt the backup: ';
    $passphrase = (string) fgets(STDIN);
    $passphrase = rtrim($passphrase, "\r\n");
    if ($passphrase === '') {
        fwrite(STDERR, 'A non-empty passphrase is required for --encrypt.' . PHP_EOL);
        exit(1);
    }
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

if ($encrypt) {
    // Collapse DB + KEKs into a single passphrase-encrypted artifact and
    // remove the plaintext intermediate files — so the backup is itself a
    // secret, not a plain archive that combines the two decryptable halves.
    $files = [basename($dbBackupPath) => (string) file_get_contents($dbBackupPath)];
    foreach ($keyFiles as $keyFile) {
        $files['keys/' . basename($keyFile)] = (string) file_get_contents($keyFile);
    }

    $encArtifact = $outputDir . '.backup.enc';
    file_put_contents($encArtifact, BackupCipher::encrypt($files, $passphrase));
    chmod($encArtifact, 0600);

    foreach (array_keys($files) as $name) {
        $plainPath = $outputDir . '/' . $name;
        if (is_file($plainPath)) {
            unlink($plainPath);
        }
    }
    @rmdir($keyBackupDir);

    fwrite(STDOUT, 'Backup written (encrypted): ' . $encArtifact . PHP_EOL);
} else {
    fwrite(STDOUT, 'Backup written (plaintext): ' . $outputDir . PHP_EOL);
}

fwrite(STDOUT, PHP_EOL);
fwrite(STDOUT, 'Store this OFF this server — a backup sitting next to the live deployment' . PHP_EOL);
fwrite(STDOUT, 'protects against nothing if the whole server is lost or compromised.' . PHP_EOL);

if ($encrypt) {
    fwrite(STDOUT, 'This artifact contains BOTH the database and the KEKs — keep the passphrase' . PHP_EOL);
    fwrite(STDOUT, 'separate from the file, or a stolen backup still decrypts everything.' . PHP_EOL);
} else {
    fwrite(STDOUT, 'Plaintext mode combines the DB + KEKs: archive it separately from any' . PHP_EOL);
    fwrite(STDOUT, 'copy of storage/ and protect it as a secret (or re-run with --encrypt).' . PHP_EOL);
}
