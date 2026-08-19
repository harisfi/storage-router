<?php

declare(strict_types=1);

namespace App\Admin\Controllers;

use App\Data\Repositories\AdminRepository;
use App\Data\Repositories\AppRepository;
use App\Data\Repositories\AuditLogRepository;
use App\Data\Repositories\FileRepository;
use App\Data\Repositories\StorageBackendRepository;
use App\Storage\StorageProviderRegistry;
use App\Support\Csrf;
use Throwable;

final class FileBrowserController
{
    public function __construct(
        private FileRepository $files,
        private AppRepository $apps,
        private AdminRepository $admins,
        private StorageBackendRepository $backends,
        private StorageProviderRegistry $providers,
        private AuditLogRepository $auditLog
    ) {
    }

    /** @param array<string, mixed> $query */
    public function browse(array $query): void
    {
        $filters = [
            'app_id' => is_string($query['app_id'] ?? null) ? $query['app_id'] : '',
            'user_id' => is_string($query['user_id'] ?? null) ? $query['user_id'] : '',
            'mime_type' => is_string($query['mime_type'] ?? null) ? $query['mime_type'] : '',
        ];

        $files = $this->files->listForAdmin(array_filter($filters));
        $apps = $this->apps->listAll();
        $backends = $this->backends->listAll();
        $csrfToken = Csrf::token();

        $appNames = [];
        foreach ($apps as $app) {
            $appNames[$app['id']] = $app['name'];
        }
        $backendLabels = [];
        foreach ($backends as $backend) {
            $backendLabels[$backend['id']] = $backend['label'];
        }

        $pageTitle = 'Files';
        $content = function () use ($files, $apps, $backends, $appNames, $backendLabels, $filters, $csrfToken): void {
            require __DIR__ . '/../Views/files/browse.php';
        };
        $flash = $_SESSION['_flash'] ?? null;
        $flashError = $_SESSION['_flash_error'] ?? null;
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
        require __DIR__ . '/../Views/layout.php';
    }

    public function errors(): void
    {
        $errors = $this->auditLog->listErrors();

        // Resolve actor ids to readable names: app UUIDs → app names,
        // admin ids → usernames (per the audit log's actor_type). Unknown
        // entries (e.g. a failed-login actor recorded as the submitted
        // username) fall back to showing the raw id.
        $actorNames = [];
        foreach ($errors as $err) {
            $id = (string) $err['actor_id'];
            if (isset($actorNames[$id])) {
                continue;
            }

            if ($err['actor_type'] === 'app') {
                $app = $this->apps->findById($id);
                $actorNames[$id] = $app['name'] ?? $id;
            } elseif ($err['actor_type'] === 'admin') {
                $admin = $this->admins->findById($id);
                $actorNames[$id] = $admin['username'] ?? $id;
            }
        }

        $pageTitle = 'Operational Errors';
        $content = function () use ($errors, $actorNames): void {
            require __DIR__ . '/../Views/files/errors.php';
        };
        require __DIR__ . '/../Views/layout.php';
    }

    /** @param array<string, mixed> $post */
    public function migrate(string $fileId, array $post): void
    {
        if (!Csrf::verify(is_string($post['csrf_token'] ?? null) ? $post['csrf_token'] : null)) {
            $this->redirectWithError('Your session expired, please try again.');
        }

        $targetStorageId = trim((string) ($post['target_storage_id'] ?? ''));
        if ($targetStorageId === '') {
            $this->redirectWithError('Select a target backend.');
        }

        $file = $this->files->findById($fileId);
        if ($file === null) {
            $this->redirectWithError('File not found.');
        }

        $sourceBackend = $this->backends->findById((string) $file['storage_id']);
        $targetBackend = $this->backends->findById($targetStorageId);

        if ($sourceBackend === null || $targetBackend === null) {
            $this->redirectWithError('Source or target backend could not be loaded.');
        }

        if ($sourceBackend['id'] === $targetBackend['id']) {
            $this->redirectWithError('File is already on that backend.');
        }

        $sourceProvider = $this->providers->forBackend($sourceBackend);
        $targetProvider = $this->providers->forBackend($targetBackend);

        // Cross-provider migration needs no decryption — encryption is
        // independent of location, so this just relocates the
        // opaque ciphertext blob. This is also a genuine end-to-end proof
        // that StorageProviderInterface generalizes correctly: this code
        // has no idea whether either side is Local or Drive.
        $buffer = fopen('php://temp/maxmemory:5242880', 'r+b');

        try {
            $sourceProvider->download($sourceBackend, (string) $file['provider_ref'], $buffer);
            rewind($buffer);
            $newProviderRef = $targetProvider->upload($targetBackend, $buffer, (string) $file['id']);
        } catch (Throwable $e) {
            fclose($buffer);
            $this->auditLog->log('admin', $this->currentAdminId(), 'file.migrate', 'error', $fileId, [
                'reason' => 'migration_failed',
                'errors' => [
                    ['storage_id' => $sourceBackend['id'], 'error' => 'migration_read_or_write_failed'],
                ],
            ]);
            $this->redirectWithError('Migration failed — see the errors log for details.');
        }
        fclose($buffer);

        $this->files->updateLocation($fileId, $targetBackend['id'], $newProviderRef);
        $sourceProvider->delete($sourceBackend, (string) $file['provider_ref']);

        $this->auditLog->log('admin', $this->currentAdminId(), 'file.migrate', 'success', $fileId, [
            'from_storage_id' => $sourceBackend['id'],
            'to_storage_id' => $targetBackend['id'],
        ]);

        $_SESSION['_flash'] = htmlspecialchars('File migrated to ' . $targetBackend['label'] . '.', ENT_QUOTES, 'UTF-8');
        header('Location: /admin/files');
        exit;
    }

    private function currentAdminId(): string
    {
        return (string) ($_SESSION['admin_id'] ?? 'unknown');
    }

    private function redirectWithError(string $message): never
    {
        $_SESSION['_flash_error'] = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        header('Location: /admin/files');
        exit;
    }
}
