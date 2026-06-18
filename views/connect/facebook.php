<div class="container py-4" style="max-width:720px">

    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo u('connect', 'index'); ?>">Connections</a></li>
            <li class="breadcrumb-item active">Connect Facebook / Instagram</li>
        </ol>
    </nav>

    <h2 class="h4 mb-1">Connect Facebook / Instagram</h2>
    <p class="text-muted mb-4">
        Enter your Meta developer app credentials below. One app covers both Facebook Pages
        and Instagram Business accounts — a single authorization connects both. You can
        connect multiple Facebook/Instagram accounts using separate developer apps.
    </p>

    <div class="card shadow-sm mb-4">
        <div class="card-body p-4">

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

            <form method="post" action="<?php echo u('connect', 'facebook'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

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

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Continue to Facebook &rarr;</button>
                    <a href="<?php echo u('connect', 'index'); ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>

        </div>
    </div>

</div>
