<?php
/**
 * Connections listing — rendered by connect/index.
 *
 * Template variables:
 *   $connections  array   All connected_platforms rows for the company,
 *                         with workspace_count from LEFT JOIN to accounts.
 *                         Keys: id, platform, platform_name, platform_username,
 *                               is_active, token_expires_at, created_at,
 *                               workspace_count
 *   $csrfToken    string
 */
?>
<div class="container py-4">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0">Connections</h1>
        <div class="d-flex gap-2">
            <a href="<?= u('connect', 'twitter') ?>" class="btn btn-primary btn-sm">Connect Twitter</a>
            <a href="<?= u('connect', 'facebook') ?>" class="btn btn-primary btn-sm">Connect Facebook / Instagram</a>
        </div>
    </div>

    <?php if (!empty($_SESSION['notification'])): ?>
    <div class="alert alert-<?= htmlspecialchars($_SESSION['notification']['type'] === 'error' ? 'danger' : $_SESSION['notification']['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars((string) $_SESSION['notification']['message'], ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['notification']); endif; ?>

    <?php if (empty($connections)): ?>
    <div class="card text-center py-5">
        <div class="card-body">
            <h5 class="card-title">No connections yet</h5>
            <p class="card-text text-muted mb-4">
                Connect a Twitter or Facebook / Instagram account to get started.
            </p>
            <div class="d-flex gap-2 justify-content-center">
                <a href="<?= u('connect', 'twitter') ?>" class="btn btn-primary">Connect Twitter</a>
                <a href="<?= u('connect', 'facebook') ?>" class="btn btn-primary">Connect Facebook / Instagram</a>
            </div>
        </div>
    </div>

    <?php else: ?>

    <div class="list-group">
        <?php foreach ($connections as $conn): ?>
        <div class="list-group-item px-3 py-3">
            <div class="d-flex align-items-center justify-content-between gap-3">

                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge <?= platformBadgeClass((string) $conn['platform']) ?>">
                            <?= platformLabel((string) $conn['platform']) ?>
                        </span>
                        <?= connectionStatus($conn) ?>
                    </div>
                    <div class="fw-semibold">
                        <?= htmlspecialchars((string) $conn['platform_name'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if (!empty($conn['platform_username'])): ?>
                            <span class="text-muted fw-normal">
                                &middot; @<?= htmlspecialchars((string) $conn['platform_username'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted small mt-1">
                        <?= (int) $conn['workspace_count'] ?> <?= (int) $conn['workspace_count'] === 1 ? 'workspace' : 'workspaces' ?>
                        &middot; Connected <?= htmlspecialchars(date('M j, Y', strtotime((string) $conn['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>

                <div class="flex-shrink-0">
                    <?php if ((int) $conn['workspace_count'] > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger" disabled
                            data-bs-toggle="tooltip"
                            data-bs-title="Remove all workspaces using this connection before disconnecting.">
                        Disconnect
                    </button>
                    <?php else: ?>
                    <form method="POST" action="<?= u('connect', 'disconnect') ?>" x-data>
                        <input type="hidden" name="connected_platform_id" value="<?= (int) $conn['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                @click.prevent="window.confirm('Disconnect <?= htmlspecialchars(addslashes((string) $conn['platform_name']), ENT_QUOTES, 'UTF-8') ?>? This removes the stored token and credentials.') && $el.closest('form').submit()">
                            Disconnect
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>

</div>
