<?php
/** @var string $csrfToken */
?>
<form method="post" action="/admin/backends/add-local">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <label for="label">Label</label>
    <input type="text" id="label" name="label" required placeholder="e.g. Local backend 1">

    <label for="capacity">Capacity cap</label>
    <div class="grid">
        <input type="number" id="capacity" name="capacity" required min="0.000001" step="any" placeholder="e.g. 5">
        <select name="capacity_unit" aria-label="Capacity unit">
            <option value="B">B</option>
            <option value="KB">KB</option>
            <option value="MB">MB</option>
            <option value="GB" selected>GB</option>
            <option value="TB">TB</option>
        </select>
    </div>
    <button type="submit">Create</button>
    <a href="/admin/backends" role="button" class="secondary">Cancel</a>
</form>