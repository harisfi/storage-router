<?php

declare(strict_types=1);

namespace App\Admin;

use App\Admin\Controllers\AppController;
use App\Admin\Controllers\AssignmentController;
use App\Admin\Controllers\AuthController;
use App\Admin\Controllers\DashboardController;
use App\Admin\Controllers\FileBrowserController;
use App\Admin\Controllers\GoogleOAuthController;
use App\Admin\Controllers\StorageBackendController;
use App\Crypto\KeyManager;
use App\Data\Repositories\AdminRepository;
use App\Data\Repositories\AppRepository;
use App\Data\Repositories\AppStorageAccessRepository;
use App\Data\Repositories\AuditLogRepository;
use App\Data\Repositories\FileRepository;
use App\Data\Repositories\StorageBackendRepository;
use App\Storage\GoogleDriveClient;
use App\Storage\GoogleDriveProvider;
use App\Storage\LocalProvider;
use App\Storage\StorageProviderRegistry;
use App\Support\Session;
use PDO;

/**
 * Front controller router for /admin/*.
 *
 * Deliberately a separate class from App\Api\Router (not a shared router
 * with a role check) — this makes the API/admin isolation requirement
 * structural rather than something that has to be remembered per-route.
 */
final class Router
{
    private AuthController $auth;
    private DashboardController $dashboard;
    private GoogleOAuthController $googleOAuth;
    private StorageBackendController $storageBackends;
    private AppController $appsController;
    private AssignmentController $assignments;
    private FileBrowserController $fileBrowser;

    public function __construct(
        private PDO $pdo,
        string $keyStorePath,
        string $projectRoot,
        string $googleClientId,
        string $googleClientSecret,
        string $googleRedirectUri,
        string $googleOauthTokenUrl = 'https://oauth2.googleapis.com/token',
        string $googleUserInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo',
        string $googleAuthorizeUrl = 'https://accounts.google.com/o/oauth2/v2/auth',
        string $googleDriveApiBaseUrl = 'https://www.googleapis.com/drive/v3',
        string $googleDriveUploadBaseUrl = 'https://www.googleapis.com/upload/drive/v3'
    ) {
        Session::start();

        $admins = new AdminRepository($this->pdo);
        $apps = new AppRepository($this->pdo);
        $files = new FileRepository($this->pdo);
        $backends = new StorageBackendRepository($this->pdo);
        $access = new AppStorageAccessRepository($this->pdo);
        $auditLog = new AuditLogRepository($this->pdo);
        $keyManager = new KeyManager($keyStorePath);

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

        $this->auth = new AuthController($admins, $auditLog);
        $this->dashboard = new DashboardController($apps, $backends, $files, $auditLog);
        $this->googleOAuth = new GoogleOAuthController(
            $backends,
            new GoogleDriveClient($googleOauthTokenUrl, $googleUserInfoUrl),
            $keyManager,
            $googleClientId,
            $googleClientSecret,
            $googleRedirectUri,
            $googleAuthorizeUrl
        );
        $this->storageBackends = new StorageBackendController($backends, $files, $providers, $auditLog, $projectRoot);
        $this->appsController = new AppController($apps, $files, $auditLog);
        $this->assignments = new AssignmentController($apps, $access, $auditLog);
        $this->fileBrowser = new FileBrowserController($files, $apps, $admins, $backends, $providers, $auditLog);
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $path = rtrim($path, '/');
        if ($path === '' || $path === '/admin') {
            $path = '/admin';
        }

        // --- auth (no requireAuth guard on these) ---
        if ($path === '/admin/login' && $method === 'GET') {
            $this->auth->showLoginForm();
            return;
        }
        if ($path === '/admin/login' && $method === 'POST') {
            $this->auth->handleLogin($_POST);
            return;
        }
        if ($path === '/admin/logout' && $method === 'POST') {
            $this->auth->requireAuth();
            $this->auth->logout();
            return;
        }

        // Every route below requires an authenticated admin session.
        $this->auth->requireAuth();

        // --- google oauth ---
        if ($path === '/admin/storage-backends/google/connect' && $method === 'GET') {
            $this->googleOAuth->redirectToConsent();
            return;
        }
        if ($path === '/admin/storage-backends/google/callback' && $method === 'GET') {
            $this->googleOAuth->handleCallback($_GET);
            return;
        }

        // --- storage backends ---
        if ($path === '/admin/backends' && $method === 'GET') {
            $this->storageBackends->list($_GET);
            return;
        }
        if ($path === '/admin/backends/add-local' && $method === 'GET') {
            $this->storageBackends->showAddLocal();
            return;
        }
        if ($path === '/admin/backends/add-local' && $method === 'POST') {
            $this->storageBackends->createLocal($_POST);
            return;
        }
        if (preg_match('#^/admin/backends/([^/]+)/toggle$#', $path, $m) === 1 && $method === 'POST') {
            $this->storageBackends->toggle($m[1], $_POST);
            return;
        }
        if (preg_match('#^/admin/backends/([^/]+)/refresh-quota$#', $path, $m) === 1 && $method === 'POST') {
            $this->storageBackends->refreshQuota($m[1], $_POST);
            return;
        }
        if (preg_match('#^/admin/backends/([^/]+)/remove$#', $path, $m) === 1 && $method === 'POST') {
            $this->storageBackends->remove($m[1], $_POST);
            return;
        }

        // --- apps ---
        if ($path === '/admin/apps' && $method === 'GET') {
            $this->appsController->list($_GET);
            return;
        }
        if ($path === '/admin/apps/new' && $method === 'GET') {
            $this->appsController->showNew();
            return;
        }
        if ($path === '/admin/apps/new' && $method === 'POST') {
            $this->appsController->create($_POST);
            return;
        }
        if (preg_match('#^/admin/apps/([^/]+)/suspend$#', $path, $m) === 1 && $method === 'POST') {
            $this->appsController->suspend($m[1], $_POST);
            return;
        }
        if (preg_match('#^/admin/apps/([^/]+)/rotate-key$#', $path, $m) === 1 && $method === 'POST') {
            $this->appsController->rotateKey($m[1], $_POST);
            return;
        }
        if (preg_match('#^/admin/apps/([^/]+)/assignments$#', $path, $m) === 1 && $method === 'GET') {
            $this->assignments->edit($m[1]);
            return;
        }
        if (preg_match('#^/admin/apps/([^/]+)/assignments$#', $path, $m) === 1 && $method === 'POST') {
            $this->assignments->save($m[1], $_POST);
            return;
        }

        // --- files ---
        if ($path === '/admin/files' && $method === 'GET') {
            $this->fileBrowser->browse($_GET);
            return;
        }
        if ($path === '/admin/files/errors' && $method === 'GET') {
            $this->fileBrowser->errors($_GET);
            return;
        }
        if (preg_match('#^/admin/files/([^/]+)/migrate$#', $path, $m) === 1 && $method === 'POST') {
            $this->fileBrowser->migrate($m[1], $_POST);
            return;
        }

        // --- dashboard ---
        if ($path === '/admin') {
            $username = (string) ($_SESSION['admin_username'] ?? '');
            $this->dashboard->show($username);
            return;
        }

        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found.';
    }
}
