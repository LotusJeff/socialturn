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
                            <label class="form-label" for="owner_email">Owner email
                                <span data-bs-toggle="tooltip"
                                      data-bs-title="Invited users receive admin access. Leave blank if no additional admins are needed."
                                      class="text-muted ms-1" style="cursor:default">&#63;</span>
                            </label>
                            <input type="email" class="form-control" id="owner_email" name="owner_email"
                                   value="<?php echo htmlspecialchars($ownerEmail, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <hr class="my-4">
                        <h6 class="fw-semibold mb-3">Queue Engine Defaults</h6>
                        <p class="text-muted small mb-3">
                            These are installation-wide defaults. Per-workspace overrides take precedence
                            where they exist.
                        </p>

                        <div class="mb-3">
                            <label class="form-label" for="recycle_threshold_default">
                                Recycle threshold (default)
                                <span data-bs-toggle="tooltip"
                                      data-bs-title="Pending queue depth below which the recycler refills the queue. Recommended: 10–20."
                                      class="text-muted ms-1" style="cursor:default">&#63;</span>
                            </label>
                            <input type="number" class="form-control" id="recycle_threshold_default"
                                   name="recycle_threshold_default" min="1" max="9999"
                                   value="<?php echo (int) $threshold; ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="recycle_lookahead_days">
                                Lookahead days (default)
                                <span data-bs-toggle="tooltip"
                                      data-bs-title="How many days ahead the queue engine schedules posts. Recommended: 14–30."
                                      class="text-muted ms-1" style="cursor:default">&#63;</span>
                            </label>
                            <input type="number" class="form-control" id="recycle_lookahead_days"
                                   name="recycle_lookahead_days" min="1" max="365"
                                   value="<?php echo (int) $lookahead; ?>">
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="schedule_min_posts">
                                Minimum posts to enable scheduling
                                <span data-bs-toggle="tooltip"
                                      data-bs-title="Minimum active, recyclable posts required before scheduling can be enabled for a workspace. Recommended: 5–25."
                                      class="text-muted ms-1" style="cursor:default">&#63;</span>
                            </label>
                            <input type="number" class="form-control" id="schedule_min_posts"
                                   name="schedule_min_posts" min="1" max="9999"
                                   value="<?php echo (int) $minPosts; ?>">
                        </div>

                        <hr class="my-4">
                        <h6 class="fw-semibold mb-1">Email Notifications</h6>
                        <p class="text-muted small mb-3">
                            Requires Postmark to be configured on the
                            <a href="<?php echo u('settings', 'email'); ?>">Email settings</a> page.
                        </p>

                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       id="notify_post_failure" name="notify_post_failure"
                                       value="1"
                                       <?php echo $notifyFailure === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="notify_post_failure">
                                    Send failure alerts
                                    <span data-bs-toggle="tooltip"
                                          data-bs-title="Sends an immediate alert when a post fails to publish. Requires Postmark to be configured."
                                          class="text-muted ms-1" style="cursor:default">&#63;</span>
                                </label>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="notify_recap_frequency">
                                Recap frequency
                                <span data-bs-toggle="tooltip"
                                      data-bs-title="Periodic summary of posting activity. Sent at the start of the next cron run after the period ends."
                                      class="text-muted ms-1" style="cursor:default">&#63;</span>
                            </label>
                            <select class="form-select" id="notify_recap_frequency"
                                    name="notify_recap_frequency" style="max-width:200px">
                                <option value="never"  <?php echo $notifyFrequency === 'never'  ? 'selected' : ''; ?>>Never</option>
                                <option value="daily"  <?php echo $notifyFrequency === 'daily'  ? 'selected' : ''; ?>>Daily</option>
                                <option value="weekly" <?php echo $notifyFrequency === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="notify_recipient_email">
                                Notification recipient
                                <span data-bs-toggle="tooltip"
                                      data-bs-title="Address to receive failure alerts and recap emails. Leave blank to use the owner email address."
                                      class="text-muted ms-1" style="cursor:default">&#63;</span>
                            </label>
                            <input type="email" class="form-control" id="notify_recipient_email"
                                   name="notify_recipient_email"
                                   value="<?php echo htmlspecialchars($notifyEmail, ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="Leave blank to use owner email">
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
