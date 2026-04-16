<?php
/**
 * Queue status overview — rendered by queue/index.
 *
 * Template variables:
 *   $rows       array   One row per account:
 *                         id, name, is_posting, platform, platform_name,
 *                         pending_count, posted_count, failed_count
 *   $csrfToken  string
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

    <?php if (empty($rows)): ?>

    <div class="card text-center py-5">
        <div class="card-body">
            <h5 class="card-title text-muted">No accounts yet</h5>
            <p class="card-text text-muted small mb-4">
                Create an account and add content to get the queue running.
            </p>
            <a href="<?= BASE_URL ?>accounts/create" class="btn btn-primary">Create Account</a>
        </div>
    </div>

    <?php else: ?>

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light">
                <tr>
                    <th>Account</th>
                    <th style="width:100px">Pending</th>
                    <th style="width:100px">Posted</th>
                    <th style="width:100px">Failed</th>
                    <th style="width:180px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge <?= queue_index_platformBadge((string) $r['platform']) ?>">
                            <?= queue_index_platformLabel((string) $r['platform']) ?>
                        </span>
                        <span class="fw-semibold">
                            <?= htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <?php if (!(int) $r['is_posting']): ?>
                        <span class="badge bg-secondary">Paused</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <?php if ((int) $r['pending_count'] > 0): ?>
                    <a href="<?= BASE_URL ?>queue/view/<?= (int) $r['id'] ?>"
                       class="fw-semibold text-decoration-none">
                        <?= (int) $r['pending_count'] ?>
                    </a>
                    <?php else: ?>
                    <span class="text-muted">0</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ((int) $r['posted_count'] > 0): ?>
                    <a href="<?= BASE_URL ?>queue/history/<?= (int) $r['id'] ?>"
                       class="text-success text-decoration-none">
                        <?= (int) $r['posted_count'] ?>
                    </a>
                    <?php else: ?>
                    <span class="text-muted">0</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ((int) $r['failed_count'] > 0): ?>
                    <a href="<?= BASE_URL ?>queue/errors/<?= (int) $r['id'] ?>"
                       class="text-danger fw-semibold text-decoration-none">
                        <?= (int) $r['failed_count'] ?>
                    </a>
                    <?php else: ?>
                    <span class="text-muted">0</span>
                    <?php endif; ?>
                </td>
                <td class="text-end">
                    <div class="d-flex gap-2 justify-content-end">
                        <a href="<?= BASE_URL ?>queue/view/<?= (int) $r['id'] ?>"
                           class="btn btn-sm btn-outline-secondary">Queue</a>
                        <a href="<?= BASE_URL ?>queue/history/<?= (int) $r['id'] ?>"
                           class="btn btn-sm btn-outline-secondary">History</a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php endif; ?>

</div>
