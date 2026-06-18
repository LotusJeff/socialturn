<?php
/**
 * Post history view — rendered by queue/history.
 *
 * Template variables:
 *   $account          array   Account row (id, name, is_posting, platform, platform_name)
 *   $rows             array   post_history rows (current page):
 *                               id, body_snapshot, image_filenames, platform_post_id,
 *                               status, posted_at, post_id
 *   $totalCount       int     Filter-aware count (for tab badge and pagination)
 *   $failedCount      int     Unfiltered failed count (for Errors tab badge)
 *   $search           string  Current ?q= search value, or ''
 *   $page             int
 *   $perPage          int
 *   $totalPages       int
 *   $totalItems       int     Same as $totalCount
 *   $paginationParams array
 *   $csrfToken        string
 */
?>
<div class="container py-4" style="max-width:900px">

    <div class="d-flex align-items-center mb-1 gap-3">
        <a href="<?= u('queue', 'view', ['id' => (int) $account['id']]) ?>"
           class="text-muted text-decoration-none">&larr; Queue</a>
        <h1 class="h3 mb-0">
            <?= htmlspecialchars((string) $account['name'], ENT_QUOTES, 'UTF-8') ?>
            &mdash; History
        </h1>
    </div>
    <p class="text-muted small mb-4">Immutable log of every post attempt for this workspace.</p>

    <?php if (!empty($_SESSION['notification'])): ?>
    <div class="alert alert-<?= htmlspecialchars($_SESSION['notification']['type'] === 'error' ? 'danger' : $_SESSION['notification']['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars((string) $_SESSION['notification']['message'], ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['notification']); endif; ?>

    <!-- Tabs + Search -->
    <div class="d-flex flex-wrap gap-2 align-items-end justify-content-between mb-3">

        <div class="d-flex gap-2">
            <a href="<?= u('queue', 'history', array_merge(['id' => (int) $account['id']], $search !== '' ? ['q' => $search] : [])) ?>"
               class="btn btn-sm btn-primary">
                All history
                <?php if ((int) $totalCount > 0): ?>
                <span class="badge bg-white text-dark ms-1"><?= (int) $totalCount ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= u('queue', 'errors', array_merge(['id' => (int) $account['id']], $search !== '' ? ['q' => $search] : [])) ?>"
               class="btn btn-sm <?= (int) $failedCount > 0 ? 'btn-outline-danger' : 'btn-outline-secondary' ?>">
                Errors
                <?php if ((int) $failedCount > 0): ?>
                <span class="badge bg-danger ms-1"><?= (int) $failedCount ?></span>
                <?php endif; ?>
            </a>
        </div>

        <form method="GET" action="<?= BASE_URL ?>index.php"
              class="d-flex gap-2 align-items-end">
            <input type="hidden" name="c" value="queue">
            <input type="hidden" name="a" value="history">
            <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
            <input type="text" name="q" class="form-control form-control-sm"
                   style="max-width:240px"
                   placeholder="Search post text&hellip;"
                   value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary">Search</button>
            <?php if ($search !== ''): ?>
            <a href="<?= u('queue', 'history', ['id' => (int) $account['id']]) ?>"
               class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>

    </div>

    <?php if ((int) $totalItems > 0): ?>

    <?php include ROOT . DS . 'views' . DS . 'partials' . DS . 'pagination.php'; ?>

    <div class="list-group">
        <?php foreach ($rows as $row): ?>
        <?php
            $preview  = mb_strlen((string) $row['body_snapshot']) > 120
                ? mb_substr((string) $row['body_snapshot'], 0, 120) . '…'
                : (string) $row['body_snapshot'];
            $isPosted = (string) $row['status'] === 'posted';
        ?>
        <div class="list-group-item px-3 py-2">
            <div class="d-flex align-items-center gap-2">

                <!-- Posted date -->
                <span class="text-muted small text-nowrap flex-shrink-0">
                    <?= htmlspecialchars(datify((string) $row['posted_at'], (string) $account['timezone']), ENT_QUOTES, 'UTF-8') ?>
                </span>

                <!-- Status badge -->
                <span class="badge <?= $isPosted ? 'bg-success' : 'bg-danger' ?> flex-shrink-0">
                    <?= $isPosted ? 'Posted' : 'Failed' ?>
                </span>

                <!-- Body + Has image badge — flexible middle -->
                <div class="flex-grow-1 small text-truncate" style="min-width:0">
                    <?= htmlspecialchars($preview, ENT_QUOTES, 'UTF-8') ?>
                    <?php if (!empty($row['image_filenames'])): ?>
                    <span class="badge bg-light text-dark border ms-1">Has image</span>
                    <?php endif; ?>
                </div>

                <!-- Right: platform post ID or Details link -->
                <div class="flex-shrink-0">
                    <?php if ($isPosted && !empty($row['platform_post_id'])): ?>
                    <span class="text-muted small"
                          title="Platform post ID: <?= htmlspecialchars((string) $row['platform_post_id'], ENT_QUOTES, 'UTF-8') ?>">
                        #<?= htmlspecialchars(substr((string) $row['platform_post_id'], 0, 8), ENT_QUOTES, 'UTF-8') ?>&hellip;
                    </span>
                    <?php elseif (!$isPosted): ?>
                    <a href="<?= u('queue', 'errors', ['id' => (int) $account['id']]) ?>"
                       class="btn btn-sm btn-outline-danger">Details</a>
                    <?php endif; ?>
                </div>

            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php include ROOT . DS . 'views' . DS . 'partials' . DS . 'pagination.php'; ?>

    <?php else: ?>

    <div class="card text-center py-5">
        <div class="card-body">
            <h5 class="card-title text-muted">No history yet</h5>
            <p class="card-text text-muted small mb-0">
                <?php if ($search !== ''): ?>
                No posts match &ldquo;<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>&rdquo;.
                <br><a href="<?= u('queue', 'history', ['id' => (int) $account['id']]) ?>" class="text-decoration-none">Clear search</a>
                <?php else: ?>
                Sent posts will appear here after the first cron run.
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php endif; ?>

</div>
