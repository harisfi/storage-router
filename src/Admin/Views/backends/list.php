<?php
/** @var array<int, array<string, mixed>> $backends */
?>
<p>
    <a href="/admin/backends/add-local">+ Add local backend</a>
    &nbsp;|&nbsp;
    <a href="/admin/storage-backends/google/connect">+ Connect Google Drive</a>
</p>

<?php if ($backends === []): ?>
    <p>No storage backends yet.</p>
<?php else: ?>
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
                <td><?= $type ?></td>
                <td><span class="badge badge-<?= $status ?>"><?= $status ?></span></td>
                <td><?= number_format($used) ?> / <?= $total > 0 ? number_format($total) : 'uncapped/unknown' ?></td>
                <td>
                    <form class="inline" method="post" action="/admin/backends/<?= $id ?>/toggle">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <button type="submit"><?= $status === 'enabled' ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <form class="inline" method="post" action="/admin/backends/<?= $id ?>/refresh-quota">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <button type="submit">Refresh quota</button>
                    </form>
                    <form class="inline" method="post" action="/admin/backends/<?= $id ?>/remove" onsubmit="return confirm('Remove this backend? Only possible if it has never held any file.');">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <button type="submit">Remove</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
