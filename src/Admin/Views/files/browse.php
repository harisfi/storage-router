<?php
/**
 * @var array<int, array<string, mixed>> $files
 * @var array<int, array<string, mixed>> $apps
 * @var array<int, array<string, mixed>> $backends
 * @var array<string, string> $appNames
 * @var array<string, string> $backendLabels
 * @var array<string, string> $filters
 * @var string $csrfToken
 * @var \App\Support\Pagination $pagination
 */
$csrf = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
$baseQuery = [
    'app_id' => $filters['app_id'],
    'user_id' => $filters['user_id'],
    'mime_type' => $filters['mime_type'],
];
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
    <figure class="overflow-auto">
    <table>
        <thead>
            <tr>
                <th>File</th>
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
            $size = \App\Support\Format::humanBytes((int) $file['size_bytes']);
            $mime = htmlspecialchars((string) $file['mime_type'], ENT_QUOTES, 'UTF-8');
            $created = htmlspecialchars((string) $file['created_at'], ENT_QUOTES, 'UTF-8');
            ?>
            <tr>
                <td>
                    <div class="cell-item"><span class="cell-label">ID</span> <code><?= $fid ?></code></div>
                    <div class="cell-item"><span class="cell-label">Mime</span> <?= $mime ?></div>
                    <div class="cell-item"><span class="cell-label">Size</span> <?= $size ?></div>
                    <div class="cell-item"><span class="cell-label">App</span> <?= $appName ?></div>
                    <div class="cell-item"><span class="cell-label">Backend</span> <?= $backendLabel ?></div>
                    <div class="cell-item"><span class="cell-label">User</span> <?= $userId !== '' ? $userId : '<em>none</em>' ?></div>
                    <div class="cell-item"><span class="cell-label">Created</span> <time class="local-time" datetime="<?= $created ?>"><?= $created ?></time></div>
                </td>
                <td>
                    <form class="inline-form" method="post" action="/admin/files/<?= $fid ?>/migrate">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <select name="target_storage_id" aria-label="Target backend">
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
    </figure>
    <?php require __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
