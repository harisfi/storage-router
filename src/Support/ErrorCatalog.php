<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Every client-facing API error goes through here. Keeps the set of error
 * codes small and fixed, and guarantees internal details (storage_id,
 * provider refs, stack traces) never leak into a response.
 */
final class ErrorCatalog
{
    public const UNAUTHORIZED = 'unauthorized';
    public const NOT_FOUND = 'not_found';
    public const INVALID_REQUEST = 'invalid_request';
    public const NO_STORAGE_AVAILABLE = 'no_storage_available';
    public const RATE_LIMITED = 'rate_limited';
    public const INTERNAL_ERROR = 'internal_error';

    public static function respond(int $httpStatus, string $code, string $message): never
    {
        http_response_code($httpStatus);
        header('Content-Type: application/json');
        echo json_encode(['error' => $code, 'message' => $message], JSON_UNESCAPED_SLASHES);
        exit;
    }
}
