<?php

declare(strict_types=1);

namespace App\Data\Repositories;

use PDO;

final class FileRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Unscoped lookup — admin-only use (e.g. the migrate action in
     * src/Admin/Controllers/FileBrowserController.php). Client-facing API
     * code must never call this; it must always go through
 * findByIdForApp() so App A can't reach App B's file.
 */
    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM files WHERE id = :id AND status = 'active'");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Scoped lookup — the API layer must always call this, never a bare
 * findById(), so that App A can never fetch App B's file even if it
 * guesses a valid UUID.
 */
    public function findByIdForApp(string $id, string $appId, ?string $userId = null): ?array
    {
        $sql = "SELECT * FROM files WHERE id = :id AND app_id = :app_id AND status = 'active'";
        $params = [':id' => $id, ':app_id' => $appId];

        if ($userId !== null) {
            $sql .= ' AND user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function listForApp(string $appId, ?string $userId = null, int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT * FROM files WHERE app_id = :app_id AND status = 'active'";
        $params = [':app_id' => $appId];

        if ($userId !== null) {
            $sql .= ' AND user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @param array<string, mixed> $file */
    public function create(array $file): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO files (id, app_id, user_id, storage_id, provider_ref, encrypted_dek,
                                 stream_header, size_bytes, mime_type, checksum_plaintext, status, kek_version)
             VALUES (:id, :app_id, :user_id, :storage_id, :provider_ref, :encrypted_dek,
                     :stream_header, :size_bytes, :mime_type, :checksum, \'active\', :kek_version)'
        );
        $stmt->execute([
            ':id' => $file['id'],
            ':app_id' => $file['app_id'],
            ':user_id' => $file['user_id'] ?? null,
            ':storage_id' => $file['storage_id'],
            ':provider_ref' => $file['provider_ref'],
            ':encrypted_dek' => $file['encrypted_dek'],
            ':stream_header' => $file['stream_header'],
            ':size_bytes' => $file['size_bytes'],
            ':mime_type' => $file['mime_type'],
            ':checksum' => $file['checksum_plaintext'],
            ':kek_version' => $file['kek_version'] ?? 1,
        ]);
    }

    /** All active files for an app — used by bin/rotate-kek.php to re-wrap every DEK under the new key version. */
    public function listAllActiveForApp(string $appId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM files WHERE app_id = :app_id AND status = 'active'");
        $stmt->execute([':app_id' => $appId]);

        return $stmt->fetchAll();
    }

    /** Updates a file's wrapped DEK after re-wrapping it under a new KEK version (rotation). */
    public function updateEncryptedDek(string $fileId, string $encryptedDek, int $kekVersion): void
    {
        $stmt = $this->pdo->prepare('UPDATE files SET encrypted_dek = :dek, kek_version = :v WHERE id = :id');
        $stmt->execute([':dek' => $encryptedDek, ':v' => $kekVersion, ':id' => $fileId]);
    }

    public function markDeleted(string $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE files SET status = 'deleted' WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    /**
     * Sum of active file sizes on a backend, computed from this table —
 * not a filesystem/API scan. Used by LocalProvider::getQuota() and
 * the admin quota display.
 */
    public function sumActiveBytesForStorage(string $storageId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(size_bytes), 0) AS total FROM files WHERE storage_id = :sid AND status = 'active'"
        );
        $stmt->execute([':sid' => $storageId]);

        return (int) $stmt->fetchColumn();
    }

    /** Used to enforce the "only remove a backend with zero indexed files" rule. */
    public function countActiveForStorage(string $storageId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM files WHERE storage_id = :sid AND status = 'active'"
        );
        $stmt->execute([':sid' => $storageId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Unlike countActiveForStorage(), this counts files regardless of
     * status. files.storage_id is `ON DELETE RESTRICT`, which blocks
     * deleting a storage_backends row if ANY file — including a
     * soft-deleted one — still references it, not only active ones. A
     * backend can only ever be truly removed (not just disabled) if this
     * returns 0; otherwise the admin UI should say so rather than let a
     * DELETE fail with a raw constraint error.
     */
    public function countAllForStorage(string $storageId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM files WHERE storage_id = :sid');
        $stmt->execute([':sid' => $storageId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{count: int, bytes: int} */
    public function countAndSumForApp(string $appId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(size_bytes), 0) AS total
             FROM files WHERE app_id = :app_id AND status = 'active'"
        );
        $stmt->execute([':app_id' => $appId]);
        $row = $stmt->fetch();

        return ['count' => (int) $row['cnt'], 'bytes' => (int) $row['total']];
    }

    /** Count of all active files, across every app — used by the dashboard. */
    public function countAllActive(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM files WHERE status = 'active'")->fetchColumn();
    }

    /** Number of ACTIVE files wrapped under a specific KEK version — gates deletion of that key. */
    public function countActiveForAppVersion(string $appId, int $kekVersion): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM files WHERE app_id = :app_id AND status = 'active' AND kek_version = :v"
        );
        $stmt->execute([':app_id' => $appId, ':v' => $kekVersion]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<int> KEK versions still referenced by this app's active files. */
    public function distinctActiveKekVersionsForApp(string $appId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT kek_version FROM files WHERE app_id = :app_id AND status = 'active' ORDER BY kek_version"
        );
        $stmt->execute([':app_id' => $appId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Sum of all active file sizes, across every app — used by the dashboard. */
    public function sumAllActiveBytes(): int
    {
        return (int) $this->pdo->query(
            "SELECT COALESCE(SUM(size_bytes), 0) FROM files WHERE status = 'active'"
        )->fetchColumn();
    }

    /** Count of active files matching the same filters as listForAdmin(). */
    public function countForAdmin(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) FROM files WHERE status = 'active'";
        $params = [];

        foreach (['app_id', 'user_id', 'mime_type'] as $field) {
            if (!empty($filters[$field])) {
                $sql .= " AND {$field} = :{$field}";
                $params[":{$field}"] = $filters[$field];
            }
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Admin file browser: filtered, paginated listing across all
     * apps. $filters may contain app_id, user_id, mime_type (each an exact
     * match, applied only if non-empty).
     *
     * @param array<string, string> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listForAdmin(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT * FROM files WHERE status = 'active'";
        $params = [];

        foreach (['app_id', 'user_id', 'mime_type'] as $field) {
            if (!empty($filters[$field])) {
                $sql .= " AND {$field} = :{$field}";
                $params[":{$field}"] = $filters[$field];
            }
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Relocates a file's ciphertext reference after an admin-triggered
     * cross-backend migration. Encryption is independent of
     * location, so encrypted_dek/stream_header are untouched — only
     * where the ciphertext physically lives changes.
     */
    public function updateLocation(string $fileId, string $newStorageId, string $newProviderRef): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE files SET storage_id = :storage_id, provider_ref = :provider_ref WHERE id = :id'
        );
        $stmt->execute([
            ':storage_id' => $newStorageId,
            ':provider_ref' => $newProviderRef,
            ':id' => $fileId,
        ]);
    }
}
