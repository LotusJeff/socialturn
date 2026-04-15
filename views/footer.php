<?php
/**
 * Application footer partial.
 *
 * Closes <body> and <html>. Loads Bootstrap 5 bundle and Alpine.js.
 *
 * Legacy assets removed in this rebuild (Phase 6a):
 *   jQuery 1.10.2, bootstrap.min.js (Bootstrap 3), jasny-bootstrap,
 *   bootstrap-select, bootstrap-maxlength, image-picker, mininotification.
 * These files remain in assets/js/ but are no longer loaded.
 * They will be deleted in Phase 8 release cleanup.
 *
 * main.js was jQuery-dependent and has been removed from the load order.
 * Confirmation dialogs will be re-implemented with Alpine.js as each
 * view is rebuilt in Phase 6/7.
 *
 * Flash notifications are rendered in header.php — not here.
 */
?>

    <div class="container py-4"></div>

    <!-- Bootstrap 5.3.3 bundle (includes Popper) -->
    <script src="<?php echo BASE_URL; ?>assets/js/bootstrap.bundle.min.js"></script>

    <!-- Alpine.js 3.14.1 — must load after DOM, before first x-data is parsed -->
    <script defer src="<?php echo BASE_URL; ?>assets/js/alpine.min.js"></script>

</body>
</html>
