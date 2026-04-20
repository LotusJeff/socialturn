<?php
/**
 * Email settings — rendered by settings/email.
 *
 * Template variables:
 *   $pmKey        string  POSTMARKAPP_API_KEY
 *   $pmFrom       string  POSTMARKAPP_MAIL_FROM_ADDRESS
 *   $pmName       string  POSTMARKAPP_MAIL_FROM_NAME
 *   $saveError    string|null
 *   $saveSuccess  bool
 *   $csrfToken    string
 */
?>
<div class="container py-4">

    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo u('settings'); ?>">Settings</a></li>
            <li class="breadcrumb-item active">Email</li>
        </ol>
    </nav>

    <h2 class="h4 mb-4">Email</h2>

    <?php if ($saveSuccess): ?>
    <div class="alert alert-success">Email settings saved.</div>
    <?php endif; ?>

    <?php if ($saveError): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($saveError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12 col-md-7">
            <div class="card shadow-sm">
                <div class="card-body p-4">

                    <p class="text-muted mb-4">
                        Postmark handles password resets and team invites.
                        Get a free API key at <a href="https://postmarkapp.com" target="_blank" rel="noopener">postmarkapp.com</a>
                        (free tier: 100 emails/month).
                    </p>

                    <form method="post" action="<?php echo u('settings', 'email'); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="mb-3">
                            <label class="form-label" for="postmarkapp_api_key">Server API Token
                                <span data-bs-toggle="tooltip"
                                      data-bs-title="From your Postmark Server's API Tokens tab."
                                      class="text-muted ms-1" style="cursor:default">&#63;</span>
                            </label>
                            <input type="text" class="form-control font-monospace" id="postmarkapp_api_key"
                                   name="postmarkapp_api_key"
                                   value="<?php echo htmlspecialchars($pmKey, ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="postmarkapp_mail_from_address">From address
                                <span data-bs-toggle="tooltip"
                                      data-bs-title="Must be a verified sender signature in Postmark. Public email providers (Gmail, Yahoo, Outlook) are not permitted."
                                      class="text-muted ms-1" style="cursor:default">&#63;</span>
                            </label>
                            <input type="email" class="form-control" id="postmarkapp_mail_from_address"
                                   name="postmarkapp_mail_from_address"
                                   value="<?php echo htmlspecialchars($pmFrom, ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="noreply@yourdomain.com">
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="postmarkapp_mail_from_name">From name</label>
                            <input type="text" class="form-control" id="postmarkapp_mail_from_name"
                                   name="postmarkapp_mail_from_name"
                                   value="<?php echo htmlspecialchars($pmName, ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="SocialTurn">
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
