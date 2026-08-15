<?php

declare(strict_types=1);

namespace App\Admin\Controllers;

use App\Data\Repositories\AdminRepository;
use App\Data\Repositories\AuditLogRepository;
use App\Support\Csrf;
use App\Support\Session;

final class AuthController
{
    public function __construct(
        private AdminRepository $admins,
        private AuditLogRepository $auditLog
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
}
