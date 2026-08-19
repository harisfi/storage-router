<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\Repositories\AppRepository;
use App\Data\Repositories\FileRepository;
use App\Data\Repositories\StorageBackendRepository;
use App\Tests\DatabaseTestCase;

final class FileRepositoryTest extends DatabaseTestCase
{
    private ?string $lastFileId = null;

    protected function setUp(): void
    {
        parent::setUp();

        (new AppRepository($this->pdo))->create('app-1', 'App One', hash('sha256', 'app-1'), 'app-1');
        (new AppRepository($this->pdo))->create('app-2', 'App Two', hash('sha256', 'app-2'), 'app-2');
        (new StorageBackendRepository($this->pdo))->create('backend-1', 'Local', 'local', ['base_path' => '/tmp/x']);
    }

    private function createFile(array $overrides = []): string
    {
        $file = array_merge([
            'id' => 'file-' . bin2hex(random_bytes(6)),
            'app_id' => 'app-1',
            'user_id' => null,
            'storage_id' => 'backend-1',
            'provider_ref' => 'ab/ref',
            'encrypted_dek' => base64_encode('wrapped'),
            'stream_header' => base64_encode('header'),
            'size_bytes' => 100,
            'mime_type' => 'text/plain',
            'checksum_plaintext' => 'abc',
        ], $overrides);

        (new FileRepository($this->pdo))->create($file);

        $this->lastFileId = $file['id'];

        return $file['id'];
    }

    public function testScopedLookupEnforcesAppIsolation(): void
    {
        $id = $this->createFile();
        $repo = new FileRepository($this->pdo);

        $this->assertNotNull($repo->findByIdForApp($id, 'app-1'));
        $this->assertNull($repo->findByIdForApp($id, 'app-2'));
    }

    public function testScopedLookupFiltersByUserId(): void
    {
        $id = $this->createFile(['user_id' => 'user-1']);
        $repo = new FileRepository($this->pdo);

        $this->assertNotNull($repo->findByIdForApp($id, 'app-1', 'user-1'));
        $this->assertNull($repo->findByIdForApp($id, 'app-1', 'user-2'));
    }

    public function testDeleteSoftlyAndHideFromScopedLookup(): void
    {
        $id = $this->createFile();
        $repo = new FileRepository($this->pdo);

        $repo->markDeleted($id);
        $this->assertNull($repo->findByIdForApp($id, 'app-1'));
    }

    public function testCountAndSumForApp(): void
    {
        $this->createFile(['size_bytes' => 100]);
        $this->createFile(['size_bytes' => 250]);
        $repo = new FileRepository($this->pdo);

        $stats = $repo->countAndSumForApp('app-1');
        $this->assertSame(2, $stats['count']);
        $this->assertSame(350, $stats['bytes']);
    }

    public function testBackendByteAccounting(): void
    {
        $this->createFile(['size_bytes' => 100]);
        $this->createFile(['size_bytes' => 300]);
        $repo = new FileRepository($this->pdo);

        $this->assertSame(400, $repo->sumActiveBytesForStorage('backend-1'));
        $this->assertSame(2, $repo->countActiveForStorage('backend-1'));
        $this->assertSame(2, $repo->countAllForStorage('backend-1'));
    }
}
