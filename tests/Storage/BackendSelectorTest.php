<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\BackendSelector;
use PHPUnit\Framework\TestCase;

final class BackendSelectorTest extends TestCase
{
    private BackendSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new BackendSelector();
    }

    public function testOrdersByLeastUsedSpace(): void
    {
        $candidates = [
            ['id' => 'a', 'quota_used_bytes' => 800, 'quota_total_bytes' => 1000, 'priority' => 100],
            ['id' => 'b', 'quota_used_bytes' => 100, 'quota_total_bytes' => 1000, 'priority' => 200],
            ['id' => 'c', 'quota_used_bytes' => 500, 'quota_total_bytes' => 1000, 'priority' => 50],
        ];

        $ordered = $this->selector->order($candidates);
        $ids = array_column($ordered, 'id');

        $this->assertSame(['b', 'c', 'a'], $ids);
    }

    public function testPriorityBreaksTies(): void
    {
        $candidates = [
            ['id' => 'a', 'quota_used_bytes' => 500, 'quota_total_bytes' => 1000, 'priority' => 200],
            ['id' => 'b', 'quota_used_bytes' => 500, 'quota_total_bytes' => 1000, 'priority' => 50],
        ];

        $ordered = $this->selector->order($candidates);
        $this->assertSame('b', $ordered[0]['id']);
    }

    public function testUnknownOrZeroQuotaTreatedAsZeroPercentUsed(): void
    {
        $candidates = [
            ['id' => 'full', 'quota_used_bytes' => 1000, 'quota_total_bytes' => 1000, 'priority' => 1],
            ['id' => 'zero', 'quota_used_bytes' => 0, 'quota_total_bytes' => 0, 'priority' => 2],
        ];

        // 'zero' is 0% used and so must sort before the 100%-full 'full',
        // even though its priority (2) is worse than 'full's (1).
        $ordered = $this->selector->order($candidates);
        $this->assertSame('zero', $ordered[0]['id']);
    }

    public function testEmptyCandidateSetIsUnchanged(): void
    {
        $this->assertSame([], $this->selector->order([]));
    }

    public function testMissingQuotaFieldsDefaultToZeroPercent(): void
    {
        $candidates = [
            ['id' => 'a', 'priority' => 5],
            ['id' => 'b', 'priority' => 1],
        ];

        $ordered = $this->selector->order($candidates);
        $this->assertSame(['b', 'a'], array_column($ordered, 'id'));
    }
}