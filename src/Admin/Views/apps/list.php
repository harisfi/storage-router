<?php
/**
 * @var array<int, array<string, mixed>> $apps
 * @var array<string, array{count: int, bytes: int}> $usageByApp
 * @var \App\Support\Pagination $pagination
 */
$baseQuery = [];
?>
<p><a href="/admin/apps/new" role="button">+ Create app</a></p>

<?php if ($apps === []): ?>
    <p>No apps yet.</p>
<?php else: ?>
    <figure class="overflow-auto">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Status</th>
                <th>Files</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($apps as $app): ?>
            <?php
            $id = htmlspecialchars((string) $app['id'], ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars((string) $app['name'], ENT_QUOTES, 'UTF-8');
            $status = htmlspecialchars((string) $app['status'], ENT_QUOTES, 'UTF-8');
            $created = htmlspecialchars((string) $app['created_at'], ENT_QUOTES, 'UTF-8');
            $usage = $usageByApp[$app['id']] ?? ['count' => 0, 'bytes' => 0];
            $csrf = htmlspecialchars(\App\Support\Csrf::token(), ENT_QUOTES, 'UTF-8');
            ?>
            <tr>
                <td><?= $name ?><br><small><code><?= $id ?></code></small></td>
                <td><span class="badge badge-<?= $status ?>"><?= \App\Support\Format::statusLabel($status) ?></span></td>
                <td><?= number_format($usage['count']) ?> (<?= \App\Support\Format::humanBytes((int) $usage['bytes']) ?>)</td>
                <td><?= $created ?></td>
                <td>
                    <a href="/admin/apps/<?= $id ?>/assignments" role="button" class="secondary" data-tooltip="Manage assignments" aria-label="Manage assignments">⚙️</a>
                    <form class="inline-form" method="post" action="/admin/apps/<?= $id ?>/suspend">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <button type="submit" class="secondary" data-tooltip="<?= $status === 'active' ? 'Suspend app' : 'Reactivate app' ?>" aria-label="<?= $status === 'active' ? 'Suspend app' : 'Reactivate app' ?>"><?= $status === 'active' ? '⏸' : '▶' ?></button>
                    </form>
                    <form class="inline-form" method="post" action="/admin/apps/<?= $id ?>/rotate-key" onsubmit="return confirm('Rotate this app\'s API key? The old key stops working immediately.');">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <button type="submit" class="secondary" data-tooltip="Rotate API key" aria-label="Rotate API key">🔑</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </figure>
    <?php require __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
