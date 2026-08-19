<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use App\Api\Router;
use App\Data\Database;
use App\Support\Config;
use App\Support\ErrorHandler;

// Sanitized error handling first — before anything that handles keys — so
// exceptions never dump plaintext DEKs/KEKs into logs (see ErrorHandler).
ErrorHandler::register(true);

$projectRoot = __DIR__ . '/../../';
Config::load($projectRoot . '.env');

$dbPath = Config::get('DB_PATH', 'storage/db/router.sqlite');
$dbPath = str_starts_with($dbPath, '/') ? $dbPath : $projectRoot . $dbPath;

$keyStorePath = Config::get('KEK_STORE_PATH', 'storage/keys');
$keyStorePath = str_starts_with($keyStorePath, '/') ? $keyStorePath : $projectRoot . $keyStorePath;

$pdo = Database::connect($dbPath);

// GOOGLE_*_URL overrides are optional and only meaningful for testing
// against a fake Drive API server — real deployments should leave them
// unset and get the real Google endpoints (Router's own defaults).
// Named arguments used here specifically so a subset of these being set
// can never misalign with the wrong constructor parameter.
$routerArgs = [
    'pdo' => $pdo,
    'keyStorePath' => $keyStorePath,
    'googleClientId' => Config::get('GOOGLE_OAUTH_CLIENT_ID', ''),
    'googleClientSecret' => Config::get('GOOGLE_OAUTH_CLIENT_SECRET', ''),
    'rateLimitUploadPerMinute' => (int) Config::get('RATE_LIMIT_UPLOAD_PER_MINUTE', '30'),
    'rateLimitFilesPerMinute' => (int) Config::get('RATE_LIMIT_FILES_PER_MINUTE', '120'),
    'maxUploadBytes' => (int) Config::get('MAX_UPLOAD_BYTES', '0'),
];
$envToParam = [
    'GOOGLE_OAUTH_TOKEN_URL' => 'googleOauthTokenUrl',
    'GOOGLE_USERINFO_URL' => 'googleUserInfoUrl',
    'GOOGLE_DRIVE_API_BASE_URL' => 'googleDriveApiBaseUrl',
    'GOOGLE_DRIVE_UPLOAD_BASE_URL' => 'googleDriveUploadBaseUrl',
];
foreach ($envToParam as $envKey => $paramName) {
    $value = Config::get($envKey);
    if ($value !== null) {
        $routerArgs[$paramName] = $value;
    }
}

$router = new Router(...$routerArgs);
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
