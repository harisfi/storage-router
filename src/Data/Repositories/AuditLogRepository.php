<?php

declare(strict_types=1);

namespace App\Data\Repositories;

use PDO;

final class AuditLogRepository
{
    public const DEFAULT_RETENTION_DAYS = 30;

    public function __construct(private PDO $pdo, private int $retentionDays = self::DEFAULT_RETENTION_DAYS)
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

        // Enforce retention without depending on a scheduler: probabilistically
        // prune expired rows (rare, one DELETE per ~1024 writes) so an
        // unbounded audit log can't silently exhaust disk. A scheduled
        // bin/prune-logs.php provides the deterministic, alertable path too.
        if (random_int(1, 1024) === 1) {
            $this->pruneOlderThan($this->retentionDays);
        }
    }

    /**
     * Deletes audit rows older than $days and returns how many were removed.
     * Uses the leading date component of the stored ISO-8601 timestamps so
     * it is format-robust (e.g. trailing 'Z'/timezone).
     */
    public function pruneOlderThan(int $days): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM audit_log WHERE date(substr(created_at, 1, 10)) < date('now', :interval)"
        );
        $stmt->execute([':interval' => '-' . max(0, $days) . ' days']);
        $count = (int) $stmt->fetchColumn();

        if ($count > 0) {
            $del = $this->pdo->prepare(
                "DELETE FROM audit_log WHERE date(substr(created_at, 1, 10)) < date('now', :interval)"
            );
            $del->execute([':interval' => '-' . max(0, $days) . ' days']);
        }

        return $count;
    }

    /** Count of error rows — supports pagination for the errors view. */
    public function countErrors(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE status = 'error'")->fetchColumn();
    }

    /** Backs the admin "errors" filter view. */
    public function listErrors(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM audit_log WHERE status = 'error' ORDER BY created_at DESC LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
