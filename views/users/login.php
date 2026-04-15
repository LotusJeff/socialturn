<?php
/**
 * Login view — rendered by users/login.
 *
 * Posts to users/login (not the legacy users/validate).
 * $noextra is set by the controller — navbar and flash notifications suppressed.
 *
 * Template variables:
 *   $csrfToken   string
 *   $loginError  string|null  Server-side authentication failure message
 */
?>
<div class="min-vh-100 d-flex align-items-center justify-content-center bg-light">
    <div class="col-12 col-sm-10 col-md-6 col-lg-5 col-xl-4">

        <div class="text-center mb-4">
            <img src="<?php echo BASE_URL; ?>assets/img/logo.png" alt="SocialTurn" height="48" class="mb-3">
            <h1 class="h3 fw-semibold">SocialTurn</h1>
            <p class="text-muted mb-0">Sign in to your account</p>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-4">

                <?php if (!empty($loginError)): ?>
                <div class="alert alert-danger" role="alert">
                    <?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="<?php echo BASE_URL; ?>users/login">
                    <input type="hidden" name="csrf_token"
                           value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="mb-3">
                        <label for="email" class="form-label">Email address</label>
                        <input type="email"
                               id="email"
                               name="email"
                               class="form-control"
                               autocomplete="email"
                               autofocus
                               required>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <input type="password"
                               id="password"
                               name="password"
                               class="form-control"
                               autocomplete="current-password"
                               required>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary btn-lg">Sign in</button>
                    </div>

                    <div class="text-center">
                        <a href="<?php echo BASE_URL; ?>users/forgot"
                           class="text-muted small">Forgot password?</a>
                    </div>

                </form>

            </div>
        </div>

    </div>
</div>
