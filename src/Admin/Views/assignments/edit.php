<?php
/**
 * @var array<string, mixed> $app
 * @var array<int, array<string, mixed>> $backends
 * @var string $csrfToken
 */
$appId = htmlspecialchars((string) $app['id'], ENT_QUOTES, 'UTF-8');
?>
<p><a href="/admin/apps">&larr; Back to apps</a></p>

<?php if ($backends === []): ?>
    <p>No storage backends exist yet. <a href="/admin/backends/add-local">Add one</a> first.</p>
<?php else: ?>
    <form method="post" action="/admin/apps/<?= $appId ?>/assignments">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <figure class="overflow-auto">
        <table>
            <thead>
                <tr>
                    <th>Enabled</th>
                    <th>Backend</th>
                    <th>Type</th>
                    <th>Priority (lower = tried first on ties)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($backends as $b): ?>
                <?php
                $sid = htmlspecialchars((string) $b['storage_id'], ENT_QUOTES, 'UTF-8');
                $label = htmlspecialchars((string) $b['label'], ENT_QUOTES, 'UTF-8');
                $type = htmlspecialchars((string) $b['provider_type'], ENT_QUOTES, 'UTF-8');
                $enabled = (int) $b['enabled'] === 1;
                $priority = (int) $b['priority'];
                $backendDisabled = $b['backend_status'] !== 'enabled';
                ?>
                <tr>
                    <td>
                        <input type="checkbox" name="backends[<?= $sid ?>][enabled]" value="1" <?= $enabled ? 'checked' : '' ?> <?= $backendDisabled ? 'disabled title="This backend itself is disabled globally"' : '' ?>>
                    </td>
                    <td><?= $label ?> <?= $backendDisabled ? '<span class="badge badge-disabled">globally disabled</span>' : '' ?></td>
                    <td><?= $type ?></td>
                    <td><input type="number" name="backends[<?= $sid ?>][priority]" value="<?= $priority ?>" aria-label="Priority for <?= $sid ?>"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </figure>
        <button type="submit">Save assignments</button>
    </form>
<?php endif; ?>
