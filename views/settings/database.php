<?php
/**
 * Database & Site URL settings — rendered by settings/database.
 *
 * Template variables:
 *   $dbHost       string  Current db_host from config.ini
 *   $dbName       string  Current db_name
 *   $dbUser       string  Current db_user
 *   $baseUrl      string  Current base_url
 *   $saveError    string|null  Error message from POST
 *   $saveSuccess  bool    True when saved successfully
 *   $csrfToken    string
 */
?>
<div class="container py-4">

    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo u('settings'); ?>">Settings</a></li>
            <li class="breadcrumb-item active">Database &amp; Site URL</li>
        </ol>
    </nav>

    <h2 class="h4 mb-4">Database &amp; Site URL</h2>

    <?php if ($saveSuccess): ?>
    <div class="alert alert-success">Settings saved. Changes take effect on the next request.</div>
    <?php endif; ?>

    <?php if ($saveError): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($saveError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12 col-md-7">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <form method="post" action="<?php echo u('settings', 'database'); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="mb-3">
                            <label class="form-label" for="db_host">Database host</label>
                            <input type="text" class="form-control" id="db_host" name="db_host"
                                   value="<?php echo htmlspecialchars($dbHost, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="db_name">Database name</label>
                            <input type="text" class="form-control" id="db_name" name="db_name"
                                   value="<?php echo htmlspecialchars($dbName, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="db_user">Database user</label>
                            <input type="text" class="form-control" id="db_user" name="db_user"
                                   value="<?php echo htmlspecialchars($dbUser, ENT_QUOTES, 'UTF-8'); ?>" required
                                   autocomplete="username">
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="db_pass">Database password</label>
                            <input type="password" class="form-control" id="db_pass" name="db_pass"
                                   placeholder="Leave blank to keep current password"
                                   autocomplete="current-password">
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="base_url">Base URL
                                <span data-bs-toggle="tooltip"
                                      data-bs-title="Full public URL with trailing slash — e.g. https://example.com/socialturn/"
                                      class="text-muted ms-1" style="cursor:default">&#63;</span>
                            </label>
                            <input type="url" class="form-control" id="base_url" name="base_url"
                                   value="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <p class="text-muted small mb-4">
                            A connection test is run before saving. If the test fails, config.ini is not modified.
                        </p>

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
