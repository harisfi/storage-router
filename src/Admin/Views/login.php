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
    <title>Storage Router — Admin Login</title>
</head>
<body>
    <h1>Admin Login</h1>

    <?php if (!empty($error)): ?>
        <p style="color: red;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/login">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <label>
            Username
            <input type="text" name="username" required autofocus autocomplete="username">
        </label>
        <br>
        <label>
            Password
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <br>
        <button type="submit">Log in</button>
    </form>
</body>
</html>
