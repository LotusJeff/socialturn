<?php
/**
 * Content library index view — rendered by content/index.
 *
 * Template variables:
 *   $posts            array   Post rows (current page):
 *                               id, body, attributed_to, image_filename,
 *                               is_recyclable, internal_note, created_at,
 *                               account_id, account_name, platform
 *   $accounts         array   Accessible accounts (id, name, platform) for filter dropdown
 *   $filterAccountId  int     Currently active account_id filter, or 0 for all
 *   $filterSearch     string  Currently active text search, or ''
 *   $page             int
 *   $perPage          int
 *   $totalPages       int
 *   $totalItems       int     Total posts matching current filters
 *   $paginationParams array
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
?>
<div class="container py-4">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0">Content Library</h1>
        <div class="d-flex gap-2">
            <a href="<?= u('content', 'create', $filterAccountId > 0 ? ['account_id' => $filterAccountId] : []) ?>"
               class="btn btn-primary btn-sm">+ New Post</a>
            <a href="<?= u('content', 'importForm', $filterAccountId > 0 ? ['account_id' => $filterAccountId] : []) ?>"
               class="btn btn-sm btn-outline-secondary">Import CSV</a>
            <a href="<?= u('content', 'content_duplicates') ?>"
               class="btn btn-sm btn-outline-secondary">Find Duplicates</a>
        </div>
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
            <a href="<?= u('accounts', 'create') ?>" class="btn btn-primary">Create Account</a>
        </div>
    </div>

    <?php else: ?>

    <!-- Filter bar -->
    <form method="GET" action="<?= BASE_URL ?>index.php" class="d-flex flex-wrap gap-2 align-items-end mb-4">
        <input type="hidden" name="c" value="content">
        <input type="hidden" name="a" value="index">

        <div>
            <label for="account_id" class="form-label form-label-sm mb-1">Account</label>
            <select id="account_id" name="account_id" class="form-select form-select-sm" style="max-width:220px"
                    onchange="this.form.submit()">
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
            <a href="<?= u('content') ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </div>

    </form>

    <?php if ($totalItems > 0): ?>

    <?php include ROOT . DS . 'views' . DS . 'partials' . DS . 'pagination.php'; ?>

    <!-- Post list -->
    <div class="list-group">
        <?php foreach ($posts as $p): ?>
        <?php
            $bodyPreview = mb_strlen((string) $p['body']) > 100
                ? mb_substr((string) $p['body'], 0, 100) . '…'
                : (string) $p['body'];
        ?>
        <div class="list-group-item px-3 py-2">
            <div class="d-flex align-items-center gap-2">

                <!-- Platform badge -->
                <span class="badge <?= content_platformBadgeClass((string) $p['platform']) ?> flex-shrink-0">
                    <?= content_platformLabel((string) $p['platform']) ?>
                </span>

                <!-- Account name -->
                <span class="text-muted small text-nowrap flex-shrink-0">
                    <?= htmlspecialchars((string) $p['account_name'], ENT_QUOTES, 'UTF-8') ?>
                </span>

                <!-- Recycling state badge -->
                <span class="badge <?= (int) $p['is_recyclable'] ? 'bg-success' : 'bg-warning text-dark' ?> flex-shrink-0">
                    <?= (int) $p['is_recyclable'] ? 'Recycling' : 'One-time' ?>
                </span>

                <!-- Body + attribution — flexible middle -->
                <div class="flex-grow-1 small text-truncate" style="min-width:0">
                    <?= htmlspecialchars($bodyPreview, ENT_QUOTES, 'UTF-8') ?>
                    <?php if (!empty($p['attributed_to'])): ?>
                    <span class="text-muted">&mdash; <?= htmlspecialchars((string) $p['attributed_to'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </div>

                <!-- Right-side actions -->
                <div class="d-flex gap-1 flex-shrink-0 align-items-center">

                    <!-- Send Now — only when filtered to a single account -->
                    <?php if ($filterAccountId > 0): ?>
                    <form method="POST" action="<?= u('content', 'sendNow') ?>">
                        <input type="hidden" name="post_id"           value="<?= (int) $p['id'] ?>">
                        <input type="hidden" name="filter_account_id" value="<?= (int) $filterAccountId ?>">
                        <input type="hidden" name="csrf_token"        value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary"
                                onclick="return confirm('This post will be sent within 5 minutes. Continue?')">
                            Send Now
                        </button>
                    </form>
                    <?php endif; ?>

                    <!-- Edit -->
                    <a href="<?= u('content', 'edit', ['id' => (int) $p['id']]) ?>"
                       class="btn btn-sm btn-outline-secondary">Edit</a>

                    <!-- Delete -->
                    <form method="POST" action="<?= u('content', 'delete') ?>"
                          onsubmit="return confirm('Delete this post? It will be removed from the queue.')">
                        <input type="hidden" name="id"         value="<?= (int) $p['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>

                    <!-- Recycle toggle action button -->
                    <form method="POST" action="<?= u('content', 'toggle') ?>">
                        <input type="hidden" name="id"                value="<?= (int) $p['id'] ?>">
                        <input type="hidden" name="filter_account_id" value="<?= (int) $filterAccountId ?>">
                        <input type="hidden" name="filter_search"     value="<?= htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="page"              value="<?= $page ?>">
                        <input type="hidden" name="per_page"          value="<?= $perPage ?>">
                        <input type="hidden" name="csrf_token"        value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit"
                                class="btn btn-sm <?= (int) $p['is_recyclable'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                title="<?= (int) $p['is_recyclable'] ? 'Click to make this post send once then deactivate' : 'Click to make this post recycle indefinitely' ?>">
                            <?= (int) $p['is_recyclable'] ? 'Make One-time' : 'Enable Recycling' ?>
                        </button>
                    </form>

                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php include ROOT . DS . 'views' . DS . 'partials' . DS . 'pagination.php'; ?>

    <?php else: ?>

    <!-- Empty state -->
    <div class="card text-center py-5">
        <div class="card-body">
            <h5 class="card-title text-muted">No posts found</h5>
            <p class="card-text text-muted small mb-4">
                <?php if ($filterAccountId > 0 || $filterSearch !== ''): ?>
                No posts match the current filters.
                <br><a href="<?= u('content') ?>" class="text-decoration-none">Clear filters</a> to see all posts.
                <?php else: ?>
                Add your first post to get started.
                <?php endif; ?>
            </p>
            <a href="<?= u('content', 'create', $filterAccountId > 0 ? ['account_id' => $filterAccountId] : []) ?>"
               class="btn btn-primary">+ New Post</a>
        </div>
    </div>

    <?php endif; ?>

    <?php endif; ?>

</div>
