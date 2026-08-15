#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Data\Database;
use App\Data\Repositories\AppRepository;
use App\Support\Config;
use App\Support\UuidGenerator;

Config::load(__DIR__ . '/../.env');

$projectRoot = __DIR__ . '/../';
$dbPath = Config::get('DB_PATH', 'storage/db/router.sqlite');
$dbPath = str_starts_with($dbPath, '/') ? $dbPath : $projectRoot . $dbPath;

$pdo = Database::connect($dbPath);
$apps = new AppRepository($pdo);

$name = trim((string) ($argv[1] ?? ''));
if ($name === '') {
    fwrite(STDOUT, 'App name: ');
    $name = trim((string) fgets(STDIN));
}

if ($name === '') {
    fwrite(STDERR, 'App name is required.' . PHP_EOL);
    exit(1);
}

$appId = UuidGenerator::generate();
$rawKey = bin2hex(random_bytes(32)); // 64 hex chars, high entropy — no bcrypt needed for lookup key material
$hash = hash('sha256', $rawKey);

$kekRef = $appId;

$apps->create($appId, $name, $hash, $kekRef);

fwrite(STDOUT, 'App created.' . PHP_EOL);
fwrite(STDOUT, "  app_id:  {$appId}" . PHP_EOL);
fwrite(STDOUT, "  API key: {$rawKey}" . PHP_EOL);
fwrite(STDOUT, PHP_EOL);
fwrite(STDOUT, 'Save the API key now — only its SHA-256 hash is stored, it cannot be retrieved again.' . PHP_EOL);
fwrite(STDOUT, "Send it as: X-API-Key: {$rawKey}" . PHP_EOL);
