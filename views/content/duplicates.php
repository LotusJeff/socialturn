<?php
/**
 * Duplicate post detection view — rendered by content/content_duplicates.
 *
 * Template variables:
 *   $groups     array   Grouped duplicate posts.
 *                       Structure: $groups[$accountId]['account_name'] string
 *                                  $groups[$accountId]['posts'][$normalized][] post row
 *                       Post row keys: id, body, attributed_to, is_recyclable,
 *                                      created_at, body_normalized, account_id, account_name
 *   $csrfToken  string
 */
?>
<div class="container py-4" style="max-width:900px">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0">Duplicate Posts</h1>
        <a href="<?= u('content') ?>" class="text-muted text-decoration-none">&larr; Content Library</a>
    </div>

    <?php if (!empty($_SESSION['notification'])): ?>
    <div class="alert alert-<?= htmlspecialchars($_SESSION['notification']['type'] === 'error' ? 'danger' : $_SESSION['notification']['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars((string) $_SESSION['notification']['message'], ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['notification']); endif; ?>

    <?php if (empty($groups)): ?>

    <div class="card text-center py-5">
        <div class="card-body">
            <h5 class="card-title text-muted">No duplicates found</h5>
            <p class="card-text text-muted small mb-0">
                All posts in your content library have unique bodies.
                <br>Note: posts that have not been edited since duplicate detection
                was added will appear here only after their first edit or re-import.
            </p>
        </div>
    </div>

    <?php else: ?>

    <?php foreach ($groups as $accountId => $accountGroup): ?>

    <h2 class="h5 mb-3 mt-4">
        <?= htmlspecialchars((string) $accountGroup['account_name'], ENT_QUOTES, 'UTF-8') ?>
    </h2>

    <?php foreach ($accountGroup['posts'] as $normalized => $copies): ?>
    <?php $copyCount = count($copies); ?>

    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between py-2">
            <span class="small fw-semibold text-muted">
                <?= $copyCount ?> copies of the same post
            </span>
        </div>
        <div class="list-group list-group-flush">
            <?php foreach ($copies as $p): ?>
            <?php
                $bodyPreview = mb_strlen((string) $p['body']) > 140
                    ? mb_substr((string) $p['body'], 0, 140) . '…'
                    : (string) $p['body'];
            ?>
            <div class="list-group-item px-3 py-3">
                <div class="d-flex align-items-start justify-content-between gap-3">

                    <div class="flex-grow-1 min-width-0">
                        <div class="small mb-1" style="white-space:pre-line">
                            <?= htmlspecialchars($bodyPreview, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <?php if (!empty($p['attributed_to'])): ?>
                        <div class="text-muted small fst-italic">
                            &mdash; <?= htmlspecialchars((string) $p['attributed_to'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <?php endif; ?>
                        <div class="text-muted small mt-1">
                            Added <?= htmlspecialchars(datify((string) $p['created_at']), ENT_QUOTES, 'UTF-8') ?>
                            <?php if (!(int) $p['is_recyclable']): ?>
                            &middot; <span class="badge bg-warning text-dark">One-time</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="flex-shrink-0 d-flex gap-2 align-items-start">
                        <a href="<?= u('content', 'edit', ['id' => (int) $p['id']]) ?>"
                           class="btn btn-sm btn-outline-secondary">Edit</a>

                        <form method="POST" action="<?= u('content', 'delete') ?>"
                              onsubmit="return confirm('Delete this post? It will be removed from the queue.')">
                            <input type="hidden" name="id"         value="<?= (int) $p['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    </div>

                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php endforeach; ?>
    <?php endforeach; ?>

    <?php endif; ?>

</div>
