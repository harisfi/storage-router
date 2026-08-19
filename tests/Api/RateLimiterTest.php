<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\Middleware\RateLimiter;
use App\Data\Repositories\AuditLogRepository;
use App\Data\Repositories\RateLimitRepository;
use App\Tests\DatabaseTestCase;

final class RateLimiterTest extends DatabaseTestCase
{
    private RateLimitRepository $repo;
    private AuditLogRepository $auditLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new RateLimitRepository($this->pdo);
        $this->auditLog = new AuditLogRepository($this->pdo);
    }

    public function testWindowCounterIncrementsPerAppAndEndpoint(): void
    {
        $window = 1000;

        $this->assertSame(1, $this->repo->incrementAndGet('app-1', 'upload', $window));
        $this->assertSame(2, $this->repo->incrementAndGet('app-1', 'upload', $window));
        $this->assertSame(3, $this->repo->incrementAndGet('app-1', 'upload', $window));

        // A different app and a different window start their own counters.
        $this->assertSame(1, $this->repo->incrementAndGet('app-2', 'upload', $window));
        $this->assertSame(1, $this->repo->incrementAndGet('app-1', 'upload', $window + 60));
    }

    public function testDistinctEndpointIsCountedSeparately(): void
    {
        $window = 500;
        $this->assertSame(1, $this->repo->incrementAndGet('app-1', 'upload', $window));
        $this->assertSame(1, $this->repo->incrementAndGet('app-1', 'files', $window));
    }

    public function testPruneRemovesOldWindowsOnly(): void
    {
        $this->repo->incrementAndGet('app-1', 'upload', 100);
        $this->repo->incrementAndGet('app-1', 'upload', 200);

        $this->repo->pruneOlderThan(150);

        $remaining = (int) $this->pdo->query('SELECT COUNT(*) FROM rate_limits')->fetchColumn();
        $this->assertSame(1, $remaining);
    }

    public function testZeroLimitDisablesEnforcement(): void
    {
        $limiter = new RateLimiter($this->repo, $this->auditLog, 0, 120);

        // limit=0 disables, so enforce returns without throwing or exiting.
        $limiter->enforce('app-1', 'upload');
        $this->assertTrue(true);
    }

    public function testEnforcePassesThroughWhenUnderLimit(): void
    {
        $limiter = new RateLimiter($this->repo, $this->auditLog, 3, 120);

        $limiter->enforce('app-1', 'upload');
        $limiter->enforce('app-1', 'upload');
        $limiter->enforce('app-1', 'upload');

        // Three calls at a limit of 3 are allowed; none of them exited.
        $this->assertTrue(true);
    }
}