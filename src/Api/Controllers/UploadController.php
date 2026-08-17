<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Crypto\EnvelopeEncryptor;
use App\Crypto\KeyManager;
use App\Data\Repositories\AppStorageAccessRepository;
use App\Data\Repositories\AuditLogRepository;
use App\Data\Repositories\FileRepository;
use App\Data\Repositories\StorageBackendRepository;
use App\Storage\BackendSelector;
use App\Storage\StorageProviderRegistry;
use App\Support\ErrorCatalog;
use App\Support\UuidGenerator;
use PDO;
use Throwable;

final class UploadController
{
    public function __construct(
        private PDO $pdo,
        private FileRepository $files,
        private StorageBackendRepository $backends,
        private AppStorageAccessRepository $access,
        private AuditLogRepository $auditLog,
        private StorageProviderRegistry $providers,
        private BackendSelector $selector,
        private KeyManager $keyManager,
        private EnvelopeEncryptor $encryptor
    ) {
    }

    /** @param array<string, mixed> $app authenticated app row */
    public function upload(array $app): void
    {
        $appId = (string) $app['id'];

        $candidates = $this->access->listForApp($appId, true);

        if ($candidates === []) {
            $this->auditLog->log('app', $appId, 'upload.rejected', 'error', null, [
                'reason' => 'no_storage_available',
                'errors' => [],
            ]);
            ErrorCatalog::respond(507, ErrorCatalog::NO_STORAGE_AVAILABLE, 'No storage backend is available for this app.');
        }

        // Least-used-space first, priority as tie-breaker.
        $orderedCandidates = $this->selector->order($candidates);

        $rawInput = fopen('php://input', 'rb');
        if ($rawInput === false) {
            ErrorCatalog::respond(400, ErrorCatalog::INVALID_REQUEST, 'Could not read request body.');
        }

        $fileId = UuidGenerator::generate();
        $mimeType = $_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream';
        $userId = (!empty($_SERVER['HTTP_X_USER_ID'])) ? $_SERVER['HTTP_X_USER_ID'] : null;

        // Encryption happens exactly once, regardless of which (or how
        // many) backends are subsequently attempted — the ciphertext
        // doesn't depend on the destination, only the retry loop below does.
        $cipherBuffer = fopen('php://temp/maxmemory:5242880', 'r+b');
        if ($cipherBuffer === false) {
            fclose($rawInput);
            ErrorCatalog::respond(500, ErrorCatalog::INTERNAL_ERROR, 'Could not allocate encryption buffer.');
        }

        try {
            $encResult = $this->encryptor->encryptStream($rawInput, $cipherBuffer);
        } catch (Throwable $e) {
            fclose($rawInput);
            fclose($cipherBuffer);
            ErrorCatalog::respond(500, ErrorCatalog::INTERNAL_ERROR, 'Encryption failed.');
        }
        fclose($rawInput);

        $kekVersion = (int) ($app['kek_version'] ?? 1);
        $kek = $this->keyManager->getOrCreateKek((string) $app['kek_ref'], $kekVersion);
        $wrappedDek = $this->keyManager->wrapDek($kek, $encResult['dek']);
        sodium_memzero($encResult['dek']);
        sodium_memzero($kek);

        $plaintextSize = $encResult['size_bytes'];
        $attemptErrors = [];

        // Retry against the next eligible backend on failure, rather than
        // failing the whole request on the first backend's error.
        foreach ($orderedCandidates as $candidate) {
            $backend = $this->backends->findById((string) $candidate['storage_id']);
            if ($backend === null) {
                $attemptErrors[] = ['storage_id' => $candidate['storage_id'], 'error' => 'backend_not_found'];
                continue;
            }

            $provider = $this->providers->forBackend($backend);

            rewind($cipherBuffer);

            try {
                $providerRef = $provider->upload($backend, $cipherBuffer, $fileId);
            } catch (Throwable $e) {
                $attemptErrors[] = ['storage_id' => $backend['id'], 'error' => 'provider_upload_failed'];
                continue;
            }

            $capacityCap = (int) ($backend['provider_config']['capacity_cap_bytes'] ?? 0);

            // TOCTOU-safe capacity enforcement: recompute
            // "used" and compare against the cap inside the same DB
            // transaction that inserts the files row.
            $this->pdo->beginTransaction();

            try {
                $usedBefore = $this->files->sumActiveBytesForStorage((string) $backend['id']);

                if ($capacityCap > 0 && ($usedBefore + $plaintextSize) > $capacityCap) {
                    $this->pdo->rollBack();
                    $provider->delete($backend, $providerRef);
                    $attemptErrors[] = ['storage_id' => $backend['id'], 'error' => 'quota_exceeded'];
                    continue;
                }

                $this->files->create([
                    'id' => $fileId,
                    'app_id' => $appId,
                    'user_id' => $userId,
                    'storage_id' => $backend['id'],
                    'provider_ref' => $providerRef,
                    'encrypted_dek' => $wrappedDek,
                    'kek_version' => $kekVersion,
                    'stream_header' => base64_encode($encResult['header']),
                    'size_bytes' => $plaintextSize,
                    'mime_type' => $mimeType,
                    'checksum_plaintext' => $encResult['checksum'],
                ]);

                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $provider->delete($backend, $providerRef);
                $attemptErrors[] = ['storage_id' => $backend['id'], 'error' => 'metadata_insert_failed'];
                continue;
            }

            // Success — stop trying further candidates.
            fclose($cipherBuffer);
            http_response_code(201);
            header('Content-Type: application/json');
            echo json_encode(['file_id' => $fileId], JSON_UNESCAPED_SLASHES);
            return;
        }

        // Every candidate backend failed.
        fclose($cipherBuffer);

        $this->auditLog->log('app', $appId, 'upload.rejected', 'error', null, [
            'reason' => 'no_storage_available',
            'errors' => $attemptErrors,
        ]);

        ErrorCatalog::respond(507, ErrorCatalog::NO_STORAGE_AVAILABLE, 'All eligible storage backends failed or are at capacity.');
    }
}
