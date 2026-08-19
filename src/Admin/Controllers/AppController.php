<?php

declare(strict_types=1);

namespace App\Admin\Controllers;

use App\Data\Repositories\AppRepository;
use App\Data\Repositories\AuditLogRepository;
use App\Data\Repositories\FileRepository;
use App\Support\Csrf;
use App\Support\Pagination;
use App\Support\UuidGenerator;

final class AppController
{
    public function __construct(
        private AppRepository $apps,
        private FileRepository $files,
        private AuditLogRepository $auditLog
    ) {
    }

    public function list(array $query = []): void
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = Pagination::normalizePerPage((int) ($query['per_page'] ?? Pagination::DEFAULT_PER_PAGE));

        $pagination = new Pagination($this->apps->countAll(), $page, $perPage);
        $apps = $this->apps->listAll($pagination->perPage(), $pagination->offset());

        $usageByApp = [];
        foreach ($apps as $app) {
            $usageByApp[$app['id']] = $this->files->countAndSumForApp((string) $app['id']);
        }

        $pageTitle = 'Apps';
        $content = function () use ($apps, $usageByApp, $pagination): void {
            require __DIR__ . '/../Views/apps/list.php';
        };
        $flash = $_SESSION['_flash'] ?? null;
        $flashError = $_SESSION['_flash_error'] ?? null;
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
        require __DIR__ . '/../Views/layout.php';
    }

    public function showNew(): void
    {
        $csrfToken = Csrf::token();
        $pageTitle = 'Create App';
        $content = function () use ($csrfToken): void {
            require __DIR__ . '/../Views/apps/new.php';
        };
        $flash = $_SESSION['_flash'] ?? null;
        $flashError = $_SESSION['_flash_error'] ?? null;
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
        require __DIR__ . '/../Views/layout.php';
    }

    /** @param array<string, mixed> $post */
    public function create(array $post): void
    {
        if (!Csrf::verify(is_string($post['csrf_token'] ?? null) ? $post['csrf_token'] : null)) {
            $this->redirectWithError('/admin/apps/new', 'Your session expired, please try again.');
        }

        $name = trim((string) ($post['name'] ?? ''));
        if ($name === '') {
            $this->redirectWithError('/admin/apps/new', 'App name is required.');
        }

        $appId = UuidGenerator::generate();
        $rawKey = bin2hex(random_bytes(32));
        $hash = hash('sha256', $rawKey);
        $kekRef = $appId; // KeyManager generates the actual key file lazily on first use.

        $this->apps->create($appId, $name, $hash, $kekRef);

        $this->auditLog->log('admin', $this->currentAdminId(), 'app.create', 'success', $appId, ['name' => $name]);

        // Shown exactly once — only the hash is ever persisted.
        $pageTitle = 'App Created';
        $content = function () use ($appId, $rawKey, $name): void {
            require __DIR__ . '/../Views/apps/created.php';
        };
        require __DIR__ . '/../Views/layout.php';
    }

    /** @param array<string, mixed> $post */
    public function suspend(string $appId, array $post): void
    {
        if (!Csrf::verify(is_string($post['csrf_token'] ?? null) ? $post['csrf_token'] : null)) {
            $this->redirectWithError('/admin/apps', 'Your session expired, please try again.');
        }

        $app = $this->apps->findById($appId);
        if ($app === null) {
            $this->redirectWithError('/admin/apps', 'App not found.');
        }

        $newStatus = $app['status'] === 'active' ? 'suspended' : 'active';
        $this->apps->updateStatus($appId, $newStatus);

        $this->auditLog->log('admin', $this->currentAdminId(), 'app.status_change', 'success', $appId, [
            'new_status' => $newStatus,
        ]);

        $this->redirectWithFlash('/admin/apps', "App status changed to {$newStatus}.");
    }

    /** @param array<string, mixed> $post */
    public function rotateKey(string $appId, array $post): void
    {
        if (!Csrf::verify(is_string($post['csrf_token'] ?? null) ? $post['csrf_token'] : null)) {
            $this->redirectWithError('/admin/apps', 'Your session expired, please try again.');
        }

        $app = $this->apps->findById($appId);
        if ($app === null) {
            $this->redirectWithError('/admin/apps', 'App not found.');
        }

        $rawKey = bin2hex(random_bytes(32));
        $hash = hash('sha256', $rawKey);
        $this->apps->updateApiKeyHash($appId, $hash);

        $this->auditLog->log('admin', $this->currentAdminId(), 'app.key_rotated', 'success', $appId);

        $pageTitle = 'API Key Rotated';
        $content = function () use ($appId, $rawKey, $app): void {
            $name = $app['name'];
            require __DIR__ . '/../Views/apps/created.php';
        };
        require __DIR__ . '/../Views/layout.php';
    }

    private function currentAdminId(): string
    {
        return (string) ($_SESSION['admin_id'] ?? 'unknown');
    }

    private function redirectWithFlash(string $location, string $message): never
    {
        $_SESSION['_flash'] = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        header('Location: ' . $location);
        exit;
    }

    private function redirectWithError(string $location, string $message): never
    {
        $_SESSION['_flash_error'] = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        header('Location: ' . $location);
        exit;
    }
}
