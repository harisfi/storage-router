<?php
/** @var string $csrfToken */
?>
<form method="post" action="/admin/backends/add-local">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <p>
        <label>Label<br>
            <input type="text" name="label" required placeholder="e.g. Local backend 1">
        </label>
    </p>
    <p>
        <label>Capacity cap (bytes)<br>
            <input type="number" name="capacity_cap_bytes" required min="1" placeholder="e.g. 5368709120 for 5GB">
        </label>
    </p>
    <button type="submit">Create</button>
    <a href="/admin/backends">Cancel</a>
</form>
