<?php
/**
 * Pending queue view — rendered by queue/view.
 *
 * Template variables:
 *   $account          array   Account row (id, name, is_posting, platform, platform_name)
 *   $rows             array   Pending scheduled_posts rows (current page):
 *                               id, scheduled_time, final_body, final_image_filenames,
 *                               post_id, attributed_to
 *   $pendingTotal     int     Filter-aware pending count (for subtitle and pagination)
 *   $search           string  Current ?q= search value, or ''
 *   $page             int
 *   $perPage          int
 *   $totalPages       int
 *   $totalItems       int     Same as $pendingTotal
 *   $paginationParams array
 *   $csrfToken        string
 */
?>
<div class="container py-4" style="max-width:900px">

    <div class="d-flex align-items-center mb-1 gap-3">
        <a href="<?= u('queue') ?>" class="text-muted text-decoration-none">&larr; Queue</a>
        <h1 class="h3 mb-0">
            <?= htmlspecialchars((string) $account['name'], ENT_QUOTES, 'UTF-8') ?>
            &mdash; Pending Queue
        </h1>
        <?php if (!(int) $account['is_posting']): ?>
        <span class="badge bg-secondary">Paused</span>
        <?php endif; ?>
    </div>
    <p class="text-muted small mb-4">
        <?= (int) $pendingTotal ?> <?= $search !== '' ? 'matching' : 'pending' ?> <?= (int) $pendingTotal === 1 ? 'post' : 'posts' ?>
        &middot;
        <a href="<?= u('queue', 'history', ['id' => (int) $account['id']]) ?>" class="text-decoration-none">History</a>
    </p>

    <?php if (!empty($_SESSION['notification'])): ?>
    <div class="alert alert-<?= htmlspecialchars($_SESSION['notification']['type'] === 'error' ? 'danger' : $_SESSION['notification']['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars((string) $_SESSION['notification']['message'], ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['notification']); endif; ?>

    <!-- Search + Flush -->
    <div class="d-flex flex-wrap gap-2 align-items-end justify-content-between mb-3">

        <form method="GET" action="<?= BASE_URL ?>index.php"
              class="d-flex gap-2 align-items-end">
            <input type="hidden" name="c" value="queue">
            <input type="hidden" name="a" value="view">
            <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
            <div>
                <label for="q" class="form-label form-label-sm mb-1">Search</label>
                <input type="text" id="q" name="q" class="form-control form-control-sm"
                       style="max-width:260px"
                       placeholder="Search post text&hellip;"
                       value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <button type="submit" class="btn btn-sm btn-outline-secondary">Search</button>
            <?php if ($search !== ''): ?>
            <a href="<?= u('queue', 'view', ['id' => (int) $account['id']]) ?>"
               class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ((int) $pendingTotal > 0 && $search === ''): ?>
        <form method="POST" action="<?= u('queue', 'queue_flush') ?>"
              onsubmit="return confirm('Remove all <?= (int) $pendingTotal ?> pending <?= (int) $pendingTotal === 1 ? 'post' : 'posts' ?> from the queue? The queue will refill automatically on the next cron run.')">
            <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
            <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger">Flush All</button>
        </form>
        <?php endif; ?>

    </div>

    <?php if ((int) $totalItems > 0): ?>

    <?php include ROOT . DS . 'views' . DS . 'partials' . DS . 'pagination.php'; ?>

    <div class="list-group">
        <?php foreach ($rows as $row): ?>
        <?php
            $preview = mb_strlen((string) $row['final_body']) > 120
                ? mb_substr((string) $row['final_body'], 0, 120) . '…'
                : (string) $row['final_body'];
        ?>
        <div class="list-group-item px-3 py-2">
            <div class="d-flex align-items-center gap-2">

                <!-- Scheduled date -->
                <span class="text-muted small text-nowrap flex-shrink-0">
                    <?= htmlspecialchars(datify((string) $row['scheduled_time'], (string) $account['timezone']), ENT_QUOTES, 'UTF-8') ?>
                </span>

                <!-- Body — flexible middle -->
                <div class="flex-grow-1 small text-truncate" style="min-width:0">
                    <?= htmlspecialchars($preview, ENT_QUOTES, 'UTF-8') ?>
                </div>

                <!-- Has image badge -->
                <?php if (!empty($row['final_image_filenames'])): ?>
                <?php
                    $imgs     = json_decode((string) $row['final_image_filenames'], true);
                    $firstImg = is_array($imgs) && !empty($imgs) ? $imgs[0] : null;
                ?>
                <?php if ($firstImg !== null): ?>
                <div class="flex-shrink-0">
                    <span class="badge bg-light text-dark border"
                          style="cursor:pointer"
                          data-bs-toggle="modal"
                          data-bs-target="#imagePreviewModal"
                          data-img-src="<?= htmlspecialchars(BASE_URL . 'images/' . $firstImg, ENT_QUOTES, 'UTF-8') ?>"
                          data-img-name="<?= htmlspecialchars($firstImg, ENT_QUOTES, 'UTF-8') ?>">Has image</span>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <!-- Right-side actions -->
                <div class="d-flex gap-2 flex-shrink-0 align-items-center">

                    <form method="POST" action="<?= u('queue', 'sharenow') ?>"
                          onsubmit="return confirm('Move this post to the front of the queue? It will publish within 5 minutes.')">
                        <input type="hidden" name="id"         value="<?= (int) $row['id'] ?>">
                        <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                        <input type="hidden" name="search"     value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="page"       value="<?= $page ?>">
                        <input type="hidden" name="per_page"   value="<?= $perPage ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-sm btn-outline-success">Post Now</button>
                    </form>

                    <a href="<?= u('content', 'edit', ['id' => (int) $row['post_id']]) ?>"
                       class="btn btn-sm btn-outline-secondary">Edit</a>

                    <form method="POST" action="<?= u('queue', 'remove') ?>"
                          onsubmit="return confirm('Remove this post from the queue?')">
                        <input type="hidden" name="id"         value="<?= (int) $row['id'] ?>">
                        <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                        <input type="hidden" name="search"     value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="page"       value="<?= $page ?>">
                        <input type="hidden" name="per_page"   value="<?= $perPage ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                    </form>

                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php include ROOT . DS . 'views' . DS . 'partials' . DS . 'pagination.php'; ?>

    <div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h6 class="modal-title text-truncate" id="imagePreviewModalLabel"></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-2">
                    <img src="" id="imagePreviewImg"
                         class="img-fluid rounded"
                         style="max-height:70vh"
                         alt="">
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var modal = document.getElementById('imagePreviewModal');
        modal.addEventListener('show.bs.modal', function (e) {
            var trigger = e.relatedTarget;
            document.getElementById('imagePreviewImg').src = trigger.getAttribute('data-img-src');
            document.getElementById('imagePreviewModalLabel').textContent = trigger.getAttribute('data-img-name');
        });
        modal.addEventListener('hidden.bs.modal', function () {
            document.getElementById('imagePreviewImg').src = '';
        });
    }());
    </script>

    <?php else: ?>

    <div class="card text-center py-5">
        <div class="card-body">
            <?php if ($search !== ''): ?>
            <h5 class="card-title text-muted">No results for &ldquo;<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>&rdquo;</h5>
            <p class="card-text text-muted small mb-4">Try a different search term.</p>
            <a href="<?= u('queue', 'view', ['id' => (int) $account['id']]) ?>"
               class="btn btn-sm btn-outline-secondary">Clear search</a>
            <?php else: ?>
            <h5 class="card-title text-muted">Queue is empty</h5>
            <p class="card-text text-muted small mb-4">
                The queue refills automatically when pending posts drop below the
                recycle threshold on the next cron run.
                <?php if (!(int) $account['is_posting']): ?>
                <br><strong>Note:</strong> this account is paused &mdash; enable posting in
                <a href="<?= u('accounts', 'edit', ['id' => (int) $account['id']]) ?>">account settings</a>
                for the queue engine to run.
                <?php endif; ?>
            </p>
            <a href="<?= u('content', 'index', ['account_id' => (int) $account['id']]) ?>"
               class="btn btn-outline-secondary btn-sm">View content library</a>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>

</div>
