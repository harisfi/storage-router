<?php

declare(strict_types=1);

namespace App\Data\Repositories;

use PDO;

final class StorageBackendRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM storage_backends WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->decodeConfig($row);
    }

    /** @return array<int, array<string, mixed>> */
    public function listAll(?int $limit = null, int $offset = 0): array
    {
        $sql = 'SELECT * FROM storage_backends ORDER BY created_at DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }

        $stmt = $this->pdo->prepare($sql);
        if ($limit !== null) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        }
        $stmt->execute();

        return array_map([$this, 'decodeConfig'], $stmt->fetchAll());
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM storage_backends')->fetchColumn();
    }

    /** @return array<int, array<string, mixed>> */
    public function listEnabled(): array
    {
        $rows = $this->pdo
            ->query("SELECT * FROM storage_backends WHERE status = 'enabled' ORDER BY created_at DESC")
            ->fetchAll();

        return array_map([$this, 'decodeConfig'], $rows);
    }

    /** @param array<string, mixed> $providerConfig */
    public function create(string $id, string $label, string $providerType, array $providerConfig, int $quotaTotalBytes = 0): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO storage_backends (id, label, provider_type, provider_config, quota_total_bytes, status)
             VALUES (:id, :label, :type, :config, :quota, \'enabled\')'
        );
        $stmt->execute([
            ':id' => $id,
            ':label' => $label,
            ':type' => $providerType,
            ':config' => json_encode($providerConfig, JSON_THROW_ON_ERROR),
            ':quota' => $quotaTotalBytes,
        ]);
    }

    public function updateStatus(string $id, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE storage_backends SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $id]);
    }

    /** Only safe to call once the caller has confirmed zero files reference this backend. */
    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM storage_backends WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /** Used by LocalProvider::getQuota() (computed from files table) and GoogleDriveProvider (from about.get). */
    public function updateQuota(string $id, int $usedBytes, int $totalBytes): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE storage_backends
             SET quota_used_bytes = :used, quota_total_bytes = :total,
                 last_quota_check_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
             WHERE id = :id"
        );
        $stmt->execute([':used' => $usedBytes, ':total' => $totalBytes, ':id' => $id]);
    }

    /** @param array<string, mixed> $row */
    private function decodeConfig(array $row): array
    {
        $row['provider_config'] = json_decode((string) $row['provider_config'], true, 512, JSON_THROW_ON_ERROR);

        return $row;
    }
}
