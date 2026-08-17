<?php
/**
 * @var array<int, array<string, mixed>> $apps
 * @var array<string, array{count: int, bytes: int}> $usageByApp
 */
?>
<p><a href="/admin/apps/new">+ Create app</a></p>

<?php if ($apps === []): ?>
    <p>No apps yet.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Status</th>
                <th>Files</th>
                <th>Total bytes</th>
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
                <td><span class="badge badge-<?= $status ?>"><?= $status ?></span></td>
                <td><?= number_format($usage['count']) ?></td>
                <td><?= number_format($usage['bytes']) ?></td>
                <td><?= $created ?></td>
                <td>
                    <a href="/admin/apps/<?= $id ?>/assignments">Assignments</a>
                    <form class="inline" method="post" action="/admin/apps/<?= $id ?>/suspend">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <button type="submit"><?= $status === 'active' ? 'Suspend' : 'Reactivate' ?></button>
                    </form>
                    <form class="inline" method="post" action="/admin/apps/<?= $id ?>/rotate-key" onsubmit="return confirm('Rotate this app\'s API key? The old key stops working immediately.');">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <button type="submit">Rotate key</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
