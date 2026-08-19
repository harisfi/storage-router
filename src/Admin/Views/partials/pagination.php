<?php
/**
 * Shared pagination controls: a per-page size limiter (5/10/25/50/100) and
 * prev/page/next links, preserving any base query params (e.g. filters).
 *
 * Usage after a table:
 *
 *   $pagination    = \App\Support\Pagination instance
 *   $baseQuery     = array<string, string|int> extra params to preserve
 *   require __DIR__ . '/partials/pagination.php';
 *
 * @var \App\Support\Pagination $pagination
 * @var array<string, string|int> $baseQuery
 */
// Only render when there is at least one result.
if ($pagination->total() <= 0) {
    return;
}

$link = static function (int $page) use ($pagination, $baseQuery): string {
    $q = $baseQuery;
    $q['page'] = $page;
    $q['per_page'] = $pagination->perPage();

    return '?' . http_build_query($q);
};
$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<nav class="pagination" aria-label="Pagination">
    <form method="get" class="inline-form per-page">
        <?php foreach ($baseQuery as $bk => $bv): ?>
            <input type="hidden" name="<?= $esc($bk) ?>" value="<?= $esc($bv) ?>">
        <?php endforeach; ?>
        <input type="hidden" name="page" value="1">
        <label for="per-page">Per page
            <select id="per-page" name="per_page" onchange="this.form.submit()">
                <?php foreach (\App\Support\Pagination::PER_PAGE_OPTIONS as $opt): ?>
                    <option value="<?= $opt ?>" <?= $pagination->perPage() === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>

    <ul class="pager">
        <?php if ($pagination->hasPrev()): ?>
            <li><a href="<?= $esc($link((int) $pagination->prev())) ?>" aria-label="Previous page">‹</a></li>
        <?php endif; ?>
        <?php foreach ($pagination->pageNumbers() as $pn): ?>
            <?php if ($pn === '…'): ?>
                <li><span class="ellipsis">…</span></li>
            <?php else: ?>
                <li>
                    <?php if ($pn === $pagination->current()): ?>
                        <span class="current" aria-current="page"><?= $pn ?></span>
                    <?php else: ?>
                        <a href="<?= $esc($link((int) $pn)) ?>"><?= $pn ?></a>
                    <?php endif; ?>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($pagination->hasNext()): ?>
            <li><a href="<?= $esc($link((int) $pagination->next())) ?>" aria-label="Next page">›</a></li>
        <?php endif; ?>
    </ul>

    <span class="pagination-info">
        Showing <?= $pagination->from() ?>–<?= $pagination->to() ?> of <?= number_format($pagination->total()) ?>
    </span>
</nav>