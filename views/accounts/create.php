<?php
/**
 * Create account view — rendered by accounts/create.
 *
 * Template variables:
 *   $platforms  array   All active connected_platforms for this company
 *   $preselect  int     platform_id to pre-select (from ?platform_id= query param)
 *   $csrfToken  string
 */
?>
<div class="container py-4" style="max-width:600px">

    <div class="d-flex align-items-center mb-4 gap-3">
        <a href="<?= u('accounts') ?>" class="text-muted text-decoration-none">&larr; Accounts</a>
        <h1 class="h3 mb-0">New Account</h1>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="<?= u('accounts', 'store') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <div class="mb-3">
                    <label for="name" class="form-label fw-semibold">
                        Account name <span class="text-danger">*</span>
                    </label>
                    <input type="text" id="name" name="name" class="form-control"
                           placeholder="e.g. Brand X — Morning Posts" required autofocus>
                    <div class="form-text">
                        Internal label — not shown publicly. Helps you tell accounts apart.
                    </div>
                </div>

                <div class="mb-3">
                    <label for="connected_platform_id" class="form-label fw-semibold">
                        Connected platform <span class="text-danger">*</span>
                    </label>
                    <select id="connected_platform_id" name="connected_platform_id"
                            class="form-select" required>
                        <option value="">— select a platform —</option>
                        <?php foreach ($platforms as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"
                            <?= (int) $p['id'] === $preselect ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucfirst((string) $p['platform']), ENT_QUOTES, 'UTF-8') ?>
                            —
                            <?php if (!empty($p['platform_name'])): ?>
                                <?= htmlspecialchars((string) $p['platform_name'], ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                            <?php if (!empty($p['platform_username'])): ?>
                                (@<?= htmlspecialchars((string) $p['platform_username'], ENT_QUOTES, 'UTF-8') ?>)
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">
                        Don't see your platform?
                        <a href="<?= u('connect', 'twitter') ?>">Connect Twitter</a> or
                        <a href="<?= u('connect', 'facebook') ?>">Connect Facebook / Instagram</a> first.
                    </div>
                </div>

                <div class="mb-4">
                    <div class="form-check">
                        <input type="checkbox" id="is_posting" name="is_posting"
                               class="form-check-input" value="1">
                        <label for="is_posting" class="form-check-label">
                            Start posting immediately
                        </label>
                    </div>
                    <div class="form-text ms-4">
                        Leave unchecked to configure the schedule first. You can enable posting
                        from the account settings page.
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Create Account</button>
                    <a href="<?= u('accounts') ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

</div>
