<?php

declare(strict_types=1);

namespace App\Admin\Controllers;

use App\Data\Repositories\AdminRepository;
use App\Data\Repositories\AuditLogRepository;
use App\Data\Repositories\RateLimitRepository;
use App\Support\Csrf;
use App\Support\Session;

final class AuthController
{
    /** Max login POSTs allowed per IP per 60-second window before throttling. */
    private const LOGIN_MAX_PER_MINUTE = 3;

    public function __construct(
        private AdminRepository $admins,
        private AuditLogRepository $auditLog,
        private RateLimitRepository $rateLimits
    ) {
    }

    public function showLoginForm(?string $error = null): void
    {
        $csrfToken = Csrf::token();
        require __DIR__ . '/../Views/login.php';
    }

    /** @param array<string, mixed> $post */
    public function handleLogin(array $post): void
    {
        if (!$this->throttleLoginAttempt()) {
            $this->auditLog->log('admin', $this->clientIp(), 'admin.login_failed', 'error', null, [
                'reason' => 'rate_limited',
            ]);
            $this->showLoginForm('Too many login attempts. Please wait a minute and try again.');
            return;
        }

        if (!Csrf::verify(is_string($post['csrf_token'] ?? null) ? $post['csrf_token'] : null)) {
            $this->showLoginForm('Your session expired, please try again.');
            return;
        }

        $username = trim((string) ($post['username'] ?? ''));
        $password = (string) ($post['password'] ?? '');

        $admin = $username !== '' ? $this->admins->findByUsername($username) : null;

        if ($admin === null || !password_verify($password, $admin['password_hash'])) {
            // Deliberately vague to the user (no "username not found" vs
            // "wrong password" distinction) — avoids username enumeration.
            $this->auditLog->log(
                'admin',
                $username !== '' ? $username : 'unknown',
                'admin.login_failed',
                'error',
                null,
                ['reason' => 'invalid_credentials']
            );
            $this->showLoginForm('Invalid username or password.');
            return;
        }

        // Regenerate the session id on privilege change — mitigates session fixation.
        Session::regenerate();
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];

        $this->auditLog->log('admin', (string) $admin['id'], 'admin.login', 'success');

        header('Location: /admin/');
        exit;
    }

    public function logout(): void
    {
        if (isset($_SESSION['admin_id'])) {
            $this->auditLog->log('admin', (string) $_SESSION['admin_id'], 'admin.logout', 'success');
        }

        Session::destroy();
        header('Location: /admin/login');
        exit;
    }

    public static function isAuthenticated(): bool
    {
        return isset($_SESSION['admin_id']);
    }

    public function requireAuth(): void
    {
        if (!self::isAuthenticated()) {
            header('Location: /admin/login');
            exit;
        }
    }

    /**
     * Fixed-window per-IP throttle for the login form. Counts every POST
     * (success or failure) against the client IP in the shared rate_limits
     * table so a discarded account can be brute-forced only up to the
     * per-window cap. Returns false once the cap is exceeded.
     */
    private function throttleLoginAttempt(): bool
    {
        $ip = $this->clientIp();
        $windowStart = intdiv(time(), 60) * 60;

        $count = $this->rateLimits->incrementAndGet('admin-login', $ip, $windowStart);

        return $count <= self::LOGIN_MAX_PER_MINUTE;
    }

    private function clientIp(): string
    {
        // Only trust the real remote address — never a client-supplied
        // X-Forwarded-For header on this shared-hosting layout.
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}
