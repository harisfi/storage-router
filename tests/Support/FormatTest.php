<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Format;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    public function testHumanBytesNearestUnit(): void
    {
        $this->assertSame('0 B', Format::humanBytes(0));
        $this->assertSame('999 B', Format::humanBytes(999));
        $this->assertSame('1 KB', Format::humanBytes(1024));
        $this->assertSame('211 KB', Format::humanBytes(216064));
        $this->assertSame('512 MB', Format::humanBytes(536870912)); // 0.5 GiB
        $this->assertSame('10 GB', Format::humanBytes(10737418240));
        $this->assertSame('1 TB', Format::humanBytes(1099511627776));
        $this->assertSame('1.5 GB', Format::humanBytes(1610612736));
        $this->assertSame('0 B', Format::humanBytes(-5));
    }

    public function testPercent(): void
    {
        $this->assertSame(21, Format::percent(211, 1024));
        $this->assertSame(2, Format::percent(211, 10240));
        $this->assertSame(100, Format::percent(200, 100));
        $this->assertSame(0, Format::percent(0, 100));
        $this->assertSame(0, Format::percent(5, 0)); // unknown/uncapped
        $this->assertSame(100, Format::percent(120, 100)); // clamped
    }

    public function testActionLabel(): void
    {
        $this->assertSame('Upload rejected', Format::actionLabel('upload.rejected'));
        $this->assertSame('Rate limit exceeded', Format::actionLabel('rate_limit.exceeded'));
        $this->assertSame('Admin login failed', Format::actionLabel('admin.login_failed'));
        $this->assertSame('Quota refresh failed', Format::actionLabel('storage.quota_refresh'));
        $this->assertSame('File migration failed', Format::actionLabel('file.migrate'));
    }

    public function testActionLabelFallsBackToPrettyCodifiedCode(): void
    {
        $this->assertSame('Future.unknown', Format::actionLabel('future.unknown'));
    }

    public function testActorLabel(): void
    {
        $this->assertSame('App · My App', Format::actorLabel('app', 'My App'));
        $this->assertSame('Admin · bob', Format::actorLabel('admin', 'bob'));
        $this->assertSame('Admin · unknown', Format::actorLabel('admin', 'unknown'));
        $this->assertSame('Service · svc-1', Format::actorLabel('service', 'svc-1'));
    }

    public function testStatusLabel(): void
    {
        $this->assertSame('Active', Format::statusLabel('active'));
        $this->assertSame('Suspended', Format::statusLabel('suspended'));
        $this->assertSame('Enabled', Format::statusLabel('enabled'));
        $this->assertSame('Disabled', Format::statusLabel('disabled'));
    }
}