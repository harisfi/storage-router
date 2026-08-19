<?php

declare(strict_types=1);

namespace App\Storage;

use App\Crypto\KeyManager;
use RuntimeException;

/**
 * Google Drive storage backend. Refresh tokens are stored encrypted at
 * rest under a dedicated, non-per-app secret — OAuth credentials are
 * backend-level infrastructure secrets, a different trust domain from
 * the per-app file-encryption KEKs managed elsewhere in
 * App\Crypto\KeyManager, even though this class reuses that same class's
 * wrap/unwrap primitives for convenience.
 */
final class GoogleDriveProvider implements StorageProviderInterface
{
    /**
     * Reserved kek_ref for the single system-wide key that encrypts every
     * Google Drive backend's refresh token. Never collides with a real
     * app_id (those are always well-formed UUIDs).
     */
    public const OAUTH_SECRET_KEK_REF = 'system-google-oauth';

    /**
     * Top-level Drive folder that holds every managed blob, and the
     * per-backend override key in provider_config. Files are organized as
     * <root>/<storage_id>/<shard>/<random> to mirror the local provider.
     */
    public const ROOT_FOLDER_NAME = 'Storage Router';

    /** Memoized per-request so the root folder isn't re-searched within one upload. */
    private static ?string $rootFolderCache = null;

    public function __construct(
        private GoogleDriveClient $client,
        private KeyManager $keyManager,
        private string $oauthClientId,
        private string $oauthClientSecret
    ) {
    }

    public function upload(array $backend, $inputStream, string $refHint): string
    {
        $accessToken = $this->getAccessToken($backend);

        $content = stream_get_contents($inputStream);
        $size = strlen($content);

        $buffer = fopen('php://temp', 'r+b');
        fwrite($buffer, $content);
        rewind($buffer);

        // Mirrors the local provider's layout, where the top folder is the
        // storage backend's own id and the shard sits below it:
        // <root>/<storage_id>/<shard>/<random>.
        $rootFolder = $this->ensureRootFolder($accessToken, $backend);
        $storageFolder = $this->client->ensureFolder($accessToken, (string) $backend['id'], $rootFolder);
        $shard = substr($refHint, 0, 2);
        $shardFolder = $this->client->ensureFolder($accessToken, $shard, $storageFolder);

        $blobName = bin2hex(random_bytes(16)); // router-generated, not client-supplied
        $fileId = $this->client->uploadFile($accessToken, $buffer, $blobName, 'application/octet-stream', $size, $shardFolder);
        fclose($buffer);

        return $fileId;
    }

    public function download(array $backend, string $providerRef, $outputStream): void
    {
        $accessToken = $this->getAccessToken($backend);
        $this->client->downloadFile($accessToken, $providerRef, $outputStream);
    }

    public function delete(array $backend, string $providerRef): void
    {
        $accessToken = $this->getAccessToken($backend);
        $this->client->deleteFile($accessToken, $providerRef);
    }

    public function getQuota(array $backend): array
    {
        $accessToken = $this->getAccessToken($backend);

        return $this->client->getQuota($accessToken);
    }

    /**
     * Access tokens are short-lived (~1 hour) and this router doesn't
     * cache them across requests — each call refreshes fresh from the
     * stored refresh token. Simpler and correct; caching access tokens
     * per-backend would be a reasonable future optimization but isn't
     * needed for correctness.
     *
     * @param array<string, mixed> $backend
     */
    private function getAccessToken(array $backend): string
    {
        $encryptedRefreshToken = $backend['provider_config']['oauth_refresh_token'] ?? null;

        if (!is_string($encryptedRefreshToken) || $encryptedRefreshToken === '') {
            throw new RuntimeException('This Google Drive backend has no stored refresh token.');
        }

        $secretKey = $this->keyManager->getOrCreateKek(self::OAUTH_SECRET_KEK_REF);
        $refreshToken = $this->keyManager->unwrapDek($secretKey, $encryptedRefreshToken);
        sodium_memzero($secretKey);

        $tokens = $this->client->refreshAccessToken($this->oauthClientId, $this->oauthClientSecret, $refreshToken);
        sodium_memzero($refreshToken);

        return $tokens['access_token'];
    }

    /** Returns (creating if needed) the backend's top-level Drive folder id. */
    private function ensureRootFolder(string $accessToken, array $backend): string
    {
        if (self::$rootFolderCache !== null) {
            return self::$rootFolderCache;
        }

        $name = (string) ($backend['provider_config']['google_drive_root_folder'] ?? self::ROOT_FOLDER_NAME);
        self::$rootFolderCache = $this->client->ensureFolder($accessToken, $name);

        return self::$rootFolderCache;
    }
}
