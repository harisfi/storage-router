<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal, dependency-free .env loader.
 *
 * Deliberately not using vlucas/phpdotenv or any package here — Composer
 * is reserved for autoloading and google/apiclient only.
 */
final class Config
{
    /** @var array<string, string> */
    private static array $values = [];

    private static bool $loaded = false;

    public static function load(string $envFilePath): void
    {
        if (self::$loaded) {
            return;
        }

        if (is_file($envFilePath)) {
            $lines = file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                if (!str_contains($line, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Strip matching surrounding quotes, if present.
                if (strlen($value) >= 2 && $value[0] === $value[-1] && ($value[0] === '"' || $value[0] === "'")) {
                    $value = substr($value, 1, -1);
                }

                self::$values[$key] = $value;
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$values[$key] ?? $default;
    }
}
