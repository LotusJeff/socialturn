<?php
/**
 * Application settings — rendered by settings/app.
 *
 * Template variables:
 *   $ownerEmail  string  OWNER_EMAIL
 *   $threshold   int     RECYCLE_THRESHOLD_DEFAULT
 *   $lookahead   int     RECYCLE_LOOKAHEAD_DAYS
 *   $minPosts    int     SCHEDULE_MIN_POSTS
 *   $saveError   string|null
 *   $saveSuccess bool
 *   $csrfToken   string
 */
?>
<div class="container py-4">

    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo u('settings'); ?>">Settings</a></li>
            <li class="breadcrumb-item active">Application</li>
        </ol>
    </nav>

    <h2 class="h4 mb-4">Application Settings</h2>

    <?php if ($saveSuccess): ?>
    <div class="alert alert-success">Application settings saved.</div>
    <?php endif; ?>

    <?php if ($saveError): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($saveError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12 col-md-7">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <form method="post" action="<?php echo u('settings', 'app'); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="mb-4">
                            <label class="form-label" for="owner_email">Owner email</label>
                            <input type="email" class="form-control" id="owner_email" name="owner_email"
                                   value="<?php echo htmlspecialchars($ownerEmail, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-text">
                                Invites sent to this address receive admin access (type=1).
                                Leave blank if you only want the first account created during install to be admin.
                            </div>
                        </div>

                        <hr class="my-4">
                        <h6 class="fw-semibold mb-3">Queue Engine Defaults</h6>
                        <p class="text-muted small mb-3">
                            These are installation-wide defaults. Per-account overrides take precedence
                            where they exist.
                        </p>

                        <div class="mb-3">
                            <label class="form-label" for="recycle_threshold_default">
                                Recycle threshold (default)
                            </label>
                            <input type="number" class="form-control" id="recycle_threshold_default"
                                   name="recycle_threshold_default" min="1" max="9999"
                                   value="<?php echo (int) $threshold; ?>">
                            <div class="form-text">
                                Pending queue depth below which the recycler refills the queue.
                                Recommended: 10&ndash;20.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="recycle_lookahead_days">
                                Lookahead days (default)
                            </label>
                            <input type="number" class="form-control" id="recycle_lookahead_days"
                                   name="recycle_lookahead_days" min="1" max="365"
                                   value="<?php echo (int) $lookahead; ?>">
                            <div class="form-text">
                                How many days ahead the queue engine schedules posts.
                                Recommended: 14&ndash;30.
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="schedule_min_posts">
                                Minimum posts to enable scheduling
                            </label>
                            <input type="number" class="form-control" id="schedule_min_posts"
                                   name="schedule_min_posts" min="1" max="9999"
                                   value="<?php echo (int) $minPosts; ?>">
                            <div class="form-text">
                                Minimum active, recyclable posts required before scheduling can be
                                enabled for an account. Recommended: 5&ndash;25.
                            </div>
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
