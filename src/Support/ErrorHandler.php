<?php

declare(strict_types=1);

namespace App\Support;

use ErrorException;

/**
 * Sanitizing error/exception handling for the web front controllers.
 *
 * Its purpose is to stop plaintext secrets (DEKs, KEKs, OAuth tokens) from
 * being written into error logs. PHP's default exception logging can include
 * function arguments in a stack trace (controlled by zend.exception_ignore_args,
 * which many hosts leave OFF), so an uncaught error that happens before
 * sodium_memzero() runs could otherwise leak a key. This handler:
 *
 *  1. forces zend.exception_ignore_args=1 and display_errors=0 at runtime;
 *  2. logs uncaught exceptions with a compact, argument-free trace; and
 *  3. redacts key-shaped strings (long base64/hex blobs) from the message.
 *
 * Only the front controllers register it — tests do not, so PHPUnit's own
 * handler is unaffected.
 */
final class ErrorHandler
{
    /** @var list<int> fatal severities worth a shutdown-time log */
    private const FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    /**
     * @param bool $isApi true renders a JSON 500 body (API), false a plain-text one (admin)
     */
    public static function register(bool $isApi): void
    {
        // The handler below is the single logging path for errors — disable
        // PHP's own logging so the raw (unsanitized) fatal line is never
        // written alongside our redacted one.
        ini_set('log_errors', '0');
        ini_set('display_errors', '0');
        ini_set('zend.exception_ignore_args', '1');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            // Honour @-suppression and the configured error_reporting mask.
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (\Throwable $e) use ($isApi): void {
            self::log($e);

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: ' . ($isApi ? 'application/json; charset=utf-8' : 'text/plain; charset=utf-8'));
                echo $isApi
                    ? json_encode(['error' => 'internal_error', 'message' => 'An unexpected error occurred.'], JSON_UNESCAPED_SLASHES)
                    : 'An unexpected error occurred.';
            }
            exit;
        });

        register_shutdown_function(static function () use ($isApi): void {
            $last = error_get_last();
            if ($last === null || !in_array($last['type'], self::FATAL_TYPES, true)) {
                return;
            }

            self::log(new ErrorException($last['message'], 0, $last['type'], $last['file'], $last['line']));

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: ' . ($isApi ? 'application/json; charset=utf-8' : 'text/plain; charset=utf-8'));
                echo $isApi ? '{"error":"internal_error"}' : 'An unexpected error occurred.';
            }
        });
    }

    private static function log(\Throwable $e): void
    {
        $callers = [];
        foreach (array_slice($e->getTrace(), 0, 8) as $frame) {
            $call = ($frame['class'] ?? '')
                . ($frame['type'] ?? '')
                . ($frame['function'] ?? '<main>');
            $at = ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?');
            $callers[] = $call . ' @ ' . basename($at);
        }

        error_log(sprintf(
            '[storage-router] %s: %s (%s:%d) trace: %s',
            get_class($e),
            self::redact((string) $e->getMessage()),
            basename((string) $e->getFile()),
            $e->getLine(),
            implode(' -> ', $callers)
        ));
    }

    /**
     * Replaces long base64/hex blobs — the typical shape of keys, wrapped
     * DEKs, and tokens — so a message that embeds one can't leak it.
     */
    private static function redact(string $value): string
    {
        $value = (string) preg_replace('/[A-Za-z0-9+\/=]{32,}={0,2}/u', '[redacted]', $value);

        return (string) preg_replace('/[0-9a-fA-F]{64,}/u', '[redacted]', $value);
    }
}