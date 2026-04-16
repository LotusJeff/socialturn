<?php
/**
 * Pending queue view — rendered by queue/view.
 *
 * Template variables:
 *   $account       array   Account row (id, name, is_posting, platform, platform_name)
 *   $rows          array   Pending scheduled_posts rows:
 *                            id, scheduled_time, final_body, final_image_filename,
 *                            post_id, attributed_to
 *   $pendingTotal  int     Unfiltered pending count (for heading and empty-state)
 *   $search        string  Current ?q= search value, or ''
 *   $csrfToken     string
 */

$count = count($rows);
?>
<div class="container py-4" style="max-width:900px">

    <div class="d-flex align-items-center mb-1 gap-3">
        <a href="<?= BASE_URL ?>queue" class="text-muted text-decoration-none">&larr; Queue</a>
        <h1 class="h3 mb-0">
            <?= htmlspecialchars((string) $account['name'], ENT_QUOTES, 'UTF-8') ?>
            &mdash; Pending Queue
        </h1>
        <?php if (!(int) $account['is_posting']): ?>
        <span class="badge bg-secondary">Paused</span>
        <?php endif; ?>
    </div>
    <p class="text-muted small mb-4">
        <?= (int) $pendingTotal ?> pending <?= (int) $pendingTotal === 1 ? 'post' : 'posts' ?>
        &middot;
        <a href="<?= BASE_URL ?>queue/history/<?= (int) $account['id'] ?>" class="text-decoration-none">History</a>
    </p>

    <?php if (!empty($_SESSION['notification'])): ?>
    <div class="alert alert-<?= htmlspecialchars($_SESSION['notification']['type'] === 'error' ? 'danger' : $_SESSION['notification']['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars((string) $_SESSION['notification']['message'], ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['notification']); endif; ?>

    <!-- Search + Flush -->
    <div class="d-flex flex-wrap gap-2 align-items-end justify-content-between mb-3">

        <form method="GET" action="<?= BASE_URL ?>queue/view/<?= (int) $account['id'] ?>"
              class="d-flex gap-2 align-items-end">
            <div>
                <label for="q" class="form-label form-label-sm mb-1">Search</label>
                <input type="text" id="q" name="q" class="form-control form-control-sm"
                       style="max-width:260px"
                       placeholder="Search post text&hellip;"
                       value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <button type="submit" class="btn btn-sm btn-outline-secondary">Search</button>
            <?php if ($search !== ''): ?>
            <a href="<?= BASE_URL ?>queue/view/<?= (int) $account['id'] ?>"
               class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ((int) $pendingTotal > 0): ?>
        <form method="POST" action="<?= BASE_URL ?>queue/queue_flush"
              onsubmit="return confirm('Remove all <?= (int) $pendingTotal ?> pending <?= (int) $pendingTotal === 1 ? 'post' : 'posts' ?> from the queue? The queue will refill automatically on the next cron run.')">
            <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
            <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger">Flush All</button>
        </form>
        <?php endif; ?>

    </div>

    <?php if ($count > 0): ?>

    <?php if ($search !== ''): ?>
    <p class="text-muted small mb-3">
        Showing <?= $count ?> of <?= (int) $pendingTotal ?> pending <?= (int) $pendingTotal === 1 ? 'post' : 'posts' ?>
    </p>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width:160px">Scheduled</th>
                    <th>Post</th>
                    <th style="width:170px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
            <?php
                $preview = mb_strlen((string) $row['final_body']) > 120
                    ? mb_substr((string) $row['final_body'], 0, 120) . '…'
                    : (string) $row['final_body'];
            ?>
            <tr>
                <td class="text-muted small text-nowrap">
                    <?= htmlspecialchars(datify((string) $row['scheduled_time']), ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td class="small">
                    <div style="white-space:pre-line"><?= htmlspecialchars($preview, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!empty($row['attributed_to'])): ?>
                    <div class="text-muted fst-italic mt-1">
                        &mdash; <?= htmlspecialchars((string) $row['attributed_to'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($row['final_image_filename'])): ?>
                    <div class="mt-1">
                        <span class="badge bg-light text-dark border">Has image</span>
                    </div>
                    <?php endif; ?>
                </td>
                <td class="text-end">
                    <div class="d-flex gap-2 justify-content-end flex-wrap">

                        <!-- Post Now — reschedules this pending row to NOW() -->
                        <form method="POST" action="<?= BASE_URL ?>queue/sharenow"
                              onsubmit="return confirm('Move this post to the front of the queue? It will publish within 5 minutes.')">
                            <input type="hidden" name="id"          value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="account_id"  value="<?= (int) $account['id'] ?>">
                            <input type="hidden" name="search"      value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="btn btn-sm btn-outline-success">Post Now</button>
                        </form>

                        <!-- Edit source post in content library -->
                        <a href="<?= BASE_URL ?>content/edit/<?= (int) $row['post_id'] ?>"
                           class="btn btn-sm btn-outline-secondary">Edit</a>

                        <!-- Remove this queue entry -->
                        <form method="POST" action="<?= BASE_URL ?>queue/remove"
                              onsubmit="return confirm('Remove this post from the queue?')">
                            <input type="hidden" name="id"          value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="account_id"  value="<?= (int) $account['id'] ?>">
                            <input type="hidden" name="search"      value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                        </form>

                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($count >= 200): ?>
    <p class="text-muted small mt-2">Showing first 200 results. Use search to narrow results.</p>
    <?php endif; ?>

    <?php else: ?>

    <div class="card text-center py-5">
        <div class="card-body">
            <?php if ($search !== '' && (int) $pendingTotal > 0): ?>
            <h5 class="card-title text-muted">No results for &ldquo;<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>&rdquo;</h5>
            <p class="card-text text-muted small mb-4">
                <?= (int) $pendingTotal ?> pending <?= (int) $pendingTotal === 1 ? 'post' : 'posts' ?> in queue.
            </p>
            <a href="<?= BASE_URL ?>queue/view/<?= (int) $account['id'] ?>"
               class="btn btn-sm btn-outline-secondary">Clear search</a>
            <?php else: ?>
            <h5 class="card-title text-muted">Queue is empty</h5>
            <p class="card-text text-muted small mb-4">
                The queue refills automatically when pending posts drop below the
                recycle threshold on the next cron run.
                <?php if (!(int) $account['is_posting']): ?>
                <br><strong>Note:</strong> this account is paused &mdash; enable posting in
                <a href="<?= BASE_URL ?>accounts/edit/<?= (int) $account['id'] ?>">account settings</a>
                for the queue engine to run.
                <?php endif; ?>
            </p>
            <a href="<?= BASE_URL ?>content?account_id=<?= (int) $account['id'] ?>"
               class="btn btn-outline-secondary btn-sm">View content library</a>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>

</div>
