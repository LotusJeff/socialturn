<?php
$isReconnect = $reconnectId > 0;
$showMasked  = $isReconnect && $existingAppKey !== null;
?>
<div class="container py-4" style="max-width:720px">

    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo u('connect', 'index'); ?>">Connections</a></li>
            <li class="breadcrumb-item active"><?php echo $isReconnect ? 'Reconnect Facebook / Instagram' : 'Connect Facebook / Instagram'; ?></li>
        </ol>
    </nav>

    <h2 class="h4 mb-1"><?php echo $isReconnect ? 'Reconnect Facebook / Instagram' : 'Connect Facebook / Instagram'; ?></h2>
    <p class="text-muted mb-4">
        <?php if ($showMasked): ?>
            Re-run the Facebook authorization to refresh your stored token and re-select your Page or
            Instagram Business account. Your current credentials are shown below &mdash; submit to
            reconnect using them, or expand the section below to enter new ones.
        <?php elseif ($isReconnect): ?>
            Enter your Meta developer app credentials to reconnect this account.
        <?php else: ?>
            Enter your Meta developer app credentials below. One app covers both Facebook Pages
            and Instagram Business accounts &mdash; a single authorization connects both. You can
            connect multiple Facebook/Instagram accounts using separate developer apps.
        <?php endif; ?>
    </p>

    <div class="card shadow-sm mb-4">
        <div class="card-body p-4">

            <?php if (!$showMasked): ?>
            <h5 class="mb-3">Before you begin</h5>
            <ol class="small text-muted mb-4">
                <li class="mb-1">Go to <strong>developers.facebook.com</strong> and create an app.</li>
                <li class="mb-1">Add the <strong>Facebook Login</strong> and <strong>Instagram Graph API</strong> products to your app.</li>
                <li class="mb-1">
                    Required permissions: <code>pages_show_list</code>, <code>pages_read_engagement</code>,
                    <code>pages_manage_posts</code>, <code>instagram_basic</code>, <code>instagram_content_publish</code>.
                </li>
                <li class="mb-1">
                    Add this callback URL under Facebook Login &rarr; Valid OAuth Redirect URIs:<br>
                    <code class="user-select-all"><?php echo htmlspecialchars($callbackUrl, ENT_QUOTES, 'UTF-8'); ?></code>
                </li>
                <li class="mb-1">Copy the <strong>App ID</strong> and <strong>App Secret</strong> from App Settings &rarr; Basic.</li>
            </ol>
            <?php endif; ?>

            <form method="post" action="<?php echo u('connect', 'facebook'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <?php if ($isReconnect): ?>
                <input type="hidden" name="reconnect_id" value="<?php echo (int) $reconnectId; ?>">
                <?php endif; ?>

                <?php if ($showMasked): ?>

                <div class="mb-3">
                    <div class="form-label">App ID</div>
                    <div class="font-monospace border rounded px-3 py-2 bg-light small">
                        <?php echo htmlspecialchars((string) $existingAppKey, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>
                <div class="mb-4">
                    <div class="form-label">App Secret</div>
                    <div class="font-monospace border rounded px-3 py-2 bg-light small text-muted">
                        &bull;&bull;&bull;&bull;<?php echo htmlspecialchars((string) $secretLast4, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>

                <details class="mb-4">
                    <summary class="text-muted small" style="cursor:pointer">Enter new credentials</summary>
                    <div class="mt-3">
                        <div class="mb-3">
                            <label class="form-label" for="new_app_key">New App ID</label>
                            <input type="text" class="form-control font-monospace" id="new_app_key"
                                   name="new_app_key" autocomplete="off">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="new_app_secret">New App Secret</label>
                            <input type="text" class="form-control font-monospace" id="new_app_secret"
                                   name="new_app_secret" autocomplete="off">
                        </div>
                        <p class="text-muted small">Leave both blank to keep using the current credentials.</p>
                    </div>
                </details>

                <?php elseif ($isReconnect): ?>

                <div class="mb-3">
                    <label class="form-label" for="new_app_key">App ID</label>
                    <input type="text" class="form-control font-monospace" id="new_app_key"
                           name="new_app_key" autocomplete="off" required>
                </div>
                <div class="mb-4">
                    <label class="form-label" for="new_app_secret">App Secret</label>
                    <input type="text" class="form-control font-monospace" id="new_app_secret"
                           name="new_app_secret" autocomplete="off" required>
                </div>

                <?php else: ?>

                <div class="mb-3">
                    <label class="form-label" for="app_key">App ID</label>
                    <input type="text" class="form-control font-monospace" id="app_key"
                           name="app_key" autocomplete="off" required>
                </div>

                <div class="mb-4">
                    <label class="form-label" for="app_secret">App Secret</label>
                    <input type="text" class="form-control font-monospace" id="app_secret"
                           name="app_secret" autocomplete="off" required>
                </div>

                <?php endif; ?>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><?php echo $isReconnect ? 'Reconnect &rarr;' : 'Continue to Facebook &rarr;'; ?></button>
                    <a href="<?php echo u('connect', 'index'); ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>

        </div>
    </div>

</div>
