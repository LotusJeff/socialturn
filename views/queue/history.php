<?php
/**
 * Post history view — rendered by queue/history.
 *
 * Template variables:
 *   $account      array   Account row (id, name, is_posting, platform, platform_name)
 *   $rows         array   post_history rows:
 *                           id, body_snapshot, image_filename, platform_post_id,
 *                           status, posted_at, post_id
 *   $totalCount   int     Unfiltered row count (for cap notice)
 *   $failedCount  int     Failed rows count (for Errors tab badge)
 *   $search       string  Current ?q= search value, or ''
 *   $csrfToken    string
 */

$count = count($rows);
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
    <p class="text-muted small mb-4">Immutable log of every post attempt for this account.</p>

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
                <span class="badge bg-white text-dark ms-1"><?= (int) $totalCount >= 200 ? '200+' : (int) $totalCount ?></span>
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

    <?php if ($count > 0): ?>

    <?php if ((int) $totalCount >= 200 && $search === ''): ?>
    <p class="text-muted small mb-3">Showing the 200 most recent entries.</p>
    <?php elseif ($search !== ''): ?>
    <p class="text-muted small mb-3">
        <?= $count ?> <?= $count === 1 ? 'result' : 'results' ?> for
        &ldquo;<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>&rdquo;
    </p>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width:160px">Posted</th>
                    <th>Post</th>
                    <th style="width:90px">Status</th>
                    <th style="width:90px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
            <?php
                $preview  = mb_strlen((string) $row['body_snapshot']) > 120
                    ? mb_substr((string) $row['body_snapshot'], 0, 120) . '…'
                    : (string) $row['body_snapshot'];
                $isPosted = (string) $row['status'] === 'posted';
            ?>
            <tr>
                <td class="text-muted small text-nowrap">
                    <?= htmlspecialchars(datify((string) $row['posted_at']), ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td class="small">
                    <div style="white-space:pre-line"><?= htmlspecialchars($preview, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!empty($row['image_filename'])): ?>
                    <div class="mt-1"><span class="badge bg-light text-dark border">Has image</span></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($isPosted): ?>
                    <span class="badge bg-success">Posted</span>
                    <?php else: ?>
                    <span class="badge bg-danger">Failed</span>
                    <?php endif; ?>
                </td>
                <td class="text-end">
                    <?php if ($isPosted && !empty($row['platform_post_id'])): ?>
                    <span class="text-muted small"
                          title="Platform post ID: <?= htmlspecialchars((string) $row['platform_post_id'], ENT_QUOTES, 'UTF-8') ?>">
                        #<?= htmlspecialchars(substr((string) $row['platform_post_id'], 0, 8), ENT_QUOTES, 'UTF-8') ?>&hellip;
                    </span>
                    <?php elseif (!$isPosted): ?>
                    <a href="<?= u('queue', 'errors', ['id' => (int) $account['id']]) ?>"
                       class="btn btn-sm btn-outline-danger">Details</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

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
