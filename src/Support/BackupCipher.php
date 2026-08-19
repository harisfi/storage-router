<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Symmetric encryption for backup artifacts so a stolen backup file is not
 * directly decryptable. A passphrase-derived key (libsodium pwhash) seals a
 * length-prefixed map of filename => content via crypto_secretbox.
 *
 * Purpose-built so the "DB + KEKs together" backup is itself a secret stored
 * under a passphrase, rather than a plain archive that combines the two
 * halves that together can decrypt everything.
 */
final class BackupCipher
{
    private const MAGIC = 'SROUTERBACK';
    private const VERSION = 1;

    /**
     * @param array<string, string> $files filename => contents
     */
    public static function encrypt(array $files, string $passphrase): string
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = sodium_crypto_pwhash(
            32,
            $passphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE
        );

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox(self::pack($files), $nonce, $key);
        sodium_memzero($key);

        return self::MAGIC . chr(self::VERSION) . $salt . $nonce . $ciphertext;
    }

    /**
     * @return array<string, string> filename => contents
     * @throws RuntimeException on a bad magic/version, wrong passphrase, or corruption
     */
    public static function decrypt(string $blob, string $passphrase): array
    {
        $magic = substr($blob, 0, strlen(self::MAGIC));
        if ($magic !== self::MAGIC) {
            throw new RuntimeException('Not a Storage Router backup file.');
        }

        $version = ord($blob[strlen(self::MAGIC)]);
        if ($version !== self::VERSION) {
            throw new RuntimeException('Unsupported backup format version.');
        }

        $offset = strlen(self::MAGIC) + 1;
        $salt = substr($blob, $offset, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $offset += SODIUM_CRYPTO_PWHASH_SALTBYTES;
        $nonce = substr($blob, $offset, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $offset += SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        $ciphertext = substr($blob, $offset);

        $key = sodium_crypto_pwhash(
            32,
            $passphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE
        );

        $payload = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        sodium_memzero($key);

        if ($payload === false) {
            throw new RuntimeException('Incorrect passphrase or corrupted backup.');
        }

        return self::unpack($payload);
    }

    /**
     * Length-prefixed binary pack of filename => contents.
     *
     * @param array<string, string> $files
     */
    private static function pack(array $files): string
    {
        $out = pack('n', count($files));
        foreach ($files as $name => $content) {
            $out .= pack('C', strlen($name)) . $name;
            $out .= pack('Q', strlen($content)) . $content;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function unpack(string $payload): array
    {
        $files = [];
        $offset = 0;
        $count = unpack('n', $payload)[1];
        $offset += 2;

        for ($i = 0; $i < $count; $i++) {
            $nameLength = ord($payload[$offset]);
            $offset += 1;
            $name = substr($payload, $offset, $nameLength);
            $offset += $nameLength;

            $contentLength = unpack('Q', substr($payload, $offset, 8))[1];
            $offset += 8;
            $content = substr($payload, $offset, $contentLength);
            $offset += $contentLength;

            $files[$name] = $content;
        }

        return $files;
    }
}