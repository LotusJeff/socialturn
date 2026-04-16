<?php
/**
 * Forgot password view — rendered by users/forgot.
 *
 * State A: email input form.
 * State B: success confirmation — deliberately vague to avoid
 *          revealing whether the submitted address has an account.
 *
 * $noextra is set by the controller — navbar and flash notifications suppressed.
 *
 * Template variables:
 *   $csrfToken  string
 *   $sent       bool   True after POST — show State B regardless of outcome
 */
?>
<div class="min-vh-100 d-flex align-items-center justify-content-center bg-light">
    <div class="col-12 col-sm-10 col-md-6 col-lg-5 col-xl-4">

        <div class="text-center mb-4">
            <img src="<?php echo BASE_URL; ?>assets/img/logo.png" alt="SocialTurn" height="48" class="mb-3">
            <h1 class="h3 fw-semibold">Reset password</h1>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-4">

                <?php if ($sent): ?>

                    <!-- State B — success (same message regardless of whether email was found) -->
                    <div class="text-center">
                        <div class="mb-3">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48"
                                 fill="currentColor" class="text-success" viewBox="0 0 16 16"
                                 aria-hidden="true">
                                <path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1zm13 2.383-4.708 2.825L15 11.105zm-.034 6.876-5.64-3.471L8 9.583l-1.326-.795-5.64 3.47A1 1 0 0 0 2 13h12a1 1 0 0 0 .966-.741M1 11.105l4.708-2.897L1 5.383z"/>
                            </svg>
                        </div>
                        <p class="mb-1 fw-semibold">Check your inbox</p>
                        <p class="text-muted mb-4">If that address has an account, a reset link
                        is on its way. The link expires in 48 hours.</p>
                        <a href="<?php echo BASE_URL; ?>users/login"
                           class="btn btn-outline-secondary">Back to sign in</a>
                    </div>

                <?php else: ?>

                    <!-- State A — email input -->
                    <p class="text-muted mb-4">Enter your email address and we&rsquo;ll send
                    you a link to reset your password.</p>

                    <form method="POST" action="<?php echo BASE_URL; ?>users/forgot">
                        <input type="hidden" name="csrf_token"
                               value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="mb-4">
                            <label for="email" class="form-label">Email address</label>
                            <input type="email"
                                   id="email"
                                   name="email"
                                   class="form-control"
                                   autocomplete="email"
                                   autofocus
                                   required>
                        </div>

                        <div class="d-grid mb-3">
                            <button type="submit" class="btn btn-primary">Send reset link</button>
                        </div>

                        <div class="text-center">
                            <a href="<?php echo BASE_URL; ?>users/login"
                               class="text-muted small">Back to sign in</a>
                        </div>

                    </form>

                <?php endif; ?>

            </div>
        </div>

    </div>
</div>
