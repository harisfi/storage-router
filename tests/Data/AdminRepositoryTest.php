<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\Repositories\AdminRepository;
use App\Tests\DatabaseTestCase;

final class AdminRepositoryTest extends DatabaseTestCase
{
    public function testCreateAndFindByUsername(): void
    {
        $repo = new AdminRepository($this->pdo);
        $repo->create('bob', password_hash('supersecretpw123', PASSWORD_DEFAULT));

        $admin = $repo->findByUsername('bob');
        $this->assertNotNull($admin);
        $this->assertSame('bob', $admin['username']);
        $this->assertTrue(password_verify('supersecretpw123', (string) $admin['password_hash']));
    }

    public function testMissingAdminReturnsNull(): void
    {
        $repo = new AdminRepository($this->pdo);
        $this->assertNull($repo->findByUsername('nobody'));
    }
}
