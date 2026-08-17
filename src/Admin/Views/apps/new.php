<?php
/** @var string $csrfToken */
?>
<form method="post" action="/admin/apps/new">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <p>
        <label>App name<br>
            <input type="text" name="name" required placeholder="e.g. Mobile App">
        </label>
    </p>
    <button type="submit">Create</button>
    <a href="/admin/apps">Cancel</a>
</form>
