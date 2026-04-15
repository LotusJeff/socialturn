<?php
/**
 * Initial setup view — rendered by users/setup.
 *
 * Three states:
 *   Unconfigured — $ownerEmailDefined is false: show config error card.
 *   State A       — $sent is false: show "Send setup email" form.
 *   State B       — $sent is true:  show success confirmation.
 *
 * $noextra is set by the controller — navbar and flash notifications
 * are suppressed on this page.
 */
?>
<div class="min-vh-100 d-flex align-items-center justify-content-center bg-light">
    <div class="col-12 col-sm-10 col-md-6 col-lg-5 col-xl-4">

        <div class="text-center mb-4">
            <img src="<?php echo BASE_URL; ?>assets/img/logo.png" alt="SocialTurn" height="48" class="mb-3">
            <h1 class="h3 fw-semibold">SocialTurn</h1>
            <p class="text-muted mb-0">Self-hosted social media scheduling</p>
        </div>

        <?php if (!empty($setupError)): ?>

            <!-- Error card — config problem or send failure -->
            <div class="card shadow-sm border-danger">
                <div class="card-body p-4">
                    <h2 class="h5 fw-semibold text-danger mb-3">Setup error</h2>
                    <p class="mb-0"><?php echo htmlspecialchars($setupError, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </div>

        <?php elseif (!$ownerEmailDefined): ?>

            <!-- Unconfigured — OWNER_EMAIL missing or placeholder -->
            <div class="card shadow-sm border-warning">
                <div class="card-body p-4">
                    <h2 class="h5 fw-semibold mb-3">Almost there</h2>
                    <p>Before you can complete setup, add your owner email address to
                    <code>config.php</code>:</p>
                    <pre class="bg-light border rounded p-2 small mb-3">define('OWNER_EMAIL', 'you@yourdomain.com');</pre>
                    <p class="mb-0 text-muted small">Reload this page after saving
                    <code>config.php</code>.</p>
                </div>
            </div>

        <?php elseif ($sent): ?>

            <!-- State B — setup email sent -->
            <div class="card shadow-sm">
                <div class="card-body p-4 text-center">
                    <div class="mb-3">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor"
                             class="text-success" viewBox="0 0 16 16" aria-hidden="true">
                            <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.061L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
                        </svg>
                    </div>
                    <h2 class="h5 fw-semibold mb-2">Check your inbox</h2>
                    <p class="mb-1">Setup email sent to
                        <strong><?php echo htmlspecialchars($ownerEmail, ENT_QUOTES, 'UTF-8'); ?></strong>.
                    </p>
                    <p class="mb-3">Click the link in the email to set your password and log in.</p>
                    <p class="text-muted small mb-0">Company name defaults to &ldquo;SocialTurn&rdquo; and
                    can be changed in Settings after login.</p>
                </div>
            </div>

        <?php else: ?>

            <!-- State A — send setup email form -->
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h2 class="h5 fw-semibold mb-1">Welcome</h2>
                    <p class="text-muted mb-4">No accounts exist yet. Send yourself a setup email to
                    create the owner account.</p>

                    <form method="POST" action="<?php echo BASE_URL; ?>users/setup">
                        <input type="hidden" name="csrf_token"
                               value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">
                                Send setup email to
                                <?php echo htmlspecialchars($ownerEmail, ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                        </div>
                    </form>

                    <p class="text-muted small text-center mt-3 mb-0">
                        Wrong address? Update <code>OWNER_EMAIL</code> in <code>config.php</code>
                        and reload.
                    </p>
                </div>
            </div>

        <?php endif; ?>

    </div>
</div>
