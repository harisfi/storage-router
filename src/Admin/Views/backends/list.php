<?php
/** @var array<int, array<string, mixed>> $backends */
?>
<p>
    <a href="/admin/backends/add-local" role="button">+ Add local backend</a>
    <a href="/admin/storage-backends/google/connect" role="button" class="secondary">+ Connect Google Drive</a>
</p>

<?php if ($backends === []): ?>
    <p>No storage backends yet.</p>
<?php else: ?>
    <figure class="overflow-auto">
    <table>
        <thead>
            <tr>
                <th>Label</th>
                <th>Type</th>
                <th>Status</th>
                <th>Quota (used / total)</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($backends as $backend): ?>
            <?php
            $id = htmlspecialchars((string) $backend['id'], ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars((string) $backend['label'], ENT_QUOTES, 'UTF-8');
            $type = htmlspecialchars((string) $backend['provider_type'], ENT_QUOTES, 'UTF-8');
            $status = htmlspecialchars((string) $backend['status'], ENT_QUOTES, 'UTF-8');
            $used = (int) $backend['quota_used_bytes'];
            $total = (int) $backend['quota_total_bytes'];
            $csrf = htmlspecialchars(\App\Support\Csrf::token(), ENT_QUOTES, 'UTF-8');
            ?>
            <tr>
                <td><?= $label ?><br><small><code><?= $id ?></code></small></td>
                <td><?= ucwords(str_replace('_', ' ', $type)) ?></td>
                <td><span class="badge badge-<?= $status ?>"><?= ucfirst($status) ?></span></td>
                <td>
                    <?php if ($total > 0): ?>
                        <span class="quota-text"><?= \App\Support\Format::humanBytes((int) $used) ?> / <?= \App\Support\Format::humanBytes((int) $total) ?> (<?= \App\Support\Format::percent((int) $used, (int) $total) ?>%)</span>
                        <progress value="<?= $used ?>" max="<?= $total ?>"></progress>
                    <?php else: ?>
                        <?= \App\Support\Format::humanBytes((int) $used) ?> / unknown
                    <?php endif; ?>
                </td>
<td>
                    <form class="inline-form" method="post" action="/admin/backends/<?= $id ?>/toggle">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <button type="submit" class="secondary" data-tooltip="<?= $status === 'enabled' ? 'Disable' : 'Enable' ?>" aria-label="<?= $status === 'enabled' ? 'Disable' : 'Enable' ?>"><?= $status === 'enabled' ? '⏸' : '▶' ?></button>
                    </form>
                    <form class="inline-form" method="post" action="/admin/backends/<?= $id ?>/refresh-quota">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <button type="submit" class="secondary" data-tooltip="Refresh quota" aria-label="Refresh quota">🔄</button>
                    </form>
                    <form class="inline-form" method="post" action="/admin/backends/<?= $id ?>/remove" onsubmit="return confirm('Remove this backend? Only possible if it has never held any file.');">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <button type="submit" class="secondary" data-tooltip="Remove" aria-label="Remove backend">🗑️</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </figure>
<?php endif; ?>
