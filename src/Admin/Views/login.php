<?php
/**
 * @var string $csrfToken
 * @var string|null $error
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>Storage Router — Admin Login</title>
    <link rel="stylesheet" href="/admin/assets/pico.min.css">
    <link rel="stylesheet" href="/admin/assets/admin.css">
    <style>
        body > main { max-width: 420px; margin: 10vh auto; }
    </style>
</head>
<body class="site-body">
    <main class="container">
        <article>
            <h1>Admin Login</h1>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post" action="/admin/login">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus autocomplete="username">

                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">

                <button type="submit" class="primary">Log in</button>
            </form>
        </article>
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