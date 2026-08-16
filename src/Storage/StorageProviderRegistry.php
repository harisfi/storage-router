<?php

declare(strict_types=1);

namespace App\Storage;

use RuntimeException;

/**
 * Resolves a storage_backends row to the StorageProviderInterface
 * implementation that handles its provider_type. Adding a future provider
 * means registering one more entry here — controllers never need to know
 * or care which concrete provider they're talking to.
 */
final class StorageProviderRegistry
{
    /** @param array<string, StorageProviderInterface> $providersByType */
    public function __construct(private array $providersByType)
    {
    }

    /** @param array<string, mixed> $backend */
    public function forBackend(array $backend): StorageProviderInterface
    {
        $type = (string) ($backend['provider_type'] ?? '');

        if (!isset($this->providersByType[$type])) {
            throw new RuntimeException("No storage provider registered for provider_type: {$type}");
        }

        return $this->providersByType[$type];
    }
}
