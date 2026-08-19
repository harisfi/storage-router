<?php
/**
 * Shared admin layout. Usage from a controller:
 *
 *   $pageTitle = 'Storage Backends';
 *   $content = function () { require __DIR__ . '/backends/list.php'; };
 *   require __DIR__ . '/layout.php';
 *
 * All dynamic values used inside $content must already be
 * htmlspecialchars()-escaped by the view that sets it — this layout only
 * escapes $pageTitle itself.
 *
 * @var string $pageTitle
 * @var callable $content
 * @var string|null $flash
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — Storage Router Admin</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🗄️</text></svg>">
    <link rel="stylesheet" href="/admin/assets/pico.min.css">
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="site-body">
<nav class="container-fluid">
    <ul>
        <li><strong>🗄️ Storage Router</strong></li>
    </ul>
    <ul class="nav-links">
        <li><a href="/admin/">Dashboard</a></li>
        <li><a href="/admin/backends">Storage Backends</a></li>
        <li><a href="/admin/apps">Apps</a></li>
        <li><a href="/admin/files">Files</a></li>
        <li><a href="/admin/files/errors">Errors</a></li>
    </ul>
    <ul>
        <li>
            <details role="list" class="nav-menu dropdown">
                <summary aria-haspopup="listbox" role="button" class="secondary">Menu</summary>
                <ul role="listbox">
                    <li><a href="/admin/">Dashboard</a></li>
                    <li><a href="/admin/backends">Storage Backends</a></li>
                    <li><a href="/admin/apps">Apps</a></li>
                    <li><a href="/admin/files">Files</a></li>
                    <li><a href="/admin/files/errors">Errors</a></li>
                </ul>
            </details>
        </li>
    </ul>
    <ul>
        <li>
            <form class="inline-form" method="post" action="/admin/logout">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\App\Support\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
                <button role="button" type="submit" class="outline" style="margin-bottom: 0">Log out</button>
            </form>
        </li>
    </ul>
</nav>
<main class="container">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if (!empty($flash)): ?>
        <div class="alert alert-success"><?= $flash /* pre-escaped by the setting controller */ ?></div>
    <?php endif; ?>
    <?php if (!empty($flashError)): ?>
        <div class="alert alert-error"><?= $flashError /* pre-escaped by the setting controller */ ?></div>
    <?php endif; ?>
    <?php $content(); ?>
</main>
<footer class="site-footer container">
    <span class="footer-item">
        Licensed under the <a href="https://github.com/harisfi/storage-router/blob/main/LICENSE" target="_blank" rel="noopener">MIT License</a>
    </span>
    <span class="footer-item">
        Enjoying Storage Router? <a href="https://github.com/harisfi/storage-router" target="_blank" rel="noopener">★ Star it on GitHub</a>
    </span>
    <span class="footer-item">
        <a href="https://github.com/harisfi/storage-router/blob/main/CONTRIBUTING.md" target="_blank" rel="noopener">Contribute</a> ·
        <a href="https://github.com/harisfi/storage-router/issues/new?labels=enhancement" target="_blank" rel="noopener">Request a feature</a> ·
        <a href="https://github.com/harisfi/storage-router/issues/new?labels=bug" target="_blank" rel="noopener">Report a bug</a>
    </span>
</footer>
</body>
</html>