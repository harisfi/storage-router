<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Controllers\FileController;
use App\Api\Controllers\UploadController;
use App\Api\Middleware\ApiKeyAuth;
use App\Api\Middleware\RateLimiter;
use App\Crypto\EnvelopeEncryptor;
use App\Crypto\KeyManager;
use App\Data\Repositories\AppRepository;
use App\Data\Repositories\AppStorageAccessRepository;
use App\Data\Repositories\AuditLogRepository;
use App\Data\Repositories\FileRepository;
use App\Data\Repositories\RateLimitRepository;
use App\Data\Repositories\StorageBackendRepository;
use App\Storage\BackendSelector;
use App\Storage\GoogleDriveClient;
use App\Storage\GoogleDriveProvider;
use App\Storage\LocalProvider;
use App\Storage\StorageProviderRegistry;
use App\Support\ErrorCatalog;
use PDO;

/**
 * Front controller router for /api/*.
 *
 * Deliberately a separate class from App\Admin\Router (not a shared router
 * with a role check) — this makes the API/admin isolation requirement
 * structural rather than something that has to be remembered per-route.
 */
final class Router
{
    private ApiKeyAuth $auth;
    private RateLimiter $rateLimiter;
    private UploadController $uploadController;
    private FileController $fileController;

    public function __construct(
        private PDO $pdo,
        string $keyStorePath,
        string $googleClientId,
        string $googleClientSecret,
        int $rateLimitUploadPerMinute = 30,
        int $rateLimitFilesPerMinute = 120,
        int $maxUploadBytes = 104857600,
        int $auditRetentionDays = 30,
        string $googleOauthTokenUrl = 'https://oauth2.googleapis.com/token',
        string $googleUserInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo',
        string $googleDriveApiBaseUrl = 'https://www.googleapis.com/drive/v3',
        string $googleDriveUploadBaseUrl = 'https://www.googleapis.com/upload/drive/v3'
    ) {
        $apps = new AppRepository($pdo);
        $files = new FileRepository($pdo);
        $backends = new StorageBackendRepository($pdo);
        $access = new AppStorageAccessRepository($pdo);
        $auditLog = new AuditLogRepository($pdo, $auditRetentionDays);
        $rateLimits = new RateLimitRepository($pdo);
        $keyManager = new KeyManager($keyStorePath);
        $encryptor = new EnvelopeEncryptor();

        $localProvider = new LocalProvider($files);
        $googleDriveProvider = new GoogleDriveProvider(
            new GoogleDriveClient($googleOauthTokenUrl, $googleUserInfoUrl, $googleDriveApiBaseUrl, $googleDriveUploadBaseUrl),
            $keyManager,
            $googleClientId,
            $googleClientSecret
        );

        $providers = new StorageProviderRegistry([
            'local' => $localProvider,
            'google_drive' => $googleDriveProvider,
        ]);
        $selector = new BackendSelector();

        $this->auth = new ApiKeyAuth($apps);
        $this->rateLimiter = new RateLimiter($rateLimits, $auditLog, $rateLimitUploadPerMinute, $rateLimitFilesPerMinute);
        $this->uploadController = new UploadController(
            $pdo,
            $files,
            $backends,
            $access,
            $auditLog,
            $providers,
            $selector,
            $keyManager,
            $encryptor,
            $maxUploadBytes
        );
        $this->fileController = new FileController($files, $backends, $providers, $keyManager, $encryptor, $auditLog);
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $path = rtrim($path, '/');

        if (preg_match('#^/api/files/([A-Za-z0-9\-]+)$#', $path, $matches) === 1) {
            $app = $this->auth->authenticate();
            $this->rateLimiter->enforce((string) $app['id'], 'files');
            $fileId = $matches[1];

            if ($method === 'GET') {
                $this->fileController->download($app, $fileId);
                return;
            }

            if ($method === 'DELETE') {
                $this->fileController->delete($app, $fileId);
                return;
            }

            ErrorCatalog::respond(405, ErrorCatalog::INVALID_REQUEST, 'Method not allowed.');
        }

        if ($path === '/api/upload' && $method === 'POST') {
            $app = $this->auth->authenticate();
            $this->rateLimiter->enforce((string) $app['id'], 'upload');
            $this->uploadController->upload($app);
            return;
        }

        ErrorCatalog::respond(404, ErrorCatalog::NOT_FOUND, 'Not found.');
    }
}
