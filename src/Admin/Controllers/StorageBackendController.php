<?php

declare(strict_types=1);

namespace App\Admin\Controllers;

use App\Data\Repositories\AuditLogRepository;
use App\Data\Repositories\FileRepository;
use App\Data\Repositories\StorageBackendRepository;
use App\Storage\StorageProviderRegistry;
use App\Support\Csrf;
use App\Support\UuidGenerator;
use Throwable;

final class StorageBackendController
{
    public function __construct(
        private StorageBackendRepository $backends,
        private FileRepository $files,
        private StorageProviderRegistry $providers,
        private AuditLogRepository $auditLog,
        private string $projectRoot
    ) {
    }

    public function list(): void
    {
        $backends = $this->backends->listAll();
        $pageTitle = 'Storage Backends';
        $content = function () use ($backends): void {
            require __DIR__ . '/../Views/backends/list.php';
        };
        $flash = $_SESSION['_flash'] ?? null;
        $flashError = $_SESSION['_flash_error'] ?? null;
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
        require __DIR__ . '/../Views/layout.php';
    }

    public function showAddLocal(): void
    {
        $csrfToken = Csrf::token();
        $pageTitle = 'Add Local Storage Backend';
        $content = function () use ($csrfToken): void {
            require __DIR__ . '/../Views/backends/add-local.php';
        };
        $flash = $_SESSION['_flash'] ?? null;
        $flashError = $_SESSION['_flash_error'] ?? null;
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
        require __DIR__ . '/../Views/layout.php';
    }

    /** @param array<string, mixed> $post */
    public function createLocal(array $post): void
    {
        if (!Csrf::verify(is_string($post['csrf_token'] ?? null) ? $post['csrf_token'] : null)) {
            $this->redirectWithError('/admin/backends/add-local', 'Your session expired, please try again.');
        }

        $label = trim((string) ($post['label'] ?? ''));
        $capacityCapBytes = (int) ($post['capacity_cap_bytes'] ?? 0);

        if ($label === '' || $capacityCapBytes <= 0) {
            $this->redirectWithError('/admin/backends/add-local', 'Label and a positive capacity cap (in bytes) are required.');
        }

        $storageId = UuidGenerator::generate();
        $basePath = rtrim($this->projectRoot, '/') . '/storage/local-backends/' . $storageId;

        if (!is_dir($basePath) && !mkdir($basePath, 0700, true) && !is_dir($basePath)) {
            $this->redirectWithError('/admin/backends/add-local', 'Could not create the backend storage directory on disk.');
        }

        $this->backends->create($storageId, $label, 'local', [
            'base_path' => $basePath,
            'capacity_cap_bytes' => $capacityCapBytes,
        ], $capacityCapBytes);

        $this->auditLog->log('admin', $this->currentAdminId(), 'storage.create', 'success', $storageId, [
            'provider_type' => 'local',
            'label' => $label,
        ]);

        $this->redirectWithFlash('/admin/backends', 'Local backend created.');
    }

    /** @param array<string, mixed> $post */
    public function toggle(string $storageId, array $post): void
    {
        if (!Csrf::verify(is_string($post['csrf_token'] ?? null) ? $post['csrf_token'] : null)) {
            $this->redirectWithError('/admin/backends', 'Your session expired, please try again.');
        }

        $backend = $this->backends->findById($storageId);
        if ($backend === null) {
            $this->redirectWithError('/admin/backends', 'Backend not found.');
        }

        $newStatus = $backend['status'] === 'enabled' ? 'disabled' : 'enabled';
        $this->backends->updateStatus($storageId, $newStatus);

        $this->auditLog->log('admin', $this->currentAdminId(), 'storage.status_change', 'success', $storageId, [
            'new_status' => $newStatus,
        ]);

        $this->redirectWithFlash('/admin/backends', "Backend status changed to {$newStatus}.");
    }

    /** @param array<string, mixed> $post */
    public function refreshQuota(string $storageId, array $post): void
    {
        if (!Csrf::verify(is_string($post['csrf_token'] ?? null) ? $post['csrf_token'] : null)) {
            $this->redirectWithError('/admin/backends', 'Your session expired, please try again.');
        }

        $backend = $this->backends->findById($storageId);
        if ($backend === null) {
            $this->redirectWithError('/admin/backends', 'Backend not found.');
        }

        try {
            $provider = $this->providers->forBackend($backend);
            $quota = $provider->getQuota($backend);
            $this->backends->updateQuota($storageId, $quota['used'], $quota['total']);
        } catch (Throwable $e) {
            $this->auditLog->log('admin', $this->currentAdminId(), 'storage.quota_refresh', 'error', $storageId, [
                'reason' => 'provider_error',
                'errors' => [['storage_id' => $storageId, 'error' => 'quota_refresh_failed']],
            ]);
            $this->redirectWithError('/admin/backends', 'Quota refresh failed for this backend.');
        }

        $this->redirectWithFlash('/admin/backends', 'Quota refreshed.');
    }

    /** @param array<string, mixed> $post */
    public function remove(string $storageId, array $post): void
    {
        if (!Csrf::verify(is_string($post['csrf_token'] ?? null) ? $post['csrf_token'] : null)) {
            $this->redirectWithError('/admin/backends', 'Your session expired, please try again.');
        }

        $backend = $this->backends->findById($storageId);
        if ($backend === null) {
            $this->redirectWithError('/admin/backends', 'Backend not found.');
        }

        if ($this->files->countActiveForStorage($storageId) > 0) {
            $this->redirectWithError('/admin/backends', 'This backend still has active files — migrate or delete them first.');
        }

        // files.storage_id is ON DELETE RESTRICT against ANY reference,
        // including soft-deleted files, not only active ones (a real
        // constraint discovered while building this). If this backend
        // ever held any file, hard removal isn't possible without
        // breaking historical record integrity — disabling is the
        // correct fallback in that case.
        if ($this->files->countAllForStorage($storageId) > 0) {
            $this->redirectWithError(
                '/admin/backends',
                'This backend has historical file records and cannot be permanently removed. Disable it instead — that already excludes it from new uploads.'
            );
        }

        $this->backends->delete($storageId);

        $this->auditLog->log('admin', $this->currentAdminId(), 'storage.delete', 'success', $storageId);

        $this->redirectWithFlash('/admin/backends', 'Backend removed.');
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
