<div class="container py-4" style="max-width:720px">

    <h2 class="mb-1">Connect a Facebook Page or Instagram Account</h2>
    <p class="text-muted mb-4">
        Select which Page or account to connect. Each selection creates one connection
        that you can attach to a workspace. You can return here to connect additional
        Pages or Instagram accounts from the same authorization.
    </p>

    <?php if (!empty($pageList)): ?>

    <h5 class="mb-3">
        <span class="badge bg-primary me-1">f</span> Facebook Pages
    </h5>
    <div class="list-group mb-4">
        <?php foreach ($pageList as $page): ?>
        <div class="list-group-item d-flex align-items-center justify-content-between gap-3">
            <span><?= htmlspecialchars((string) $page['name'], ENT_QUOTES, 'UTF-8') ?></span>
            <form method="POST" action="<?= u('connect', 'savePage') ?>" class="flex-shrink-0">
                <input type="hidden" name="platform"            value="facebook">
                <input type="hidden" name="platform_account_id" value="<?= htmlspecialchars((string) $page['id'],   ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="platform_name"       value="<?= htmlspecialchars((string) $page['name'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="platform_username"   value="">
                <input type="hidden" name="csrf_token"          value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn-sm btn-primary">Connect Page</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>

    <?php if (!empty($igList)): ?>

    <h5 class="mb-3">
        <span class="badge bg-danger me-1">&#9679;</span> Instagram Business Accounts
    </h5>
    <p class="text-muted small mb-3">
        These Instagram Business accounts are linked to one of your Facebook Pages.
        They use the same Page authorization — no separate Instagram login is needed.
    </p>
    <div class="list-group mb-4">
        <?php foreach ($igList as $ig): ?>
        <div class="list-group-item d-flex align-items-center justify-content-between gap-3">
            <span>
                <?php if ($ig['username'] !== ''): ?>
                    @<?= htmlspecialchars((string) $ig['username'], ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($ig['name'] !== ''): ?>
                        <small class="text-muted ms-2"><?= htmlspecialchars((string) $ig['name'], ENT_QUOTES, 'UTF-8') ?></small>
                    <?php endif; ?>
                <?php else: ?>
                    <?= htmlspecialchars((string) $ig['name'], ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </span>
            <form method="POST" action="<?= u('connect', 'savePage') ?>" class="flex-shrink-0">
                <input type="hidden" name="platform"            value="instagram">
                <input type="hidden" name="platform_account_id" value="<?= htmlspecialchars((string) $ig['id'],       ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="platform_name"       value="<?= htmlspecialchars((string) $ig['name'],     ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="platform_username"   value="<?= htmlspecialchars((string) $ig['username'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="csrf_token"          value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn-sm btn-danger">Connect Account</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>

    <hr class="my-4">
    <a href="<?= u('connect', 'cancel') ?>" class="text-muted small">
        Cancel — return to workspaces without connecting
    </a>

</div>
