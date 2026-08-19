<?php

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Data\Repositories\AuditLogRepository;
use App\Data\Repositories\RateLimitRepository;
use App\Support\ErrorCatalog;

/**
 * OPT-IN per-app request rate limiting, enforced at the PHP layer via a fixed
 * 60-second window counter in SQLite. It is OFF by default (uploadPerMinute/
 * filesPerMinute > 0 to enable): with a limit <= 0 it returns immediately and
 * performs NO database write. The primary DoS control is the EDGE reverse
 * proxy (Nginx limit_req / CDN) — a PHP/SQLite layer cannot absorb raw
 * connection floods. This in-app limiter exists only as an optional fairness
 * tool for deployments with no proxy, at the cost of single-writer DB writes.
 */
final class RateLimiter
{
    public function __construct(
        private RateLimitRepository $rateLimits,
        private AuditLogRepository $auditLog,
        private int $uploadPerMinute,
        private int $filesPerMinute
    ) {
    }

    /** Exits with 429 if the app has exceeded its limit for this endpoint. */
    public function enforce(string $appId, string $endpoint): void
    {
        $limit = $endpoint === 'upload' ? $this->uploadPerMinute : $this->filesPerMinute;

        if ($limit <= 0) {
            return; // 0 or negative disables the limit for this endpoint
        }

        $windowStart = intdiv(time(), 60) * 60;
        $count = $this->rateLimits->incrementAndGet($appId, $endpoint, $windowStart);

        if ($count > $limit) {
            $this->auditLog->log('app', $appId, 'rate_limit.exceeded', 'error', null, [
                'reason' => 'rate_limited',
                'errors' => [
                    ['endpoint' => $endpoint, 'error' => 'rate_limit_exceeded', 'limit_per_minute' => $limit],
                ],
            ]);

            header('Retry-After: 60');
            ErrorCatalog::respond(429, ErrorCatalog::RATE_LIMITED, 'Rate limit exceeded for this endpoint. Try again shortly.');
        }
    }
}
