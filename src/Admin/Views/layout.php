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
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — Storage Router Admin</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; color: #1a1a1a; }
        nav { background: #1a1a1a; padding: 0.75rem 1.5rem; display: flex; gap: 1.25rem; align-items: center; }
        nav a { color: #eee; text-decoration: none; font-size: 0.95rem; }
        nav a:hover { text-decoration: underline; }
        nav .spacer { flex: 1; }
        main { padding: 1.5rem; max-width: 1000px; margin: 0 auto; }
        table { border-collapse: collapse; width: 100%; margin: 1rem 0; }
        th, td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid #ddd; font-size: 0.9rem; }
        th { background: #f5f5f5; }
        .flash { background: #eaf7ea; border: 1px solid #a8d8a8; padding: 0.6rem 1rem; border-radius: 4px; margin-bottom: 1rem; }
        .error { background: #fdeaea; border: 1px solid #e0a8a8; padding: 0.6rem 1rem; border-radius: 4px; margin-bottom: 1rem; }
        .badge { display: inline-block; padding: 0.1rem 0.5rem; border-radius: 3px; font-size: 0.8rem; }
        .badge-enabled, .badge-active { background: #dff0d8; color: #3c763d; }
        .badge-disabled, .badge-suspended, .badge-error { background: #f2dede; color: #a94442; }
        form.inline { display: inline; }
        input[type=text], input[type=number] { padding: 0.3rem; }
        button, input[type=submit] { padding: 0.35rem 0.8rem; cursor: pointer; }
        code { background: #f5f5f5; padding: 0.1rem 0.35rem; border-radius: 3px; font-size: 0.85rem; }
    </style>
</head>
<body>
<nav>
    <a href="/admin/">Dashboard</a>
    <a href="/admin/backends">Storage Backends</a>
    <a href="/admin/apps">Apps</a>
    <a href="/admin/files">Files</a>
    <a href="/admin/files/errors">Errors</a>
    <span class="spacer"></span>
    <form class="inline" method="post" action="/admin/logout">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\App\Support\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">Log out</button>
    </form>
</nav>
<main>
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if (!empty($flash)): ?>
        <div class="flash"><?= $flash /* pre-escaped by the setting controller */ ?></div>
    <?php endif; ?>
    <?php if (!empty($flashError)): ?>
        <div class="error"><?= $flashError /* pre-escaped by the setting controller */ ?></div>
    <?php endif; ?>
    <?php $content(); ?>
</main>
</body>
</html>
