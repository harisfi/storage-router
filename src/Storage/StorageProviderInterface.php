<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Common contract every storage backend implements. The router's
 * encryption, metadata, and selection logic never talks to a specific
 * provider directly — only through this interface.
 */
interface StorageProviderInterface
{
    /**
     * Write $inputStream's contents to this backend and return the
     * provider-specific reference to store in files.provider_ref
     * (a Drive file id, or a local relative path).
     *
     * @param array<string, mixed> $backend storage_backends row, provider_config already JSON-decoded
     * @param resource $inputStream
     */
    public function upload(array $backend, $inputStream, string $refHint): string;

    /**
     * Stream the object identified by $providerRef to $outputStream.
     *
     * @param array<string, mixed> $backend
     * @param resource $outputStream
     */
    public function download(array $backend, string $providerRef, $outputStream): void;

    /** @param array<string, mixed> $backend */
    public function delete(array $backend, string $providerRef): void;

    /**
     * @param array<string, mixed> $backend
     * @return array{used: int, total: int}
     */
    public function getQuota(array $backend): array;
}
