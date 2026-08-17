<?php

declare(strict_types=1);

namespace App\Admin\Controllers;

use App\Data\Repositories\AppRepository;
use App\Data\Repositories\AppStorageAccessRepository;
use App\Data\Repositories\AuditLogRepository;
use App\Support\Csrf;

final class AssignmentController
{
    public function __construct(
        private AppRepository $apps,
        private AppStorageAccessRepository $access,
        private AuditLogRepository $auditLog
    ) {
    }

    public function edit(string $appId): void
    {
        $app = $this->apps->findById($appId);
        if ($app === null) {
            $_SESSION['_flash_error'] = 'App not found.';
            header('Location: /admin/apps');
            exit;
        }

        $backends = $this->access->listAllBackendsWithAccessForApp($appId);
        $csrfToken = Csrf::token();

        $pageTitle = 'Assignments — ' . $app['name'];
        $content = function () use ($app, $backends, $csrfToken): void {
            require __DIR__ . '/../Views/assignments/edit.php';
        };
        $flash = $_SESSION['_flash'] ?? null;
        $flashError = $_SESSION['_flash_error'] ?? null;
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
        require __DIR__ . '/../Views/layout.php';
    }

    /** @param array<string, mixed> $post */
    public function save(string $appId, array $post): void
    {
        if (!Csrf::verify(is_string($post['csrf_token'] ?? null) ? $post['csrf_token'] : null)) {
            $_SESSION['_flash_error'] = 'Your session expired, please try again.';
            header('Location: /admin/apps/' . rawurlencode($appId) . '/assignments');
            exit;
        }

        $app = $this->apps->findById($appId);
        if ($app === null) {
            $_SESSION['_flash_error'] = 'App not found.';
            header('Location: /admin/apps');
            exit;
        }

        $submitted = is_array($post['backends'] ?? null) ? $post['backends'] : [];

        foreach ($submitted as $storageId => $fields) {
            $storageId = (string) $storageId;
            $enabled = !empty($fields['enabled']);
            $priority = (int) ($fields['priority'] ?? 100);

            $this->access->setAccess($appId, $storageId, $priority, $enabled);
        }

        $this->auditLog->log('admin', (string) ($_SESSION['admin_id'] ?? 'unknown'), 'app.assignments_updated', 'success', $appId);

        $_SESSION['_flash'] = 'Assignments saved.';
        header('Location: /admin/apps/' . rawurlencode($appId) . '/assignments');
        exit;
    }
}
