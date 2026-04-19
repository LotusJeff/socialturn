<?php
/**
 * Pagination controls partial — include above and below any paginated table or list.
 *
 * Variables expected in scope via extract() or local assignment:
 *   $page             int    Current page number
 *   $totalPages       int    Total page count
 *   $perPage          int    Current page size (25|50|100)
 *   $totalItems       int    Total row count
 *   $paginationParams array  Stable URL params (c, a, filters) — no page/per_page
 */

if ($totalItems < 25) {
    return;
}

$_pag_url  = fn(int $p, int $pp): string =>
    BASE_URL . 'index.php?' . http_build_query(array_merge($paginationParams, ['page' => $p, 'per_page' => $pp]));

$_pag_from = $totalItems > 0 ? (($page - 1) * $perPage) + 1 : 0;
$_pag_to   = min($page * $perPage, $totalItems);
?>
<div class="d-flex align-items-center justify-content-between py-2">

    <!-- Left: result count -->
    <div class="text-start">
        <?php if ($totalItems > 0): ?>
        <span class="text-muted small">
            Showing <?= $_pag_from ?>–<?= $_pag_to ?> of <?= $totalItems ?>
        </span>
        <?php endif; ?>
    </div>

    <!-- Center: First / Prev / Page X of Y / Next / Last -->
    <div class="flex-grow-1 d-flex justify-content-center">
        <nav aria-label="Page navigation">
            <ul class="pagination pagination-sm mb-0">

                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <?php if ($page > 1): ?>
                    <a class="page-link"
                       href="<?= htmlspecialchars($_pag_url(1, $perPage), ENT_QUOTES, 'UTF-8') ?>"
                       aria-label="First">&laquo;</a>
                    <?php else: ?>
                    <span class="page-link">&laquo;</span>
                    <?php endif; ?>
                </li>

                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <?php if ($page > 1): ?>
                    <a class="page-link"
                       href="<?= htmlspecialchars($_pag_url($page - 1, $perPage), ENT_QUOTES, 'UTF-8') ?>">&#8249;&nbsp;Prev</a>
                    <?php else: ?>
                    <span class="page-link">&#8249;&nbsp;Prev</span>
                    <?php endif; ?>
                </li>

                <li class="page-item disabled">
                    <span class="page-link">Page&nbsp;<?= $page ?>&nbsp;of&nbsp;<?= $totalPages ?></span>
                </li>

                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <?php if ($page < $totalPages): ?>
                    <a class="page-link"
                       href="<?= htmlspecialchars($_pag_url($page + 1, $perPage), ENT_QUOTES, 'UTF-8') ?>">Next&nbsp;&#8250;</a>
                    <?php else: ?>
                    <span class="page-link">Next&nbsp;&#8250;</span>
                    <?php endif; ?>
                </li>

                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <?php if ($page < $totalPages): ?>
                    <a class="page-link"
                       href="<?= htmlspecialchars($_pag_url($totalPages, $perPage), ENT_QUOTES, 'UTF-8') ?>"
                       aria-label="Last">&raquo;</a>
                    <?php else: ?>
                    <span class="page-link">&raquo;</span>
                    <?php endif; ?>
                </li>

            </ul>
        </nav>
    </div>

    <!-- Right: per-page size links -->
    <div class="text-end d-flex align-items-center gap-1">
        <span class="text-muted small">Per page:</span>
        <?php foreach ([25, 50, 100] as $_pp): ?>
        <a href="<?= htmlspecialchars($_pag_url(1, $_pp), ENT_QUOTES, 'UTF-8') ?>"
           class="btn btn-sm <?= $perPage === $_pp ? 'btn-secondary' : 'btn-outline-secondary' ?>">
            <?= $_pp ?>
        </a>
        <?php endforeach; ?>
    </div>

</div>
