<?php

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Data\Repositories\AppRepository;
use App\Support\ErrorCatalog;

/**
 * Resolves the calling app from its API key. Keys are stored as a SHA-256
 * hash — API keys are high-entropy random tokens, not user-chosen
 * passwords, so a fast hash + indexed DB lookup is appropriate here
 * (unlike admin passwords, which use password_hash/bcrypt).
 */
final class ApiKeyAuth
{
    public function __construct(private AppRepository $apps)
    {
    }

    /** @return array<string, mixed> the authenticated app row, or exits with an error response */
    public function authenticate(): array
    {
        $key = $this->extractKey();

        if ($key === null || $key === '') {
            ErrorCatalog::respond(401, ErrorCatalog::UNAUTHORIZED, 'Missing API key.');
        }

        $hash = hash('sha256', $key);
        $app = $this->apps->findByApiKeyHash($hash);

        if ($app === null) {
            ErrorCatalog::respond(401, ErrorCatalog::UNAUTHORIZED, 'Invalid API key.');
        }

        if ($app['status'] !== 'active') {
            ErrorCatalog::respond(403, ErrorCatalog::UNAUTHORIZED, 'This app is suspended.');
        }

        return $app;
    }

    private function extractKey(): ?string
    {
        if (!empty($_SERVER['HTTP_X_API_KEY'])) {
            return $_SERVER['HTTP_X_API_KEY'];
        }

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        return null;
    }
}
