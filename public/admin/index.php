<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use App\Admin\Router;
use App\Data\Database;
use App\Support\Config;
use App\Support\ErrorHandler;

// Sanitized error handling first — before anything that handles keys — so
// exceptions never dump plaintext DEKs/KEKs into logs (see ErrorHandler).
ErrorHandler::register(false);

$projectRoot = __DIR__ . '/../../';
Config::load($projectRoot . '.env');

$dbPath = Config::get('DB_PATH', 'storage/db/router.sqlite');
$dbPath = str_starts_with($dbPath, '/') ? $dbPath : $projectRoot . $dbPath;

$keyStorePath = Config::get('KEK_STORE_PATH', 'storage/keys');
$keyStorePath = str_starts_with($keyStorePath, '/') ? $keyStorePath : $projectRoot . $keyStorePath;

$pdo = Database::connect($dbPath);

$routerArgs = [
    'pdo' => $pdo,
    'keyStorePath' => $keyStorePath,
    'projectRoot' => $projectRoot,
    'googleClientId' => Config::get('GOOGLE_OAUTH_CLIENT_ID', ''),
    'googleClientSecret' => Config::get('GOOGLE_OAUTH_CLIENT_SECRET', ''),
    'googleRedirectUri' => Config::get('GOOGLE_OAUTH_REDIRECT_URI', ''),
    'auditRetentionDays' => (int) Config::get('AUDIT_LOG_RETENTION_DAYS', '30'),
];
$envToParam = [
    'GOOGLE_OAUTH_TOKEN_URL' => 'googleOauthTokenUrl',
    'GOOGLE_USERINFO_URL' => 'googleUserInfoUrl',
    'GOOGLE_AUTHORIZE_URL' => 'googleAuthorizeUrl',
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
