<?php

declare(strict_types=1);

namespace App\Crypto;

use RuntimeException;

/**
 * Manages per-app Key Encryption Keys (KEKs).
 *
 * v1 minimum bar: KEKs live in a secrets directory
 * outside the web root, one file per app, 0400 permissions. The
 * recommended target (not implemented here) is a cloud KMS with one
 * KMS key per app — swapping this class's internals for a KMS client
 * later should not require changes to EnvelopeEncryptor or the
 * controllers that use it, since they only ever see raw key bytes
 * in and out of this class.
 */
final class KeyManager
{
    public function __construct(private string $keyStorePath)
    {
    }

    /**
     * Loads the KEK for $kekRef at $version, generating and persisting a
     * new one on first use of that (ref, version) pair. Versioning means a
     * rotation never breaks decryption of files wrapped under a prior
     * version — old key files are simply left in place, never overwritten,
     * only ever added to.
     */
    public function getOrCreateKek(string $kekRef, int $version = 1): string
    {
        $path = $this->keyPath($kekRef, $version);

        if (is_file($path)) {
            $key = file_get_contents($path);

            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new RuntimeException("Corrupt or invalid KEK file: {$path}");
            }

            return $key;
        }

        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create key store directory: {$dir}");
        }

        if (file_put_contents($path, $key, LOCK_EX) === false) {
            throw new RuntimeException("Could not write KEK file: {$path}");
        }
        chmod($path, 0400);

        return $key;
    }

    /** Wraps a DEK under the given KEK. Returns base64(nonce || ciphertext). */
    public function wrapDek(string $kek, string $dek): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($dek, $nonce, $kek);

        return base64_encode($nonce . $ciphertext);
    }

    /** Reverses wrapDek(). Throws if the KEK doesn't match or the data is corrupted/tampered. */
    public function unwrapDek(string $kek, string $wrapped): string
    {
        $raw = base64_decode($wrapped, true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Invalid wrapped DEK.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $dek = sodium_crypto_secretbox_open($ciphertext, $nonce, $kek);

        if ($dek === false) {
            throw new RuntimeException('Failed to unwrap DEK — KEK mismatch or corrupted data.');
        }

        return $dek;
    }

    private function keyPath(string $kekRef, int $version = 1): string
    {
        // kek_ref is always a router-generated UUID (the app_id), never
        // client input — but guard against path traversal defensively.
        if (preg_match('/^[A-Za-z0-9\-]+$/', $kekRef) !== 1) {
            throw new RuntimeException('Invalid kek_ref.');
        }

        $suffix = $version > 1 ? ".v{$version}" : '';

        return rtrim($this->keyStorePath, '/') . '/' . $kekRef . $suffix . '.kek';
    }
}
