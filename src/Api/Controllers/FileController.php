<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Crypto\EnvelopeEncryptor;
use App\Crypto\KeyManager;
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
        private EnvelopeEncryptor $encryptor
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

        header('Content-Type: ' . $file['mime_type']);
        // Never render inline based on sniffed type.
        header('Content-Disposition: attachment; filename="' . $file['id'] . '"');
        header('Content-Length: ' . $file['size_bytes']); // plaintext size, stored at upload time

        $out = fopen('php://output', 'wb');

        try {
            // AEAD authentication is checked per chunk before it's written
            // to $out — corrupted/tampered ciphertext throws here rather
            // than silently forwarding bad plaintext to the client.
            $this->encryptor->decryptStream($dek, $header, $cipherBuffer, $out);
        } catch (Throwable $e) {
            // Headers (and possibly some already-authenticated plaintext
            // chunks) may have been sent by this point — there is no clean
            // way to convert this into a JSON error mid-stream. The
            // truncated/aborted download is the visible symptom to the
            // client; the failure itself should still be logged
            // server-side by the surrounding error handler.
        } finally {
            sodium_memzero($dek);
            fclose($cipherBuffer);
            fclose($out);
        }
    }

    /** @param array<string, mixed> $app authenticated app row */
    public function delete(array $app, string $fileId): void
    {
        $file = $this->findScoped($app, $fileId);

        $backend = $this->backends->findById((string) $file['storage_id']);
        if ($backend !== null) {
            // Ciphertext deletion needs no decryption — deleting the
            // opaque blob is all that's required.
            $provider = $this->providers->forBackend($backend);
            $provider->delete($backend, (string) $file['provider_ref']);
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
