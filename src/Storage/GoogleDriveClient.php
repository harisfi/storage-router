<?php

declare(strict_types=1);

namespace App\Storage;

use RuntimeException;

/**
 * Thin REST client for Google's OAuth2 token endpoint and the Drive v3
 * API, using PHP's built-in curl extension directly rather than the
 * official google/apiclient SDK.
 *
 * Why: google/apiclient pulls in a large dependency tree (google/auth,
 * Guzzle, google/apiclient-services — the latter alone bundles service
 * definitions for every Google API) that could not be resolved in the
 * build environment without Packagist access. A REST-only client has
 * zero Composer dependencies, which also fits the project's minimal-
 * dependency stack better than the original SDK plan.
 * Swapping this out for the official SDK later, if ever wanted, would
 * only touch this class and GoogleDriveProvider — nothing else.
 *
 * Base URLs are constructor parameters (defaulting to the real Google
 * endpoints) specifically so tests can point this at a local fake server
 * instead of hitting Google's live API.
 */
final class GoogleDriveClient
{
    public function __construct(
        private string $oauthTokenUrl = 'https://oauth2.googleapis.com/token',
        private string $userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo',
        private string $driveApiBaseUrl = 'https://www.googleapis.com/drive/v3',
        private string $driveUploadBaseUrl = 'https://www.googleapis.com/upload/drive/v3'
    ) {
    }

    /** @return array{access_token: string, refresh_token?: string, expires_in: int} */
    public function exchangeCodeForTokens(string $clientId, string $clientSecret, string $code, string $redirectUri): array
    {
        return $this->postForm([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);
    }

    /** @return array{access_token: string, expires_in: int} */
    public function refreshAccessToken(string $clientId, string $clientSecret, string $refreshToken): array
    {
        return $this->postForm([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
    }

    public function getUserEmail(string $accessToken): string
    {
        $response = $this->request('GET', $this->userInfoUrl, ['Authorization: Bearer ' . $accessToken]);

        if ($response['status'] !== 200) {
            throw new RuntimeException('Failed to fetch Google account email: HTTP ' . $response['status']);
        }

        $body = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);

        return (string) ($body['email'] ?? '');
    }

    /**
     * Resumable upload: initiate a session, then PUT the content in one
     * shot. Google's resumable protocol allows the entire file to be sent
     * in a single PUT (no Content-Range header needed) as long as
     * Content-Length is set correctly — this is sufficient for the file
     * sizes this router handles; very large files could extend this to
     * chunk across multiple PUTs against the same session URI.
     *
     * @param resource $inputStream
     */
    public function uploadFile(string $accessToken, $inputStream, string $filename, string $mimeType, int $sizeBytes): string
    {
        $initHeaders = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Type: ' . $mimeType,
        ];
        $metadata = json_encode(['name' => $filename], JSON_THROW_ON_ERROR);

        $initResponse = $this->request(
            'POST',
            $this->driveUploadBaseUrl . '/files?uploadType=resumable',
            $initHeaders,
            $metadata
        );

        if ($initResponse['status'] !== 200) {
            throw new RuntimeException('Failed to initiate Drive upload session: HTTP ' . $initResponse['status']);
        }

        $sessionUrl = $initResponse['headers']['location'] ?? null;
        if ($sessionUrl === null) {
            throw new RuntimeException('Drive did not return a resumable session URL.');
        }

        $content = stream_get_contents($inputStream);
        $uploadHeaders = [
            'Content-Type: ' . $mimeType,
            'Content-Length: ' . $sizeBytes,
        ];

        $uploadResponse = $this->request('PUT', $sessionUrl, $uploadHeaders, $content);

        if (!in_array($uploadResponse['status'], [200, 201], true)) {
            throw new RuntimeException('Failed to upload file content to Drive: HTTP ' . $uploadResponse['status']);
        }

        $body = json_decode($uploadResponse['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!isset($body['id'])) {
            throw new RuntimeException('Drive upload response did not include a file id.');
        }

        return (string) $body['id'];
    }

    /** @param resource $outputStream */
    public function downloadFile(string $accessToken, string $fileId, $outputStream): void
    {
        $url = $this->driveApiBaseUrl . '/files/' . rawurlencode($fileId) . '?alt=media';
        $response = $this->request('GET', $url, ['Authorization: Bearer ' . $accessToken], null, $outputStream);

        if ($response['status'] !== 200) {
            throw new RuntimeException('Failed to download file from Drive: HTTP ' . $response['status']);
        }
    }

    public function deleteFile(string $accessToken, string $fileId): void
    {
        $url = $this->driveApiBaseUrl . '/files/' . rawurlencode($fileId);
        $response = $this->request('DELETE', $url, ['Authorization: Bearer ' . $accessToken]);

        if (!in_array($response['status'], [200, 204], true)) {
            throw new RuntimeException('Failed to delete file from Drive: HTTP ' . $response['status']);
        }
    }

    /** @return array{used: int, total: int} */
    public function getQuota(string $accessToken): array
    {
        $url = $this->driveApiBaseUrl . '/about?fields=storageQuota';
        $response = $this->request('GET', $url, ['Authorization: Bearer ' . $accessToken]);

        if ($response['status'] !== 200) {
            throw new RuntimeException('Failed to fetch Drive quota: HTTP ' . $response['status']);
        }

        $body = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        $quota = $body['storageQuota'] ?? [];

        return [
            'used' => (int) ($quota['usage'] ?? 0),
            // Drive omits 'limit' entirely for accounts with unlimited storage.
            'total' => (int) ($quota['limit'] ?? 0),
        ];
    }

    /** @param array<string, string> $fields */
    private function postForm(array $fields): array
    {
        $response = $this->request(
            'POST',
            $this->oauthTokenUrl,
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query($fields)
        );

        if ($response['status'] !== 200) {
            throw new RuntimeException('OAuth token request failed: HTTP ' . $response['status'] . ' ' . $response['body']);
        }

        return json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<int, string> $headers
     * @param resource|null $outputStream if provided, the response body is streamed here instead of buffered
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function request(string $method, string $url, array $headers, ?string $body = null, $outputStream = null): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curlHandle, string $headerLine) use (&$responseHeaders): int {
            $len = strlen($headerLine);
            $parts = explode(':', $headerLine, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        });

        if ($outputStream !== null) {
            curl_setopt($ch, CURLOPT_FILE, $outputStream);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }

        $result = curl_exec($ch);

        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP request to Drive/OAuth failed: {$error}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => $outputStream !== null ? '' : (string) $result,
        ];
    }
}
