<?php
/**
 * Queue status overview — rendered by queue/index.
 *
 * Template variables:
 *   $rows             array   One row per account (paginated):
 *                               id, name, is_posting, platform, platform_name,
 *                               platform_active, token_expires_at,
 *                               recycled_count, pending_count,
 *                               posted_count (30d), failed_count (30d)
 *   $page             int
 *   $perPage          int
 *   $totalPages       int
 *   $totalItems       int     Total accessible account count
 *   $paginationParams array
 *   $csrfToken        string
 */

function queue_index_platformBadge(string $platform): string
{
    return match ($platform) {
        'twitter'   => 'bg-info text-dark',
        'facebook'  => 'bg-primary',
        'instagram' => 'bg-danger',
        default     => 'bg-secondary',
    };
}

function queue_index_platformLabel(string $platform): string
{
    return match ($platform) {
        'twitter'   => 'Twitter / X',
        'facebook'  => 'Facebook',
        'instagram' => 'Instagram',
        default     => ucfirst($platform),
    };
}

/**
 * Returns connection status badge HTML — identical logic to connectionStatus()
 * in accounts/index, using platform_active and token_expires_at from the row.
 */
function queue_index_connectionStatus(array $r): string
{
    if (!(int) $r['platform_active']) {
        return '<span class="badge bg-danger">Disconnected</span>';
    }
    if (!empty($r['token_expires_at'])) {
        $expiresAt = new DateTimeImmutable($r['token_expires_at']);
        $threshold = new DateTimeImmutable('+7 days');
        if ($expiresAt <= $threshold) {
            return '<span class="badge bg-warning text-dark">Expires soon</span>';
        }
    }
    return '<span class="badge bg-success">Connected</span>';
}
?>
<div class="container py-4">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0">Queue</h1>
    </div>

    <?php if (!empty($_SESSION['notification'])): ?>
    <div class="alert alert-<?= htmlspecialchars($_SESSION['notification']['type'] === 'error' ? 'danger' : $_SESSION['notification']['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars((string) $_SESSION['notification']['message'], ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['notification']); endif; ?>

    <?php if ($totalItems === 0): ?>

    <div class="card text-center py-5">
        <div class="card-body">
            <h5 class="card-title text-muted">No accounts yet</h5>
            <p class="card-text text-muted small mb-4">
                Create an account and add content to get the queue running.
            </p>
            <a href="<?= u('accounts', 'create') ?>" class="btn btn-primary">Create Account</a>
        </div>
    </div>

    <?php else: ?>

    <?php include ROOT . DS . 'views' . DS . 'partials' . DS . 'pagination.php'; ?>

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width:80px"></th>
                    <th style="width:200px">Status</th>
                    <th>Account</th>
                    <th class="text-center" style="width:130px">Recycled Queue</th>
                    <th class="text-center" style="width:100px">Pending</th>
                    <th class="text-center" style="width:110px">Posted (30d)</th>
                    <th class="text-center" style="width:110px">Failed (30d)</th>
                    <th style="width:1%;white-space:nowrap"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td>
                    <span class="badge <?= queue_index_platformBadge((string) $r['platform']) ?>">
                        <?= queue_index_platformLabel((string) $r['platform']) ?>
                    </span>
                </td>
                <td>
                    <div class="d-flex gap-1">
                        <?= queue_index_connectionStatus($r) ?>
                        <?php if ((int) $r['is_posting']): ?>
                        <span class="badge bg-success">Posting</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Paused</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="fw-semibold">
                    <?= htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td class="text-center">
                    <?php if ((int) $r['recycled_count'] > 0): ?>
                    <a href="<?= u('content', 'index', ['account_id' => (int) $r['id']]) ?>"
                       class="text-decoration-none">
                        <?= (int) $r['recycled_count'] ?>
                    </a>
                    <?php else: ?>
                    <span class="text-muted">0</span>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <?php if ((int) $r['pending_count'] > 0): ?>
                    <a href="<?= u('queue', 'view', ['id' => (int) $r['id']]) ?>"
                       class="fw-semibold text-decoration-none">
                        <?= (int) $r['pending_count'] ?>
                    </a>
                    <?php else: ?>
                    <span class="text-muted">0</span>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <?php if ((int) $r['posted_count'] > 0): ?>
                    <a href="<?= u('queue', 'history', ['id' => (int) $r['id']]) ?>"
                       class="text-success text-decoration-none">
                        <?= (int) $r['posted_count'] ?>
                    </a>
                    <?php else: ?>
                    <span class="text-muted">0</span>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <?php if ((int) $r['failed_count'] > 0): ?>
                    <a href="<?= u('queue', 'errors', ['id' => (int) $r['id']]) ?>"
                       class="text-danger fw-semibold text-decoration-none">
                        <?= (int) $r['failed_count'] ?>
                    </a>
                    <?php else: ?>
                    <span class="text-muted">0</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                    <a href="<?= u('content', 'create', ['account_id' => (int) $r['id']]) ?>"
                       class="btn btn-sm btn-outline-primary">New Post</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php include ROOT . DS . 'views' . DS . 'partials' . DS . 'pagination.php'; ?>

    <?php endif; ?>

</div>
