<?php
/**
 * @var array<int, array<string, mixed>> $files
 * @var array<int, array<string, mixed>> $apps
 * @var array<int, array<string, mixed>> $backends
 * @var array<string, string> $appNames
 * @var array<string, string> $backendLabels
 * @var array<string, string> $filters
 * @var string $csrfToken
 */
$csrf = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
?>
<form method="get" action="/admin/files">
    <label>App:
        <select name="app_id">
            <option value="">All</option>
            <?php foreach ($apps as $app): ?>
                <option value="<?= htmlspecialchars((string) $app['id'], ENT_QUOTES, 'UTF-8') ?>" <?= ($filters['app_id'] === $app['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) $app['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    &nbsp;
    <label>User ID: <input type="text" name="user_id" value="<?= htmlspecialchars($filters['user_id'], ENT_QUOTES, 'UTF-8') ?>"></label>
    &nbsp;
    <label>Mime type: <input type="text" name="mime_type" value="<?= htmlspecialchars($filters['mime_type'], ENT_QUOTES, 'UTF-8') ?>"></label>
    &nbsp;
    <button type="submit">Filter</button>
</form>

<?php if ($files === []): ?>
    <p>No files match.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>File ID</th>
                <th>App</th>
                <th>User</th>
                <th>Backend</th>
                <th>Size</th>
                <th>Mime</th>
                <th>Created</th>
                <th>Migrate to</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($files as $file): ?>
            <?php
            $fid = htmlspecialchars((string) $file['id'], ENT_QUOTES, 'UTF-8');
            $appName = htmlspecialchars($appNames[$file['app_id']] ?? (string) $file['app_id'], ENT_QUOTES, 'UTF-8');
            $userId = htmlspecialchars((string) ($file['user_id'] ?? ''), ENT_QUOTES, 'UTF-8');
            $backendLabel = htmlspecialchars($backendLabels[$file['storage_id']] ?? (string) $file['storage_id'], ENT_QUOTES, 'UTF-8');
            $size = number_format((int) $file['size_bytes']);
            $mime = htmlspecialchars((string) $file['mime_type'], ENT_QUOTES, 'UTF-8');
            $created = htmlspecialchars((string) $file['created_at'], ENT_QUOTES, 'UTF-8');
            ?>
            <tr>
                <td><code><?= $fid ?></code></td>
                <td><?= $appName ?></td>
                <td><?= $userId !== '' ? $userId : '<em>none</em>' ?></td>
                <td><?= $backendLabel ?></td>
                <td><?= $size ?></td>
                <td><?= $mime ?></td>
                <td><?= $created ?></td>
                <td>
                    <form class="inline" method="post" action="/admin/files/<?= $fid ?>/migrate">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <select name="target_storage_id">
                            <?php foreach ($backends as $b): ?>
                                <?php if ($b['id'] === $file['storage_id']) { continue; } ?>
                                <option value="<?= htmlspecialchars((string) $b['id'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars((string) $b['label'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit">Go</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
