<?php

declare(strict_types=1);

namespace App\Admin\Controllers;

use App\Data\Repositories\AppRepository;
use App\Data\Repositories\AuditLogRepository;
use App\Data\Repositories\FileRepository;
use App\Data\Repositories\StorageBackendRepository;

/**
 * Computes the numeric stats rendered as cards on the dashboard. All values
 * are derived from the metadata DB on each request — the dashboard is a
 * point-in-time snapshot, not a cached rollup.
 */
final class DashboardController
{
    public function __construct(
        private AppRepository $apps,
        private StorageBackendRepository $backends,
        private FileRepository $files,
        private AuditLogRepository $auditLog
    ) {
    }

    public function show(string $username): void
    {
        $allApps = $this->apps->listAll();
        $allBackends = $this->backends->listAll();

        $activeApps = 0;
        foreach ($allApps as $app) {
            if ($app['status'] === 'active') {
                $activeApps++;
            }
        }

        $enabledBackends = 0;
        $storageUsed = 0;
        $storageTotal = 0;
        foreach ($allBackends as $backend) {
            if ($backend['status'] === 'enabled') {
                $enabledBackends++;
            }
            $storageUsed += (int) $backend['quota_used_bytes'];
            $storageTotal += (int) $backend['quota_total_bytes'];
        }

        $stats = [
            'apps_total' => count($allApps),
            'apps_active' => $activeApps,
            'backends_total' => count($allBackends),
            'backends_enabled' => $enabledBackends,
            'files_count' => $this->files->countAllActive(),
            'files_bytes' => $this->files->sumAllActiveBytes(),
            'errors_count' => $this->auditLog->countErrors(),
            'storage_used' => $storageUsed,
            'storage_total' => $storageTotal,
        ];

        $pageTitle = 'Dashboard';
        $content = function () use ($username, $stats): void {
            require __DIR__ . '/../Views/dashboard.php';
        };
        require __DIR__ . '/../Views/layout.php';
    }
}