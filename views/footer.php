<?php
/**
 * Application footer partial.
 *
 * Closes <body> and <html>. Loads Bootstrap 5 bundle and Alpine.js.
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
