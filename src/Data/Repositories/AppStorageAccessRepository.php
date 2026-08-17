<?php

declare(strict_types=1);

namespace App\Data\Repositories;

use PDO;

final class AppStorageAccessRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Backends this app may use, ordered by priority ascending.
     * With $onlyEnabled = true this is exactly the candidate pool the
     * BackendSelector draws from.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForApp(string $appId, bool $onlyEnabled = true): array
    {
        $sql = 'SELECT asa.*, sb.label, sb.provider_type, sb.status AS backend_status,
                       sb.quota_used_bytes, sb.quota_total_bytes
                FROM app_storage_access asa
                JOIN storage_backends sb ON sb.id = asa.storage_id
                WHERE asa.app_id = :app_id';

        if ($onlyEnabled) {
            $sql .= " AND asa.enabled = 1 AND sb.status = 'enabled'";
        }

        $sql .= ' ORDER BY asa.priority ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':app_id' => $appId]);

        return $stmt->fetchAll();
    }

    public function setAccess(string $appId, string $storageId, int $priority, bool $enabled): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_storage_access (app_id, storage_id, priority, enabled)
             VALUES (:app_id, :storage_id, :priority, :enabled)
             ON CONFLICT(app_id, storage_id) DO UPDATE SET
                priority = excluded.priority,
                enabled = excluded.enabled'
        );
        $stmt->execute([
            ':app_id' => $appId,
            ':storage_id' => $storageId,
            ':priority' => $priority,
            ':enabled' => $enabled ? 1 : 0,
        ]);
    }

    public function revoke(string $appId, string $storageId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM app_storage_access WHERE app_id = :app_id AND storage_id = :storage_id'
        );
        $stmt->execute([':app_id' => $appId, ':storage_id' => $storageId]);
    }

    /**
     * All storage backends, each annotated with this app's current
     * access (enabled/priority), defaulting to disabled/priority=100 for
     * a backend the app has no app_storage_access row for yet. Used by
     * the admin assignment screen, which needs to show every
     * backend as a candidate to enable, not just ones already assigned.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAllBackendsWithAccessForApp(string $appId): array
    {
        $sql = 'SELECT sb.id AS storage_id, sb.label, sb.provider_type, sb.status AS backend_status,
                       COALESCE(asa.priority, 100) AS priority,
                       COALESCE(asa.enabled, 0) AS enabled
                FROM storage_backends sb
                LEFT JOIN app_storage_access asa ON asa.storage_id = sb.id AND asa.app_id = :app_id
                ORDER BY sb.created_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':app_id' => $appId]);

        return $stmt->fetchAll();
    }
}
