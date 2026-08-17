<?php
/**
 * @var string $username
 * @var string $csrfToken
 */
$pageTitle = 'Dashboard';
$content = function () use ($username): void {
    ?>
    <p>Welcome, <?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>.</p>
    <p>
        <a href="/admin/backends">Manage storage backends</a> —
        <a href="/admin/apps">manage apps</a> —
        <a href="/admin/files">browse files</a> —
        <a href="/admin/files/errors">view errors</a>
    </p>
    <?php
};
require __DIR__ . '/layout.php';
