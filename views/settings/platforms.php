<?php
/**
 * Platform credentials settings — rendered by settings/platforms.
 *
 * Template variables:
 *   $twKey       string  TWITTER_APIKEY
 *   $twSecret    string  TWITTER_APISECRET
 *   $metaId      string  META_APP_ID
 *   $metaSecret  string  META_APP_SECRET
 *   $saveError   string|null
 *   $saveSuccess bool
 *   $csrfToken   string
 */
?>
<div class="container py-4">

    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo u('settings'); ?>">Settings</a></li>
            <li class="breadcrumb-item active">Platform Credentials</li>
        </ol>
    </nav>

    <h2 class="h4 mb-4">Platform Credentials</h2>

    <?php if ($saveSuccess): ?>
    <div class="alert alert-success">Platform credentials saved.</div>
    <?php endif; ?>

    <?php if ($saveError): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($saveError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12 col-md-8">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <form method="post" action="<?php echo u('settings', 'platforms'); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                        <h5 class="mb-3">Twitter / X</h5>
                        <p class="text-muted small mb-3">
                            Create a project and app at <strong>developer.twitter.com</strong>.
                            Set app permissions to <strong>Read and Write</strong>.
                            Copy the Consumer Key (API Key) and Consumer Secret (API Secret) from
                            the app&rsquo;s Keys and Tokens page.
                            Register the OAuth callback URL:
                            <code><?php echo htmlspecialchars(BASE_URL . 'index.php?c=connect&a=twitterCallback', ENT_QUOTES, 'UTF-8'); ?></code>
                        </p>

                        <div class="mb-3">
                            <label class="form-label" for="twitter_apikey">Consumer Key (API Key)</label>
                            <input type="text" class="form-control font-monospace" id="twitter_apikey"
                                   name="twitter_apikey"
                                   value="<?php echo htmlspecialchars($twKey, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="twitter_apisecret">Consumer Secret (API Secret)</label>
                            <input type="text" class="form-control font-monospace" id="twitter_apisecret"
                                   name="twitter_apisecret"
                                   value="<?php echo htmlspecialchars($twSecret, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <hr class="my-4">

                        <h5 class="mb-3">Facebook / Instagram</h5>
                        <p class="text-muted small mb-3">
                            Create an app at <strong>developers.facebook.com</strong>. Add the
                            <strong>Facebook Login</strong> and <strong>Instagram Graph API</strong> products.
                            Required permissions: <code>pages_manage_posts</code>, <code>pages_read_engagement</code>,
                            <code>instagram_basic</code>, <code>instagram_content_publish</code>.
                            Copy the App ID and App Secret from App Settings &rarr; Basic.
                            Register the OAuth callback URL:
                            <code><?php echo htmlspecialchars(BASE_URL . 'index.php?c=connect&a=facebookCallback', ENT_QUOTES, 'UTF-8'); ?></code>
                        </p>

                        <div class="mb-3">
                            <label class="form-label" for="meta_app_id">App ID</label>
                            <input type="text" class="form-control font-monospace" id="meta_app_id"
                                   name="meta_app_id"
                                   value="<?php echo htmlspecialchars($metaId, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="meta_app_secret">App Secret</label>
                            <input type="text" class="form-control font-monospace" id="meta_app_secret"
                                   name="meta_app_secret"
                                   value="<?php echo htmlspecialchars($metaSecret, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Save</button>
                            <a href="<?php echo u('settings'); ?>" class="btn btn-outline-secondary">Cancel</a>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
