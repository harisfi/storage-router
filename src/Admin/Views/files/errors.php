<?php
/** @var array<int, array<string, mixed>> $errors */
/** @var array<string, string> $actorNames resolved actor ids → display names */
/** @var \App\Support\Pagination $pagination */
$baseQuery = [];
?>
<?php if ($errors === []): ?>
    <p>No operational errors logged.</p>
<?php else: ?>
    <figure class="overflow-auto">
    <table>
        <thead>
            <tr>
                <th>When</th>
                <th>Actor</th>
                <th>Action</th>
                <th>Target</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($errors as $err): ?>
            <?php
            $when = htmlspecialchars((string) $err['created_at'], ENT_QUOTES, 'UTF-8');
            $actorIdRaw = (string) $err['actor_id'];
            $actorDisplay = $actorNames[$actorIdRaw] ?? $actorIdRaw;
            $actor = htmlspecialchars(
                \App\Support\Format::actorLabel((string) $err['actor_type'], $actorDisplay),
                ENT_QUOTES,
                'UTF-8'
            );
            $action = htmlspecialchars((string) $err['action'], ENT_QUOTES, 'UTF-8');
            $actionLabel = htmlspecialchars(\App\Support\Format::actionLabel((string) $err['action']), ENT_QUOTES, 'UTF-8');
            $target = htmlspecialchars((string) ($err['target_id'] ?? ''), ENT_QUOTES, 'UTF-8');
            $metadata = $err['metadata'] ? json_decode((string) $err['metadata'], true) : null;
            $metadataStr = $metadata !== null ? htmlspecialchars(json_encode($metadata, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') : '';
            ?>
            <tr>
                <td><?= $when ?></td>
                <td><?= $actor ?></td>
                <td><span class="badge badge-error" data-tooltip="<?= $action ?>"><?= $actionLabel ?></span></td>
                <td><code><?= $target ?></code></td>
                <td><code style="font-size:0.8rem;"><?= $metadataStr ?></code></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </figure>
    <?php require __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
