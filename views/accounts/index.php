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
        <h1 class="h3 mb-0">Accounts</h1>
        <div class="d-flex gap-2">
            <a href="<?= u('connect', 'twitter') ?>"  class="btn btn-outline-secondary btn-sm">+ Twitter</a>
            <a href="<?= u('connect', 'facebook') ?>" class="btn btn-outline-secondary btn-sm">+ Facebook / Instagram</a>
            <a href="<?= u('accounts', 'create') ?>"  class="btn btn-primary btn-sm">New Account</a>
        </div>
    </div>

    <?php if (!empty($_SESSION['notification'])): ?>
    <div class="alert alert-<?= htmlspecialchars($_SESSION['notification']['type'] === 'error' ? 'danger' : $_SESSION['notification']['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars((string) $_SESSION['notification']['message'], ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['notification']); endif; ?>

    <?php if (empty($accounts) && empty($unconnected)): ?>
    <!-- No connections at all — first-time prompt -->
    <div class="card text-center py-5">
        <div class="card-body">
            <h5 class="card-title">No platforms connected yet</h5>
            <p class="card-text text-muted mb-4">
                Connect a Twitter or Facebook account to get started.<br>
                One Facebook authorization covers both Facebook Pages and Instagram Business accounts.
            </p>
            <a href="<?= u('connect', 'twitter') ?>"  class="btn btn-outline-dark me-2">Connect Twitter / X</a>
            <a href="<?= u('connect', 'facebook') ?>" class="btn btn-primary">Connect Facebook / Instagram</a>
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

    <?php if (!empty($unconnected)): ?>
    <!-- Available connections without an account yet -->
    <h6 class="text-muted text-uppercase small fw-semibold mb-2">Available Connections</h6>
    <div class="list-group">
        <?php foreach ($unconnected as $cp): ?>
        <div class="list-group-item px-3 py-2 d-flex align-items-center justify-content-between">
            <div>
                <span class="badge <?= platformBadgeClass((string) $cp['platform']) ?> me-2">
                    <?= platformLabel((string) $cp['platform']) ?>
                </span>
                <?php if (!empty($cp['platform_name'])): ?>
                    <?= htmlspecialchars((string) $cp['platform_name'], ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
                <?php if (!empty($cp['platform_username'])): ?>
                    <span class="text-muted small ms-1">
                        @<?= htmlspecialchars((string) $cp['platform_username'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>
            </div>
            <a href="<?= u('accounts', 'create', ['platform_id' => (int) $cp['id']]) ?>"
               class="btn btn-sm btn-outline-primary">Create Account</a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div>
