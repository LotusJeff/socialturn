<div class="container py-4" style="max-width:720px">

    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo u('connect', 'index'); ?>">Connections</a></li>
            <li class="breadcrumb-item active">Connect Twitter / X</li>
        </ol>
    </nav>

    <h2 class="h4 mb-1">Connect Twitter / X</h2>
    <p class="text-muted mb-4">
        Enter your Twitter developer app credentials below. Each connection uses its own
        Consumer Key and Secret — you can connect multiple Twitter accounts using separate
        developer apps.
    </p>

    <div class="card shadow-sm mb-4">
        <div class="card-body p-4">

            <h5 class="mb-3">Before you begin</h5>
            <ol class="small text-muted mb-4">
                <li class="mb-1">Go to <strong>developer.twitter.com</strong> and create a project and app.</li>
                <li class="mb-1">Set app permissions to <strong>Read and Write</strong>.</li>
                <li class="mb-1">
                    Add this callback URL to your app&rsquo;s OAuth settings:<br>
                    <code class="user-select-all"><?php echo htmlspecialchars($callbackUrl, ENT_QUOTES, 'UTF-8'); ?></code>
                </li>
                <li class="mb-1">Copy the <strong>Consumer Key (API Key)</strong> and <strong>Consumer Secret (API Secret)</strong> from the app&rsquo;s Keys and Tokens page.</li>
            </ol>

            <form method="post" action="<?php echo u('connect', 'twitter'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="mb-3">
                    <label class="form-label" for="app_key">Consumer Key (API Key)</label>
                    <input type="text" class="form-control font-monospace" id="app_key"
                           name="app_key" autocomplete="off" required>
                </div>

                <div class="mb-4">
                    <label class="form-label" for="app_secret">Consumer Secret (API Secret)</label>
                    <input type="text" class="form-control font-monospace" id="app_secret"
                           name="app_secret" autocomplete="off" required>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Continue to Twitter &rarr;</button>
                    <a href="<?php echo u('connect', 'index'); ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>

        </div>
    </div>

</div>
