<?php

declare(strict_types=1);

namespace App\Storage;

use App\Data\Repositories\FileRepository;
use RuntimeException;

/**
 * Local disk storage backend. Files are written under the backend's
 * configured base_path, sharded into subdirectories, with router-generated
 * random filenames — never the client-supplied or original filename.
 */
final class LocalProvider implements StorageProviderInterface
{
    public function __construct(private FileRepository $files)
    {
    }

    public function upload(array $backend, $inputStream, string $refHint): string
    {
        $basePath = $this->basePath($backend);
        $shard = substr($refHint, 0, 2);
        $dir = $basePath . '/' . $shard;

        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create storage directory: {$dir}");
        }

        $filename = bin2hex(random_bytes(16));
        $relativePath = $shard . '/' . $filename;
        $targetPath = $dir . '/' . $filename;

        $out = fopen($targetPath, 'wb');
        if ($out === false) {
            throw new RuntimeException("Could not open target file for writing: {$targetPath}");
        }

        $bytesCopied = stream_copy_to_stream($inputStream, $out);
        fclose($out);

        if ($bytesCopied === false) {
            @unlink($targetPath);
            throw new RuntimeException('Failed writing file to local backend.');
        }

        return $relativePath;
    }

    public function download(array $backend, string $providerRef, $outputStream): void
    {
        $path = $this->resolveAndValidate($backend, $providerRef);

        $in = fopen($path, 'rb');
        if ($in === false) {
            throw new RuntimeException("Could not open file for reading: {$path}");
        }

        stream_copy_to_stream($in, $outputStream);
        fclose($in);
    }

    public function delete(array $backend, string $providerRef): void
    {
        $path = $this->resolveAndValidate($backend, $providerRef);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function getQuota(array $backend): array
    {
        // Computed from the files table, not a filesystem scan.
        $used = $this->files->sumActiveBytesForStorage($backend['id']);
        $total = (int) ($backend['provider_config']['capacity_cap_bytes'] ?? 0);

        return ['used' => $used, 'total' => $total];
    }

    private function basePath(array $backend): string
    {
        $basePath = $backend['provider_config']['base_path'] ?? null;

        if (!is_string($basePath) || $basePath === '') {
            throw new RuntimeException('Local backend is missing a base_path in its provider_config.');
        }

        return rtrim($basePath, '/');
    }

    /**
     * Resolves $providerRef against the backend's base_path and confirms
     * the result actually stays within it — defense in depth, even though
     * $providerRef is always router-generated, never client input.
     */
    private function resolveAndValidate(array $backend, string $providerRef): string
    {
        $basePath = $this->basePath($backend);
        $realBase = realpath($basePath);

        if ($realBase === false) {
            throw new RuntimeException("Local backend base_path does not exist: {$basePath}");
        }

        $candidate = $basePath . '/' . $providerRef;
        $realCandidate = realpath($candidate);

        if ($realCandidate === false || !str_starts_with($realCandidate, $realBase . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Invalid or out-of-bounds file reference.');
        }

        return $realCandidate;
    }
}
