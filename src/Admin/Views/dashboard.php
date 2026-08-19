<?php
/**
 * Dashboard content (rendered inside the shared layout by DashboardController).
 *
 * @var string $username
 * @var array<string, int> $stats keys: apps_total, apps_active, backends_total,
 *       backends_enabled, files_count, files_bytes, errors_count,
 *       storage_used, storage_total
 */
use App\Support\Format;

$esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$usagePercent = Format::percent($stats['storage_used'], $stats['storage_total']);
$hasCapacity = $stats['storage_total'] > 0;
?>
<p>Welcome, <?= $esc($username) ?>.</p>

<section class="stat-grid">
    <a class="stat-card" href="/admin/apps">
        <div class="stat-body">
            <span class="stat-value"><?= number_format($stats['apps_total']) ?></span>
            <span class="stat-label">Apps</span>
            <span class="stat-sub"><?= number_format($stats['apps_active']) ?> active</span>
        </div>
        <span class="stat-icon" aria-hidden="true">📱</span>
    </a>

    <a class="stat-card" href="/admin/backends">
        <div class="stat-body">
            <span class="stat-value"><?= number_format($stats['backends_total']) ?></span>
            <span class="stat-label">Storage backends</span>
            <span class="stat-sub"><?= number_format($stats['backends_enabled']) ?> enabled</span>
        </div>
        <span class="stat-icon" aria-hidden="true">🗄️</span>
    </a>

    <a class="stat-card" href="/admin/files">
        <div class="stat-body">
            <span class="stat-value"><?= number_format($stats['files_count']) ?></span>
            <span class="stat-label">Files</span>
            <span class="stat-sub"><?= Format::humanBytes($stats['files_bytes']) ?> stored</span>
        </div>
        <span class="stat-icon" aria-hidden="true">📁</span>
    </a>

    <a class="stat-card <?= $stats['errors_count'] > 0 ? 'stat-error' : '' ?>" href="/admin/files/errors">
        <div class="stat-body">
            <span class="stat-value"><?= number_format($stats['errors_count']) ?></span>
            <span class="stat-label">Operational errors</span>
            <span class="stat-sub"><?= $stats['errors_count'] > 0 ? 'from the audit log' : 'no logged failures' ?></span>
        </div>
        <span class="stat-icon" aria-hidden="true">⚠️</span>
    </a>

    <a class="stat-card stat-usage" href="/admin/backends">
        <div class="stat-body">
            <span class="stat-value"><?= $hasCapacity ? $usagePercent . '%' : '—' ?></span>
            <span class="stat-label">Storage usage</span>
            <?php if ($hasCapacity): ?>
                <progress value="<?= $stats['storage_used'] ?>" max="<?= $stats['storage_total'] ?>"></progress>
                <span class="stat-sub"><?= Format::humanBytes($stats['storage_used']) ?> of <?= Format::humanBytes($stats['storage_total']) ?></span>
            <?php else: ?>
                <span class="stat-sub">No backend has a set capacity</span>
            <?php endif; ?>
        </div>
        <span class="stat-icon" aria-hidden="true">📊</span>
    </a>
</section>