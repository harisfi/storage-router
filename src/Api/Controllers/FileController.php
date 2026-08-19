<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Crypto\EnvelopeEncryptor;
use App\Crypto\KeyManager;
use App\Data\Repositories\AuditLogRepository;
use App\Data\Repositories\FileRepository;
use App\Data\Repositories\StorageBackendRepository;
use App\Storage\StorageProviderRegistry;
use App\Support\ErrorCatalog;
use Throwable;

final class FileController
{
    public function __construct(
        private FileRepository $files,
        private StorageBackendRepository $backends,
        private StorageProviderRegistry $providers,
        private KeyManager $keyManager,
        private EnvelopeEncryptor $encryptor,
        private AuditLogRepository $auditLog
    ) {
    }

    /** @param array<string, mixed> $app authenticated app row */
    public function download(array $app, string $fileId): void
    {
        $file = $this->findScoped($app, $fileId);

        $backend = $this->backends->findById((string) $file['storage_id']);
        if ($backend === null) {
            ErrorCatalog::respond(500, ErrorCatalog::INTERNAL_ERROR, 'Backend could not be loaded.');
        }

        // Use the version that actually wrapped THIS file's DEK, not the
        // app's current version — after a rotation those can differ for
        // any file not yet re-wrapped.
        $fileKekVersion = (int) ($file['kek_version'] ?? 1);
        $kek = $this->keyManager->getOrCreateKek((string) $app['kek_ref'], $fileKekVersion);

        try {
            $dek = $this->keyManager->unwrapDek($kek, (string) $file['encrypted_dek']);
        } catch (Throwable $e) {
            sodium_memzero($kek);
            ErrorCatalog::respond(500, ErrorCatalog::INTERNAL_ERROR, 'Could not decrypt this file.');
        }
        sodium_memzero($kek);

        $header = base64_decode((string) $file['stream_header'], true);
        if ($header === false) {
            sodium_memzero($dek);
            ErrorCatalog::respond(500, ErrorCatalog::INTERNAL_ERROR, 'Corrupted file metadata.');
        }

        // Fetch ciphertext into a buffer first, then decrypt from the
        // buffer to the real response stream. This is the one method
        // every provider implements identically (write raw bytes to a
        // given stream) — Local writes from disk, Drive streams via curl
        // — so decryption sits uniformly in front of either provider
        // without either one needing to know encryption exists.
        $provider = $this->providers->forBackend($backend);
        $cipherBuffer = fopen('php://temp/maxmemory:5242880', 'r+b');

        try {
            $provider->download($backend, (string) $file['provider_ref'], $cipherBuffer);
        } catch (Throwable $e) {
            sodium_memzero($dek);
            fclose($cipherBuffer);
            ErrorCatalog::respond(502, ErrorCatalog::INTERNAL_ERROR, 'Could not fetch file from storage backend.');
        }
        rewind($cipherBuffer);

        // Decrypt the ENTIRE stream into a buffer and validate it fully
        // before sending a single byte to the client. If authentication
        // fails on any chunk, we abort with a clean error and never emit
        // partial plaintext — a truncated file must not silently reach the
        // caller with a 200.
        $clearBuffer = fopen('php://temp/maxmemory:5242880', 'r+b');

        try {
            $dec = $this->encryptor->decryptStream($dek, $header, $cipherBuffer, $clearBuffer);
        } catch (Throwable $e) {
            sodium_memzero($dek);
            fclose($cipherBuffer);
            fclose($clearBuffer);
            ErrorCatalog::respond(500, ErrorCatalog::INTERNAL_ERROR, 'File could not be decrypted.');
        }
        sodium_memzero($dek);
        fclose($cipherBuffer);

        // The stored plaintext length and the decrypted length must agree —
        // a mismatch means the ciphertext or metadata was tampered with.
        if ((int) $dec['size_bytes'] !== (int) $file['size_bytes']) {
            fclose($clearBuffer);
            ErrorCatalog::respond(500, ErrorCatalog::INTERNAL_ERROR, 'File integrity check failed.');
        }
        rewind($clearBuffer);

        header('Content-Type: ' . $file['mime_type']);
        // Never render inline based on sniffed type.
        header('Content-Disposition: attachment; filename="' . $file['id'] . '"');
        header('Content-Length: ' . $dec['size_bytes']); // plaintext size, confirmed by decryption

        $out = fopen('php://output', 'wb');
        stream_copy_to_stream($clearBuffer, $out);
        fclose($clearBuffer);
        fclose($out);
    }

    /** @param array<string, mixed> $app authenticated app row */
    public function delete(array $app, string $fileId): void
    {
        $file = $this->findScoped($app, $fileId);

        // 1) Destroy the stored DEK + stream header first: even if the
        //    backend blob can't be removed, the leftover ciphertext becomes
        //    permanently undecryptable — the data is gone the moment the key
        //    is, regardless of the backend's fate. This also matches the
        //    "destroy the key first" hardening.
        $this->files->destroyKeyMaterial($fileId);

        // 2) Best-effort delete of the ciphertext blob. If it fails, the blob
        //    remains as useless bytes (DEK already destroyed); log it so an
        //    operator can reclaim the wasted storage. The DB record is still
        //    marked deleted.
        $backend = $this->backends->findById((string) $file['storage_id']);
        if ($backend !== null) {
            try {
                $this->providers->forBackend($backend)->delete($backend, (string) $file['provider_ref']);
            } catch (Throwable $e) {
                $this->auditLog->log('app', (string) $app['id'], 'file.delete_blob_failed', 'error', $fileId, [
                    'reason' => 'provider_delete_failed',
                    'errors' => [['storage_id' => (string) $backend['id'], 'error' => 'delete_failed']],
                ]);
            }
        }

        $this->files->markDeleted($fileId);

        http_response_code(204);
    }

    /**
     * Always scoped to the authenticated app — never a bare findById() —
     * so App A can never reach App B's file even with a guessed/valid UUID.
     *
     * @param array<string, mixed> $app
     * @return array<string, mixed>
     */
    private function findScoped(array $app, string $fileId): array
    {
        $userId = (!empty($_SERVER['HTTP_X_USER_ID'])) ? $_SERVER['HTTP_X_USER_ID'] : null;
        $file = $this->files->findByIdForApp($fileId, (string) $app['id'], $userId);

        if ($file === null) {
            ErrorCatalog::respond(404, ErrorCatalog::NOT_FOUND, 'File not found.');
        }

        return $file;
    }
}
