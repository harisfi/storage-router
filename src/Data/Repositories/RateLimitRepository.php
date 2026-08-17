<?php

declare(strict_types=1);

namespace App\Data\Repositories;

use PDO;

final class RateLimitRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Atomically increments the counter for this app/endpoint/window and
     * returns the count AFTER incrementing. Uses SQLite's UPSERT so a
     * concurrent request racing into the same window still gets a
     * correct, serialized count rather than a lost update.
     */
    public function incrementAndGet(string $appId, string $endpoint, int $windowStart): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rate_limits (app_id, endpoint, window_start, count)
             VALUES (:app_id, :endpoint, :window_start, 1)
             ON CONFLICT(app_id, endpoint, window_start) DO UPDATE SET count = count + 1'
        );
        $stmt->execute([
            ':app_id' => $appId,
            ':endpoint' => $endpoint,
            ':window_start' => $windowStart,
        ]);

        $select = $this->pdo->prepare(
            'SELECT count FROM rate_limits WHERE app_id = :app_id AND endpoint = :endpoint AND window_start = :window_start'
        );
        $select->execute([
            ':app_id' => $appId,
            ':endpoint' => $endpoint,
            ':window_start' => $windowStart,
        ]);

        return (int) $select->fetchColumn();
    }

    /** Deletes buckets older than $olderThanWindowStart — call occasionally to keep the table small. */
    public function pruneOlderThan(int $olderThanWindowStart): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rate_limits WHERE window_start < :cutoff');
        $stmt->execute([':cutoff' => $olderThanWindowStart]);
    }
}
