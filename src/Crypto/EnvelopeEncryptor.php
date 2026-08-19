<?php

declare(strict_types=1);

namespace App\Crypto;

use RuntimeException;

/**
 * Streaming AEAD encryption/decryption using libsodium's
 * crypto_secretstream_xchacha20poly1305 construction — chosen specifically
 * because it's designed for exactly this: chunked, tamper-evident,
 * streamable, without needing the whole file in memory.
 *
 * A fresh random nonce/header is generated per file automatically by
 * init_push() — nonce reuse (which would catastrophically break AEAD
 * confidentiality) is not something the caller can get wrong here.
 *
 * Framing: plaintext is split into fixed-size chunks; each becomes a
 * ciphertext chunk of (plaintext_len + ABYTES) bytes, tagged MESSAGE for
 * all but the last chunk, which is tagged FINAL. Decryption reads
 * ciphertext back in matching-size chunks and stops at the FINAL tag —
 * this is the standard libsodium file-encryption recipe.
 *
 * IMPORTANT: fread() is not guaranteed to return the full requested byte
 * count in a single call — this is reliably true for plain local files,
 * but live streams (php://input during an HTTP request, sockets, pipes)
 * routinely return short reads well below the requested length. Chunk
 * boundaries here MUST be exact on both the encrypt and decrypt side (a
 * chunk is one AEAD-authenticated unit), so both directions read via
 * readExactly() below, which loops until the requested length is reached
 * or the stream is genuinely exhausted, rather than trusting a single
 * fread() call.
 */
final class EnvelopeEncryptor
{
    private const CHUNK_SIZE = 65536; // 64 KB of plaintext per chunk

    /**
     * Encrypts $inputStream with a fresh DEK. Returns the raw DEK (the
     * caller is responsible for wrapping it under a KEK via KeyManager and
     * never persisting it unwrapped), the stream header needed to decrypt
     * later, the plaintext size, and its SHA-256 checksum.
     *
     * @param resource $inputStream
     * @param resource $outputStream
     * @return array{dek: string, header: string, size_bytes: int, checksum: string}
     */
    public function encryptStream($inputStream, $outputStream): array
    {
        $dek = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($dek);

        $hashContext = hash_init('sha256');
        $totalBytes = 0;
        $wroteAnyChunk = false;
        $chunk = $this->readExactly($inputStream, self::CHUNK_SIZE);

        while (true) {
            if ($chunk === '' && $wroteAnyChunk) {
                // Exhausted after having written at least one chunk — done.
                break;
            }

            // Decide whether THIS chunk is the final one before writing it.
            // A short read (< CHUNK_SIZE) means the stream is genuinely
            // exhausted, so the chunk is final. A full chunk can still be
            // final if nothing follows it — peek ahead to find out. Without
            // this lookahead, a file whose size is an exact multiple of
            // CHUNK_SIZE would never receive a FINAL tag and could never
            // be decrypted (decryption requires the FINAL tag to terminate).
            if ($chunk === '' && !$wroteAnyChunk) {
                // Empty input: emit one empty FINAL chunk so decryption
                // has a terminating tag.
                $isLast = true;
            } elseif (strlen($chunk) < self::CHUNK_SIZE) {
                $isLast = true;
            } else {
                $next = $this->readExactly($inputStream, self::CHUNK_SIZE);
                $isLast = ($next === '');
            }

            hash_update($hashContext, $chunk);
            $totalBytes += strlen($chunk);

            $tag = $isLast
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

            $cipherChunk = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
            fwrite($outputStream, $cipherChunk);
            $wroteAnyChunk = true;

            if ($isLast) {
                break;
            }

            $chunk = $next;
        }

        return [
            'dek' => $dek,
            'header' => $header,
            'size_bytes' => $totalBytes,
            'checksum' => hash_final($hashContext),
        ];
    }

    /**
     * Decrypts $inputStream (as produced by encryptStream) to $outputStream.
     *
     * AEAD authentication is checked per chunk BEFORE that chunk is written
     * to $outputStream — so if the ciphertext was tampered with or
     * corrupted, this throws before any corrupted plaintext is ever
     * forwarded downstream, rather than after.
     *
     * @param resource $inputStream
     * @param resource $outputStream
     * @return array{size_bytes: int, checksum: string}
     */
    public function decryptStream(string $dek, string $header, $inputStream, $outputStream): array
    {
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $dek);

        $hashContext = hash_init('sha256');
        $totalBytes = 0;
        $sawFinal = false;
        $readChunkSize = self::CHUNK_SIZE + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

        while (true) {
            $cipherChunk = $this->readExactly($inputStream, $readChunkSize);

            if ($cipherChunk === '') {
                break;
            }

            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipherChunk);

            if ($result === false) {
                throw new RuntimeException('Ciphertext authentication failed — file is corrupted or was tampered with.');
            }

            [$plainChunk, $tag] = $result;

            fwrite($outputStream, $plainChunk);
            hash_update($hashContext, $plainChunk);
            $totalBytes += strlen($plainChunk);

            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                $sawFinal = true;
                break;
            }
        }

        if (!$sawFinal) {
            throw new RuntimeException('Ciphertext stream ended unexpectedly (missing final chunk).');
        }

        // Wipe this copy of the plaintext DEK regardless of whether the
        // caller also does, so the key's in-memory lifetime is minimized
        // at this layer too. (PHP passes strings by value, so the caller's
        // own variable is separate and must be wiped there as well.)
        sodium_memzero($dek);

        return [
            'size_bytes' => $totalBytes,
            'checksum' => hash_final($hashContext),
        ];
    }

    /**
     * Reads exactly $length bytes from $stream, looping across multiple
     * fread() calls as needed. Returns fewer than $length bytes only when
     * the stream is genuinely exhausted (EOF) — never merely because one
     * underlying fread() call returned a short read.
     *
     * @param resource $stream
     */
    private function readExactly($stream, int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            if (feof($stream)) {
                break;
            }

            $remaining = $length - strlen($buffer);
            $chunk = fread($stream, $remaining);

            if ($chunk === false) {
                throw new RuntimeException('Failed reading from stream.');
            }

            if ($chunk === '') {
                // No data this call and not yet EOF per feof() above —
                // avoid spinning forever on a stream that never reports
                // EOF; treat as exhausted.
                break;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }
}
