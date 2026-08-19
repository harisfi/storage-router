<?php

declare(strict_types=1);

namespace App\Data\Repositories;

use PDO;

final class AppRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM apps WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByApiKeyHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM apps WHERE api_key_hash = :hash');
        $stmt->execute([':hash' => $hash]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function listAll(?int $limit = null, int $offset = 0): array
    {
        $sql = 'SELECT * FROM apps ORDER BY created_at DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }

        $stmt = $this->pdo->prepare($sql);
        if ($limit !== null) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM apps')->fetchColumn();
    }

    public function create(string $id, string $name, string $apiKeyHash, string $kekRef): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO apps (id, name, api_key_hash, kek_ref, status)
             VALUES (:id, :name, :hash, :kek_ref, \'active\')'
        );
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':hash' => $apiKeyHash,
            ':kek_ref' => $kekRef,
        ]);
    }

    public function updateStatus(string $id, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE apps SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $id]);
    }

    public function updateApiKeyHash(string $id, string $apiKeyHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE apps SET api_key_hash = :hash WHERE id = :id');
        $stmt->execute([':hash' => $apiKeyHash, ':id' => $id]);
    }

    /** Bumps the app's current KEK version — new uploads wrap under this version going forward. */
    public function updateKekVersion(string $id, int $kekVersion): void
    {
        $stmt = $this->pdo->prepare('UPDATE apps SET kek_version = :v WHERE id = :id');
        $stmt->execute([':v' => $kekVersion, ':id' => $id]);
    }
}
