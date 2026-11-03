<?php
/**
 * Shared pagination nav for admin listings.
 *
 * Contract — the caller sets these BEFORE the include (the partial never
 * reads superglobals):
 *   @var int   $page       current page (1-based, already clamped by the Paginator)
 *   @var int   $totalPages total pages (>= 1)
 *   @var array $query      current query params WITHOUT 'page' (preserved in hrefs)
 *
 * Relative hrefs ("?page=N&…") resolve against the current path — no
 * screen needs to supply a baseUrl. Compact window: first, last, ±2
 * neighborhood around the current page, ellipses between non-contiguous
 * blocks.
 */
if (($totalPages ?? 1) > 1):
    $pgWindow = [1];
    for ($pgI = max(2, $page - 2); $pgI <= min($totalPages - 1, $page + 2); $pgI++) {
        $pgWindow[] = $pgI;
    }
    $pgWindow[] = $totalPages;
?>
<nav class="pagination">
    <?php $pgPrev = 0; foreach ($pgWindow as $pgN): ?>
        <?php if ($pgN > $pgPrev + 1): ?><span class="pagination__gap">…</span><?php endif; ?>
        <a class="pagination__link <?= $pgN === $page ? 'pagination__link--active' : '' ?>"
           href="?<?= e(http_build_query(array_merge($query ?? [], ['page' => $pgN]))) ?>"><?= $pgN ?></a>
        <?php $pgPrev = $pgN; ?>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
