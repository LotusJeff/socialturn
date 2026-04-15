<?php
/**
 * Content library index view — rendered by content/index.
 *
 * Template variables:
 *   $posts            array   Post rows (id, body, attributed_to, image_filename,
 *                             is_recyclable, internal_note, created_at,
 *                             account_id, account_name, platform)
 *   $accounts         array   Accessible accounts (id, name, platform) for filter dropdown
 *   $filterAccountId  int     Currently active account_id filter, or 0 for all
 *   $filterSearch     string  Currently active text search, or ''
 *   $csrfToken        string
 */

/**
 * Returns a Bootstrap badge class for a platform name.
 */
function content_platformBadgeClass(string $platform): string
{
    return match ($platform) {
        'twitter'   => 'bg-info text-dark',
        'facebook'  => 'bg-primary',
        'instagram' => 'bg-danger',
        default     => 'bg-secondary',
    };
}

/**
 * Returns a human-readable platform label.
 */
function content_platformLabel(string $platform): string
{
    return match ($platform) {
        'twitter'   => 'Twitter / X',
        'facebook'  => 'Facebook',
        'instagram' => 'Instagram',
        default     => ucfirst($platform),
    };
}

$count = count($posts);
?>
<div class="container py-4">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0">Content Library</h1>
        <a href="<?= BASE_URL ?>content/create<?= $filterAccountId > 0 ? '?account_id=' . (int) $filterAccountId : '' ?>"
           class="btn btn-primary btn-sm">+ New Post</a>
    </div>

    <?php if (!empty($_SESSION['notification'])): ?>
    <div class="alert alert-<?= htmlspecialchars($_SESSION['notification']['type'] === 'error' ? 'danger' : $_SESSION['notification']['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars((string) $_SESSION['notification']['message'], ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['notification']); endif; ?>

    <?php if (empty($accounts)): ?>
    <!-- No accounts yet -->
    <div class="card text-center py-5">
        <div class="card-body">
            <h5 class="card-title text-muted">No accounts yet</h5>
            <p class="card-text text-muted small mb-4">
                Create an account before adding content.
            </p>
            <a href="<?= BASE_URL ?>accounts/create" class="btn btn-primary">Create Account</a>
        </div>
    </div>

    <?php else: ?>

    <!-- Filter bar -->
    <form method="GET" action="<?= BASE_URL ?>content" class="d-flex flex-wrap gap-2 align-items-end mb-4">

        <div>
            <label for="account_id" class="form-label form-label-sm mb-1">Account</label>
            <select id="account_id" name="account_id" class="form-select form-select-sm" style="max-width:220px">
                <option value="0" <?= $filterAccountId === 0 ? 'selected' : '' ?>>All accounts</option>
                <?php foreach ($accounts as $a): ?>
                <option value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === $filterAccountId ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) $a['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="q" class="form-label form-label-sm mb-1">Search</label>
            <input type="text" id="q" name="q" class="form-control form-control-sm"
                   style="max-width:260px"
                   placeholder="Search post text or attribution&hellip;"
                   value="<?= htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-outline-secondary">Filter</button>
            <?php if ($filterAccountId > 0 || $filterSearch !== ''): ?>
            <a href="<?= BASE_URL ?>content" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </div>

    </form>

    <!-- Result count -->
    <?php if ($filterAccountId > 0 || $filterSearch !== ''): ?>
    <p class="text-muted small mb-3">
        Showing <?= $count ?> <?= $count === 1 ? 'post' : 'posts' ?>
    </p>
    <?php endif; ?>

    <?php if ($count > 0): ?>

    <!-- Post list -->
    <div class="list-group">
        <?php foreach ($posts as $p): ?>
        <?php
            $bodyPreview = mb_strlen((string) $p['body']) > 140
                ? mb_substr((string) $p['body'], 0, 140) . '…'
                : (string) $p['body'];
        ?>
        <div class="list-group-item px-3 py-3">
            <div class="d-flex align-items-start justify-content-between gap-3">
                <div class="flex-grow-1 min-width-0">

                    <!-- Platform + account badges -->
                    <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                        <span class="badge <?= content_platformBadgeClass((string) $p['platform']) ?>">
                            <?= content_platformLabel((string) $p['platform']) ?>
                        </span>
                        <span class="text-muted small">
                            <?= htmlspecialchars((string) $p['account_name'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <?php if (!(int) $p['is_recyclable']): ?>
                        <span class="badge bg-warning text-dark">One-time</span>
                        <?php endif; ?>
                        <?php if (!empty($p['image_filename'])): ?>
                        <span class="badge bg-light text-dark border">Has image</span>
                        <?php endif; ?>
                    </div>

                    <!-- Post body preview -->
                    <div class="small mb-1" style="white-space:pre-line">
                        <?= htmlspecialchars($bodyPreview, ENT_QUOTES, 'UTF-8') ?>
                    </div>

                    <!-- Attribution -->
                    <?php if (!empty($p['attributed_to'])): ?>
                    <div class="text-muted small fst-italic">
                        &mdash; <?= htmlspecialchars((string) $p['attributed_to'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php endif; ?>

                    <!-- Internal note -->
                    <?php if (!empty($p['internal_note'])): ?>
                    <div class="text-muted small mt-1">
                        <span class="fw-semibold">Note:</span>
                        <?= htmlspecialchars((string) $p['internal_note'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php endif; ?>

                </div>

                <!-- Actions -->
                <div class="d-flex flex-column gap-2 flex-shrink-0 align-items-end">

                    <div class="d-flex gap-2">
                        <a href="<?= BASE_URL ?>content/edit/<?= (int) $p['id'] ?>"
                           class="btn btn-sm btn-outline-secondary">Edit</a>

                        <!-- Delete -->
                        <form method="POST" action="<?= BASE_URL ?>content/delete"
                              onsubmit="return confirm('Delete this post? It will be removed from the queue.')">
                            <input type="hidden" name="id"         value="<?= (int) $p['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    </div>

                    <!-- Recycle toggle -->
                    <form method="POST" action="<?= BASE_URL ?>content/toggle">
                        <input type="hidden" name="id"                value="<?= (int) $p['id'] ?>">
                        <input type="hidden" name="filter_account_id" value="<?= (int) $filterAccountId ?>">
                        <input type="hidden" name="filter_search"     value="<?= htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="csrf_token"        value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit"
                                class="btn btn-sm <?= (int) $p['is_recyclable'] ? 'btn-outline-secondary' : 'btn-outline-warning' ?>"
                                title="<?= (int) $p['is_recyclable'] ? 'Click to make one-time only' : 'Click to enable recycling' ?>">
                            <?= (int) $p['is_recyclable'] ? 'Recycling' : 'One-time' ?>
                        </button>
                    </form>

                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($count >= 200): ?>
    <p class="text-muted small mt-3">Showing first 200 results. Use filters to narrow results.</p>
    <?php endif; ?>

    <?php else: ?>

    <!-- Empty state -->
    <div class="card text-center py-5">
        <div class="card-body">
            <h5 class="card-title text-muted">No posts found</h5>
            <p class="card-text text-muted small mb-4">
                <?php if ($filterAccountId > 0 || $filterSearch !== ''): ?>
                No posts match the current filters.
                <br><a href="<?= BASE_URL ?>content" class="text-decoration-none">Clear filters</a> to see all posts.
                <?php else: ?>
                Add your first post to get started.
                <?php endif; ?>
            </p>
            <a href="<?= BASE_URL ?>content/create<?= $filterAccountId > 0 ? '?account_id=' . (int) $filterAccountId : '' ?>"
               class="btn btn-primary">+ New Post</a>
        </div>
    </div>

    <?php endif; ?>

    <?php endif; ?>

</div>
