#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Data\Database;
use App\Data\Repositories\AdminRepository;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$projectRoot = __DIR__ . '/../';
$dbPath = Config::get('DB_PATH', 'storage/db/router.sqlite');
$dbPath = str_starts_with($dbPath, '/') ? $dbPath : $projectRoot . $dbPath;

$pdo = Database::connect($dbPath);
$admins = new AdminRepository($pdo);

$username = trim((string) ($argv[1] ?? ''));
if ($username === '') {
    fwrite(STDOUT, 'Username: ');
    $username = trim((string) fgets(STDIN));
}

if ($username === '') {
    fwrite(STDERR, 'Username is required.' . PHP_EOL);
    exit(1);
}

if ($admins->findByUsername($username) !== null) {
    fwrite(STDERR, "An admin with that username already exists." . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Password (min 12 chars): ');

// Hide input where the terminal supports it (Linux/macOS). Falls back to
// visible input on platforms where `stty` isn't available.
$isWindows = stripos(PHP_OS, 'WIN') === 0;
if (!$isWindows) {
    system('stty -echo');
}

$password = trim((string) fgets(STDIN));

if (!$isWindows) {
    system('stty echo');
    fwrite(STDOUT, PHP_EOL);
}

if (strlen($password) < 12) {
    fwrite(STDERR, 'Password must be at least 12 characters.' . PHP_EOL);
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$admins->create($username, $hash);

fwrite(STDOUT, "Admin '{$username}' created." . PHP_EOL);
