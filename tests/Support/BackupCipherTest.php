<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\BackupCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackupCipherTest extends TestCase
{
    public function testEncryptDecryptRoundTrip(): void
    {
        $files = [
            'router.sqlite' => str_repeat('sqlite-bytes', 1000),
            'keys/app-a.kek' => base64_encode(random_bytes(32)),
            'keys/app-a.v2.kek' => base64_encode(random_bytes(32)),
        ];

        $blob = BackupCipher::encrypt($files, 'hunter2-secret');
        $restored = BackupCipher::decrypt($blob, 'hunter2-secret');

        $this->assertSame($files, $restored);
    }

    public function testCiphertextDoesNotLeakPlaintextData(): void
    {
        $secret = random_bytes(64);
        $blob = BackupCipher::encrypt(['keys/a.kek' => $secret], 'pw');
        $this->assertStringNotContainsString($secret, $blob);
    }

    public function testWrongPassphraseFails(): void
    {
        $blob = BackupCipher::encrypt(['a' => 'b'], 'right-password');
        $this->expectException(RuntimeException::class);
        BackupCipher::decrypt($blob, 'wrong-password');
    }

    public function testNonBackupFileIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        BackupCipher::decrypt('not-a-backup', 'pw');
    }

    public function testTamperedCiphertextFailsAuthentication(): void
    {
        $blob = BackupCipher::encrypt(['a' => 'b'], 'pw');
        $last = strlen($blob) - 1;
        $blob[$last] = $blob[$last] === 'A' ? 'B' : 'A';

        $this->expectException(RuntimeException::class);
        BackupCipher::decrypt($blob, 'pw');
    }
}