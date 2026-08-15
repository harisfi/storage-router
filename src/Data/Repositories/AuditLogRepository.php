<?php

declare(strict_types=1);

namespace App\Data\Repositories;

use PDO;

final class AuditLogRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed>|null $metadata For status=error, should include a
     *   "reason" and an "errors" array, e.g.:
     *   ['reason' => 'no_storage_available', 'errors' => [['storage_id' => ..., 'error' => ...]]]
     */
    public function log(
        string $actorType,
        string $actorId,
        string $action,
        string $status = 'success',
        ?string $targetId = null,
        ?array $metadata = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (actor_type, actor_id, action, status, target_id, metadata)
             VALUES (:actor_type, :actor_id, :action, :status, :target_id, :metadata)'
        );
        $stmt->execute([
            ':actor_type' => $actorType,
            ':actor_id' => $actorId,
            ':action' => $action,
            ':status' => $status,
            ':target_id' => $targetId,
            ':metadata' => $metadata !== null ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
        ]);
    }

    /** Backs the admin "errors" filter view. */
    public function listErrors(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM audit_log WHERE status = 'error' ORDER BY created_at DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
