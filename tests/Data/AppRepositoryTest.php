<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\Repositories\AppRepository;
use App\Tests\DatabaseTestCase;

final class AppRepositoryTest extends DatabaseTestCase
{
    public function testCreateAndFind(): void
    {
        $repo = new AppRepository($this->pdo);
        $repo->create('app-1', 'My App', hash('sha256', 'secret'), 'app-1');

        $app = $repo->findById('app-1');
        $this->assertNotNull($app);
        $this->assertSame('My App', $app['name']);
        $this->assertSame('active', $app['status']);

        $byHash = $repo->findByApiKeyHash(hash('sha256', 'secret'));
        $this->assertNotNull($byHash);
        $this->assertSame('app-1', $byHash['id']);

        $this->assertNull($repo->findByApiKeyHash(hash('sha256', 'wrong')));
    }

    public function testSuspendAndRestore(): void
    {
        $repo = new AppRepository($this->pdo);
        $repo->create('app-1', 'My App', 'h', 'app-1');

        $repo->updateStatus('app-1', 'suspended');
        $this->assertSame('suspended', $repo->findById('app-1')['status']);

        $repo->updateStatus('app-1', 'active');
        $this->assertSame('active', $repo->findById('app-1')['status']);
    }

    public function testKekVersionDefaultsToOneAndUpdates(): void
    {
        $repo = new AppRepository($this->pdo);
        $repo->create('app-1', 'My App', 'h', 'app-1');

        $this->assertSame(1, (int) $repo->findById('app-1')['kek_version']);

        $repo->updateKekVersion('app-1', 3);
        $this->assertSame(3, (int) $repo->findById('app-1')['kek_version']);
    }
}
