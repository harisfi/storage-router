<?php

declare(strict_types=1);

namespace App\Tests\Crypto;

use App\Crypto\KeyManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KeyManagerTest extends TestCase
{
    private string $store;

    protected function setUp(): void
    {
        $this->store = sys_get_temp_dir() . '/storage-router-keys-' . bin2hex(random_bytes(6));
        mkdir($this->store, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->store . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->store);
    }

    public function testCreatesKeyLazilyAndPersistsWith0400(): void
    {
        $km = new KeyManager($this->store);
        $key = $km->getOrCreateKek('app-1');

        $this->assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($key));
        $this->assertFileExists($this->store . '/app-1.kek');
        $this->assertSame(0400, fileperms($this->store . '/app-1.kek') & 0777);

        // A second call returns the same persisted key, not a new one.
        $this->assertSame($key, $km->getOrCreateKek('app-1'));
    }

    public function testSeparateAppsGetSeparateKeys(): void
    {
        $km = new KeyManager($this->store);
        $this->assertNotSame($km->getOrCreateKek('app-1'), $km->getOrCreateKek('app-2'));
    }

    public function testWrapUnwrapRoundTrip(): void
    {
        $km = new KeyManager($this->store);
        $kek = $km->getOrCreateKek('app-1');
        $dek = random_bytes(32);

        $wrapped = $km->wrapDek($kek, $dek);
        $this->assertNotSame($dek, $wrapped);
        $this->assertSame($dek, $km->unwrapDek($kek, $wrapped));
    }

    public function testUnwrapWithWrongKekFails(): void
    {
        $km = new KeyManager($this->store);
        $wrapped = $km->wrapDek($km->getOrCreateKek('app-1'), 'data');
        $wrongKek = $km->getOrCreateKek('app-2');

        $this->expectException(RuntimeException::class);
        $km->unwrapDek($wrongKek, $wrapped);
    }

    public function testUnwrapWithCorruptedDataFails(): void
    {
        $km = new KeyManager($this->store);
        $kek = $km->getOrCreateKek('app-1');
        $wrapped = $km->wrapDek($kek, 'data');

        // Flip a byte inside the base64 payload.
        $tampered = $wrapped;
        $last = strlen($tampered) - 1;
        $tampered[$last] = $tampered[$last] === 'A' ? 'B' : 'A';

        $this->expectException(RuntimeException::class);
        $km->unwrapDek($kek, $tampered);
    }

    public function testRotationCreatesVersionedFileWithoutBreakingOldVersion(): void
    {
        $km = new KeyManager($this->store);
        $v1 = $km->getOrCreateKek('app-1', 1);
        $v2 = $km->getOrCreateKek('app-1', 2);

        $this->assertNotSame($v1, $v2);
        $this->assertFileExists($this->store . '/app-1.kek');
        $this->assertFileExists($this->store . '/app-1.v2.kek');
        $this->assertSame($v1, $km->getOrCreateKek('app-1', 1));
        $this->assertSame($v2, $km->getOrCreateKek('app-1', 2));
    }

    public function testRejectsPathTraversalInKekRef(): void
    {
        $km = new KeyManager($this->store);

        $this->expectException(RuntimeException::class);
        $km->getOrCreateKek('../../etc/passwd');
    }
}
