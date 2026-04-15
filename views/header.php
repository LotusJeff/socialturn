<?php
/**
 * Application header and navigation partial.
 *
 * Loaded by every view. Outputs <head>, opens <body>, and renders the
 * Bootstrap 5 navbar and flash notification when $noextra is not set.
 *
 * Vendored assets — update deliberately, not automatically:
 *   Bootstrap 5.3.3  — https://getbootstrap.com
 *   Alpine.js 3.14.1 — https://alpinejs.dev
 *
 * All asset references point to local /assets/ paths only.
 * No CDN links. To update a vendored library, download the new minified
 * file, replace the file in assets/, and update the version note above.
 *
 * Legacy Bootstrap 3 assets (bootstrap.css, bootstrap.min.js, jasny-bootstrap,
 * bootstrap-select, image-picker) remain in assets/ but are no longer loaded
 * by this header. They will be removed in Phase 8 release cleanup.
 *
 * Variables expected from the controller:
 *   $controller  string  Current controller name — used for active nav state
 *   $action      string  Current action name — used for body class
 *   $noextra     mixed   When set (any truthy value), navbar and notifications
 *                        are suppressed. Used by login, setup, setpassword, forgot.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SocialTurn</title>

    <link rel="shortcut icon" type="image/x-icon" href="<?php echo BASE_URL; ?>favicon.ico">

    <!-- Bootstrap 5.3.3 -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/bootstrap.min.css">

    <!-- Application styles -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/main.css">
</head>

<body class="<?php echo htmlspecialchars($controller ?? '', ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars(($controller ?? '') . '_' . ($action ?? ''), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars(($controller ?? '') . '-' . ($action ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

<?php if (empty($noextra)): ?>

    <!-- ============================================================
         Navigation
         ============================================================ -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">

            <a class="navbar-brand d-flex align-items-center gap-2" href="<?php echo BASE_URL; ?>social/queue">
                <img src="<?php echo BASE_URL; ?>assets/img/logo.png" alt="SocialTurn" height="32">
                SocialTurn
            </a>

            <button class="navbar-toggler" type="button"
                    data-bs-toggle="collapse" data-bs-target="#navbarMain"
                    aria-controls="navbarMain" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarMain">
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0">

                    <!-- Queue — all authenticated users -->
                    <li class="nav-item">
                        <a class="nav-link<?php echo ($controller === 'social') ? ' active' : ''; ?>"
                           href="<?php echo BASE_URL; ?>social/queue">Queue</a>
                    </li>

                    <!-- Content — all authenticated users -->
                    <li class="nav-item">
                        <a class="nav-link<?php echo ($controller === 'posts') ? ' active' : ''; ?>"
                           href="<?php echo BASE_URL; ?>posts">Content</a>
                    </li>

                    <?php if (hasPermission(1)): ?>

                    <!-- Accounts — admin only -->
                    <li class="nav-item">
                        <a class="nav-link<?php echo ($controller === 'accounts') ? ' active' : ''; ?>"
                           href="<?php echo BASE_URL; ?>accounts">Accounts</a>
                    </li>

                    <!-- Team — admin only -->
                    <li class="nav-item">
                        <a class="nav-link<?php echo ($controller === 'team') ? ' active' : ''; ?>"
                           href="<?php echo BASE_URL; ?>team">Team</a>
                    </li>

                    <?php endif; ?>

                    <!-- Logout — all authenticated users -->
                    <li class="nav-item">
                        <a class="nav-link<?php echo ($controller === 'users') ? ' active' : ''; ?>"
                           href="<?php echo BASE_URL; ?>users/logout">Logout</a>
                    </li>

                </ul>
            </div>

        </div>
    </nav>

    <!-- ============================================================
         Flash notifications
         ============================================================ -->
    <?php if (!empty($_SESSION['notification'])): ?>
    <?php
        $notifType    = $_SESSION['notification']['type']    ?? 'info';
        $notifMessage = $_SESSION['notification']['message'] ?? '';
        $alertClass   = match ($notifType) {
            'success' => 'alert-success',
            'error'   => 'alert-danger',
            'warning' => 'alert-warning',
            default   => 'alert-info',
        };
        unset($_SESSION['notification']);
    ?>
    <div class="container mt-3">
        <div class="alert <?php echo $alertClass; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($notifMessage, ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    </div>
    <?php endif; ?>

<?php endif; ?>

<!-- Page content begins below -->
