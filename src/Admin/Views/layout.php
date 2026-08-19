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
    <link rel="stylesheet" href="/admin/assets/pico.min.css">
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body>
<nav class="container-fluid">
    <ul>
        <li><strong>Storage Router</strong></li>
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
</body>
</html>