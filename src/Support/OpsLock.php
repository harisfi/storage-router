<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Advisory process lock (flock) shared by the storage CLI operations —
 * backup, KEK rotation, KEK deletion, and automated key pruning.
 *
 * Purpose: these operations are never safe to run concurrently. A backup
 * that copies KEKs while a rotation is re-wrapping (or about to delete a
 * key file) could capture an inconsistent pairing of DB snapshot and keys.
 * Taking the same exclusive, non-blocking lock at the start of each
 * operation makes the combination mutually exclusive; a concurrent run
 * fails fast with a clear message instead of corrupting the pairing.
 */
final class OpsLock
{
    /** @var resource|null */
    private static $handle = null;

    public static function acquire(string $lockPath, string $owner): void
    {
        $dir = dirname($lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            throw new RuntimeException("Could not open operation lock: {$lockPath}");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException(
                "{$owner} could not acquire the storage operation lock ({$lockPath}) — another " .
                'backup, rotation, or key-purge is in progress. Try again shortly.'
            );
        }

        self::$handle = $handle;

        // Guarantee the lock is released on every exit path, including the
        // early-error exits in the CLI scripts.
        register_shutdown_function(static function (): void {
            OpsLock::release();
        });
    }

    public static function release(): void
    {
        if (self::$handle !== null) {
            flock(self::$handle, LOCK_UN);
            fclose(self::$handle);
            self::$handle = null;
        }
    }
}