<?php
/**
 * Failed posts view — rendered by queue/errors.
 *
 * Template variables:
 *   $account     array   Account row (id, name, is_posting, platform, platform_name)
 *   $rows        array   Failed post_history rows:
 *                          id, body_snapshot, image_filename,
 *                          error_message, posted_at, post_id
 *   $totalCount  int     Unfiltered failed count (for heading and cap notice)
 *   $search      string  Current ?q= search value, or ''
 *   $csrfToken   string
 */

$count = count($rows);
?>
<div class="container py-4" style="max-width:900px">

    <div class="d-flex align-items-center mb-1 gap-3">
        <a href="<?= u('queue', 'view', ['id' => (int) $account['id']]) ?>"
           class="text-muted text-decoration-none">&larr; Queue</a>
        <h1 class="h3 mb-0">
            <?= htmlspecialchars((string) $account['name'], ENT_QUOTES, 'UTF-8') ?>
            &mdash; Errors
        </h1>
    </div>
    <p class="text-muted small mb-4">
        Failed post attempts. Check error messages and platform connection status.
    </p>

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
               class="btn btn-sm btn-outline-secondary">All history</a>
            <a href="<?= u('queue', 'errors', array_merge(['id' => (int) $account['id']], $search !== '' ? ['q' => $search] : [])) ?>"
               class="btn btn-sm btn-danger">
                Errors
                <?php if ((int) $totalCount > 0): ?>
                <span class="badge bg-white text-dark ms-1"><?= (int) $totalCount >= 200 ? '200+' : (int) $totalCount ?></span>
                <?php endif; ?>
            </a>
        </div>

        <form method="GET" action="<?= BASE_URL ?>index.php"
              class="d-flex gap-2 align-items-end">
            <input type="hidden" name="c" value="queue">
            <input type="hidden" name="a" value="errors">
            <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
            <input type="text" name="q" class="form-control form-control-sm"
                   style="max-width:240px"
                   placeholder="Search text or error&hellip;"
                   value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary">Search</button>
            <?php if ($search !== ''): ?>
            <a href="<?= u('queue', 'errors', ['id' => (int) $account['id']]) ?>"
               class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>

    </div>

    <?php if ($count > 0): ?>

    <?php if ((int) $totalCount >= 200 && $search === ''): ?>
    <p class="text-muted small mb-3">Showing the 200 most recent failures.</p>
    <?php elseif ($search !== ''): ?>
    <p class="text-muted small mb-3">
        <?= $count ?> <?= $count === 1 ? 'result' : 'results' ?> for
        &ldquo;<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>&rdquo;
    </p>
    <?php endif; ?>

    <div class="list-group">
        <?php foreach ($rows as $row): ?>
        <?php
            $preview = mb_strlen((string) $row['body_snapshot']) > 120
                ? mb_substr((string) $row['body_snapshot'], 0, 120) . '…'
                : (string) $row['body_snapshot'];
        ?>
        <div class="list-group-item px-3 py-3">
            <div class="d-flex align-items-start justify-content-between gap-3">

                <div class="flex-grow-1 min-width-0">

                    <div class="text-muted small mb-1">
                        Failed <?= htmlspecialchars(datify((string) $row['posted_at']), ENT_QUOTES, 'UTF-8') ?>
                    </div>

                    <div class="small mb-2" style="white-space:pre-line">
                        <?= htmlspecialchars($preview, ENT_QUOTES, 'UTF-8') ?>
                    </div>

                    <!-- Error message shown in full — the primary reason this view exists -->
                    <?php if (!empty($row['error_message'])): ?>
                    <div class="bg-light border border-danger-subtle rounded p-2 small text-danger-emphasis"
                         style="font-family:monospace;white-space:pre-wrap;word-break:break-all">
                        <?= htmlspecialchars((string) $row['error_message'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php else: ?>
                    <div class="text-muted small fst-italic">No error message recorded.</div>
                    <?php endif; ?>

                </div>

                <div class="flex-shrink-0">
                    <a href="<?= u('content', 'edit', ['id' => (int) $row['post_id']]) ?>"
                       class="btn btn-sm btn-outline-secondary">Edit post</a>
                </div>

            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php else: ?>

    <div class="card text-center py-5">
        <div class="card-body">
            <?php if ($search !== '' && (int) $totalCount > 0): ?>
            <h5 class="card-title text-muted">No results for &ldquo;<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>&rdquo;</h5>
            <p class="card-text text-muted small mb-4">
                <?= (int) $totalCount ?> failed <?= (int) $totalCount === 1 ? 'post' : 'posts' ?> on record.
            </p>
            <a href="<?= u('queue', 'errors', ['id' => (int) $account['id']]) ?>"
               class="btn btn-sm btn-outline-secondary">Clear search</a>
            <?php else: ?>
            <h5 class="card-title text-success">No failed posts</h5>
            <p class="card-text text-muted small mb-0">Everything is posting successfully.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>

</div>
