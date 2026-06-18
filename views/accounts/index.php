<?php
/**
 * Accounts index view — rendered by accounts/index.
 *
 * Template variables:
 *   $accounts     array   Active accounts joined to connected_platforms
 *   $unconnected  array   Connected platforms with no account yet
 *   $csrfToken    string
 */

/**
 * Returns a Bootstrap badge class for a platform name.
 */
function platformBadgeClass(string $platform): string
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
function platformLabel(string $platform): string
{
    return match ($platform) {
        'twitter'   => 'Twitter / X',
        'facebook'  => 'Facebook',
        'instagram' => 'Instagram',
        default     => ucfirst($platform),
    };
}

/**
 * Returns connection status badge HTML.
 * active=0 → Disconnected; near expiry → Expires soon; otherwise → Connected.
 */
function connectionStatus(array $account): string
{
    if (!(int) $account['platform_active']) {
        return '<span class="badge bg-danger">Disconnected</span>';
    }
    if (!empty($account['token_expires_at'])) {
        $expiresAt = new DateTimeImmutable($account['token_expires_at']);
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
        <h1 class="h3 mb-0">Workspaces</h1>
        <a href="<?= u('accounts', 'create') ?>" class="btn btn-primary btn-sm">New Workspace</a>
    </div>

    <?php if (!empty($_SESSION['notification'])): ?>
    <div class="alert alert-<?= htmlspecialchars($_SESSION['notification']['type'] === 'error' ? 'danger' : $_SESSION['notification']['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars((string) $_SESSION['notification']['message'], ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['notification']); endif; ?>

    <?php if (empty($accounts)): ?>
    <div class="card text-center py-5">
        <div class="card-body">
            <h5 class="card-title">No workspaces yet</h5>
            <p class="card-text text-muted mb-4">
                Create a workspace to start scheduling posts for a connected platform.
            </p>
            <a href="<?= u('accounts', 'create') ?>" class="btn btn-primary">New Workspace</a>
        </div>
    </div>

    <?php else: ?>

    <?php if (!empty($accounts)): ?>
    <!-- Active accounts -->
    <div class="list-group mb-4">
        <?php foreach ($accounts as $a): ?>
        <div class="list-group-item px-3 py-3">
            <div class="d-flex align-items-center justify-content-between gap-3">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge <?= platformBadgeClass((string) $a['platform']) ?>">
                            <?= platformLabel((string) $a['platform']) ?>
                        </span>
                        <?= connectionStatus($a) ?>
                        <?php if (!(int) $a['is_posting']): ?>
                            <span class="badge bg-secondary">Paused</span>
                        <?php else: ?>
                            <span class="badge bg-success">Posting</span>
                        <?php endif; ?>
                    </div>
                    <div class="fw-semibold">
                        <?= htmlspecialchars((string) $a['name'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php if (!empty($a['display_name']) && $a['display_name'] !== $a['name']): ?>
                    <div class="text-muted small">
                        <?= htmlspecialchars((string) $a['display_name'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if (!empty($a['platform_username'])): ?>
                            &middot; @<?= htmlspecialchars((string) $a['platform_username'], ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2 flex-shrink-0">
                    <a href="<?= u('accounts', 'edit', ['id' => (int) $a['id']]) ?>"
                       class="btn btn-sm btn-outline-secondary">Edit</a>
                    <form method="POST" action="<?= u('accounts', 'delete') ?>"
                          onsubmit="return confirm('Archive <?= htmlspecialchars(addslashes((string) $a['name']), ENT_QUOTES, 'UTF-8') ?>? This will stop the queue. Posts and history are preserved.')">
                        <input type="hidden" name="id"         value="<?= (int) $a['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Archive</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>


    <?php endif; ?>

</div>
