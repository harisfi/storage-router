<?php

declare(strict_types=1);

namespace App\Tests\Crypto;

use App\Crypto\EnvelopeEncryptor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvelopeEncryptorTest extends TestCase
{
    private const HEADER_BYTES = 24;
    private const ABYTES = 17;

    private function roundTrip(string $plaintext): array
    {
        $encryptor = new EnvelopeEncryptor();

        $input = fopen('php://temp', 'r+b');
        fwrite($input, $plaintext);
        rewind($input);

        $cipher = fopen('php://temp', 'r+b');
        $result = $encryptor->encryptStream($input, $cipher);
        fclose($input);

        rewind($cipher);
        $cipherBytes = stream_get_contents($cipher);
        rewind($cipher);

        $out = fopen('php://temp', 'r+b');
        $dec = $encryptor->decryptStream($result['dek'], $result['header'], $cipher, $out);
        fclose($cipher);

        rewind($out);
        $decrypted = stream_get_contents($out);
        fclose($out);

        return [
            'plaintext' => $decrypted,
            'encrypt' => $result,
            'decrypt' => $dec,
            'cipher_bytes' => $cipherBytes,
        ];
    }

    public function testRoundTripSmallFile(): void
    {
        $data = 'hello world';
        $r = $this->roundTrip($data);
        $this->assertSame($data, $r['plaintext']);
        $this->assertSame(strlen($data), $r['decrypt']['size_bytes']);
        $this->assertSame($r['encrypt']['checksum'], $r['decrypt']['checksum']);
    }

    public function testRoundTripMultiChunkFile(): void
    {
        $data = random_bytes(200_000); // 200 KB — multiple 64 KB chunks
        $r = $this->roundTrip($data);
        $this->assertSame($data, $r['plaintext']);
        $this->assertSame(200_000, $r['decrypt']['size_bytes']);
    }

    public function testRoundTripExactlyOneChunk(): void
    {
        // Regression: a file of exactly CHUNK_SIZE bytes must still carry a
        // FINAL tag and decrypt successfully.
        $data = random_bytes(65_536);
        $r = $this->roundTrip($data);
        $this->assertSame($data, $r['plaintext']);
        $this->assertSame(65_536, $r['decrypt']['size_bytes']);
    }

    public function testRoundTripZeroByteFile(): void
    {
        $r = $this->roundTrip('');
        $this->assertSame('', $r['plaintext']);
        $this->assertSame(0, $r['decrypt']['size_bytes']);
    }

    public function testOnDiskCiphertextIsOpaque(): void
    {
        $marker = 'PLAINTEXT-SENTINEL-MARKER-7f8e21';
        $data = str_repeat($marker, 20);
        $r = $this->roundTrip($data);

        $this->assertStringNotContainsString($marker, $r['cipher_bytes']);
    }

    public function testBackendBlobSizeForSingleChunk(): void
    {
        // The streaming header is stored separately in the DB (stream_header
        // column), not in the backend blob — so the on-disk ciphertext is
        // exactly plaintext + AEAD overhead (17 bytes/chunk).
        $data = 'a';
        $r = $this->roundTrip($data);

        $this->assertSame(strlen($data) + self::ABYTES, strlen($r['cipher_bytes']));
        $this->assertSame(self::HEADER_BYTES, strlen($r['encrypt']['header']));
    }

    public function testTamperedCiphertextAbortsDownload(): void
    {
        $data = random_bytes(100_000);
        $r = $this->roundTrip($data);

        // Flip a byte inside a ciphertext chunk (after the header), then
        // attempt decryption — it must throw, never serve bad data.
        $bytes = $r['cipher_bytes'];
        $pos = self::HEADER_BYTES + 10;
        $bytes[$pos] = $bytes[$pos] === 'a' ? 'b' : 'a';

        $cipher = fopen('php://temp', 'r+b');
        fwrite($cipher, $bytes);
        rewind($cipher);

        $out = fopen('php://temp', 'r+b');
        $this->expectException(RuntimeException::class);
        (new EnvelopeEncryptor())->decryptStream($r['encrypt']['dek'], $r['encrypt']['header'], $cipher, $out);
    }

    public function testDecryptWithWrongKeyFails(): void
    {
        $data = 'secret';
        $r = $this->roundTrip($data);

        $wrongKey = str_repeat("\x00", SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);

        $cipher = fopen('php://temp', 'r+b');
        fwrite($cipher, $r['cipher_bytes']);
        rewind($cipher);

        $out = fopen('php://temp', 'r+b');
        $this->expectException(RuntimeException::class);
        (new EnvelopeEncryptor())->decryptStream($wrongKey, $r['encrypt']['header'], $cipher, $out);
    }
}
