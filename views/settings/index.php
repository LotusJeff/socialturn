<?php
/**
 * Settings overview — rendered by settings/index.
 *
 * Template variables:
 *   $dbHost          string   Current DB host from config.ini
 *   $dbName          string   Current DB name from config.ini
 *   $baseUrl         string   Current base URL from config.ini
 *   $pmConfigured    bool     True if POSTMARKAPP_API_KEY is non-empty
 *   $connectionCount int      Count of connected_platforms rows for the company
 *   $memberCount     int      Count of active type=100 users for the company
 */
?>
<div class="container py-4">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h2 class="h4 mb-0">Settings</h2>
    </div>

    <div class="row g-3">

        <div class="col-12 col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">Database &amp; Site URL</h5>
                    <p class="card-text text-muted small">
                        Host: <code><?php echo htmlspecialchars($dbHost, ENT_QUOTES, 'UTF-8'); ?></code><br>
                        Database: <code><?php echo htmlspecialchars($dbName, ENT_QUOTES, 'UTF-8'); ?></code><br>
                        Base URL: <code><?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?></code>
                    </p>
                    <a href="<?php echo u('settings', 'database'); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">Email</h5>
                    <p class="card-text text-muted small">
                        Postmark credentials for password resets and team invites.
                    </p>
                    <?php if ($pmConfigured): ?>
                        <span class="badge bg-success mb-2">Configured</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark mb-2">Not configured</span>
                    <?php endif; ?>
                    <br>
                    <a href="<?php echo u('settings', 'email'); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">Application</h5>
                    <p class="card-text text-muted small">
                        Owner email, queue thresholds, scheduling defaults, and email notifications.
                    </p>
                    <?php if ($notifyFailure === '1'): ?>
                        <span class="badge bg-success mb-2">Failure alerts on</span>
                    <?php else: ?>
                        <span class="badge bg-secondary mb-2">Failure alerts off</span>
                    <?php endif; ?>
                    <br>
                    <a href="<?php echo u('settings', 'app'); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">Team</h5>
                    <p class="card-text text-muted small">
                        Team members and their workspace access.
                    </p>
                    <?php if ($memberCount > 0): ?>
                        <span class="badge bg-success mb-2"><?php echo (int) $memberCount; ?> <?php echo $memberCount === 1 ? 'member' : 'members'; ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary mb-2">No members</span>
                    <?php endif; ?>
                    <br>
                    <a href="<?php echo u('team'); ?>" class="btn btn-sm btn-outline-primary">Manage</a>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">Connections</h5>
                    <p class="card-text text-muted small">
                        Platform API credentials and connected social media accounts.
                    </p>
                    <?php if ($connectionCount > 0): ?>
                        <span class="badge bg-success mb-2"><?php echo (int) $connectionCount; ?> connected</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark mb-2">None connected</span>
                    <?php endif; ?>
                    <br>
                    <a href="<?php echo u('connect', 'index'); ?>" class="btn btn-sm btn-outline-primary">Manage</a>
                </div>
            </div>
        </div>

    </div>

</div>
