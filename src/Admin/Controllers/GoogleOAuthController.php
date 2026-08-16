<?php

declare(strict_types=1);

namespace App\Admin\Controllers;

use App\Crypto\KeyManager;
use App\Data\Repositories\StorageBackendRepository;
use App\Storage\GoogleDriveClient;
use App\Storage\GoogleDriveProvider;
use App\Support\UuidGenerator;
use RuntimeException;

/**
 * "Add Drive" admin flow, with CSRF protection on the
 * OAuth callback via the `state` parameter — a callback with a
 * missing or mismatched state is rejected before ever exchanging a code.
 */
final class GoogleOAuthController
{
    private const SCOPE = 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/userinfo.email';
    private const STATE_SESSION_KEY = '_google_oauth_state';

    public function __construct(
        private StorageBackendRepository $backends,
        private GoogleDriveClient $client,
        private KeyManager $keyManager,
        private string $clientId,
        private string $clientSecret,
        private string $redirectUri,
        private string $authorizeUrl = 'https://accounts.google.com/o/oauth2/v2/auth'
    ) {
    }

    public function redirectToConsent(): void
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Google OAuth is not configured. Set GOOGLE_OAUTH_CLIENT_ID / '
                . 'GOOGLE_OAUTH_CLIENT_SECRET / GOOGLE_OAUTH_REDIRECT_URI in .env.';
            return;
        }

        $state = bin2hex(random_bytes(32));
        $_SESSION[self::STATE_SESSION_KEY] = $state;

        $params = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            // Forces Google to issue a refresh_token even if this app was
            // already authorized before — otherwise a repeat connect can
            // silently return no refresh_token at all.
            'prompt' => 'consent',
            'state' => $state,
        ]);

        header('Location: ' . $this->authorizeUrl . '?' . $params);
    }

    /** @param array<string, mixed> $query */
    public function handleCallback(array $query): void
    {
        $state = is_string($query['state'] ?? null) ? $query['state'] : null;
        $expectedState = $_SESSION[self::STATE_SESSION_KEY] ?? null;
        unset($_SESSION[self::STATE_SESSION_KEY]);

        if ($state === null || $expectedState === null || !hash_equals($expectedState, $state)) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Invalid or missing OAuth state — request rejected.';
            return;
        }

        $code = is_string($query['code'] ?? null) ? $query['code'] : null;
        if ($code === null) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Google did not return an authorization code.';
            return;
        }

        try {
            $tokens = $this->client->exchangeCodeForTokens($this->clientId, $this->clientSecret, $code, $this->redirectUri);
        } catch (RuntimeException $e) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Failed to exchange authorization code with Google.';
            return;
        }

        $refreshToken = $tokens['refresh_token'] ?? null;
        if (!is_string($refreshToken) || $refreshToken === '') {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Google did not return a refresh token. If this app was already authorized before, "
                . "remove its access at https://myaccount.google.com/permissions and try connecting again.";
            return;
        }

        $email = '';
        try {
            $email = $this->client->getUserEmail($tokens['access_token']);
        } catch (RuntimeException $e) {
            // Non-fatal — the backend is still fully usable without a
            // known display email, it just won't have a friendly label.
        }

        $secretKey = $this->keyManager->getOrCreateKek(GoogleDriveProvider::OAUTH_SECRET_KEK_REF);
        $encryptedRefreshToken = $this->keyManager->wrapDek($secretKey, $refreshToken);
        sodium_memzero($secretKey);
        sodium_memzero($refreshToken);

        $storageId = UuidGenerator::generate();
        $label = $email !== '' ? "Google Drive ({$email})" : 'Google Drive (' . substr($storageId, 0, 8) . ')';

        $this->backends->create($storageId, $label, 'google_drive', [
            'google_account_email' => $email,
            'oauth_refresh_token' => $encryptedRefreshToken, // always encrypted, never plaintext
        ], 0); // total quota unknown until the first quota refresh

        header('Location: /admin/');
    }
}
