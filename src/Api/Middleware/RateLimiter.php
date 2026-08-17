<?php

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Data\Repositories\AuditLogRepository;
use App\Data\Repositories\RateLimitRepository;
use App\Support\ErrorCatalog;

/**
 * Per-app request rate limiting, enforced at the PHP layer via a fixed
 * 60-second window counter in SQLite. This is in addition to, not a
 * replacement for, rate limiting at the reverse-proxy layer (e.g. nginx
 * limit_req) where the host supports it — PHP-layer limiting alone can't
 * protect against raw connection-flood DoS the way a proxy in front of
 * PHP can, but it does stop one app from exhausting shared resources
 * (Drive API quota, disk I/O, DB writes) via excessive legitimate-looking
 * requests.
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
