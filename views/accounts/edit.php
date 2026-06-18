<?php
/**
 * Edit account view — rendered by accounts/edit.
 *
 * Template variables:
 *   $account     array   Account row + joined platform fields
 *   $schedule    array   account_schedules row
 *   $settings    array   account_settings row
 *   $slots       array   account_schedule_slots rows (may be empty)
 *   $platforms   array   All active connected_platforms for this company
 *   $tagDisplay  string  default_tags decoded to comma-separated string
 *   $timezones   array   timezone_identifiers_list()
 *   $csrfToken   string
 */

$scheduleType  = (string) ($schedule['schedule_type']        ?? 'interval');
$interval      = (string) ($schedule['interval']             ?? 'every_hour');
$customMinutes = (int)    ($schedule['custom_interval_minutes'] ?? 60);
$hoursStart    = (int)    ($schedule['active_hours_start']   ?? 8);
$hoursEnd      = (int)    ($schedule['active_hours_end']     ?? 20);
$tz            = (string) ($schedule['timezone']             ?? 'UTC');
$threshold     = (int)    ($settings['recycle_threshold']    ?? 10);
$lookahead     = (int)    ($settings['recycle_lookahead_days'] ?? 30);
$dynImages         = (int) ($account['dynamic_images_enabled']     ?? 0);
$schedulingEnabled = (int) ($settings['scheduling_enabled']        ?? 0);
$minPosts          = defined('SCHEDULE_MIN_POSTS') ? (int) SCHEDULE_MIN_POSTS : 25;
$canEnableSchedule = ($schedulingEnabled === 1) || ($activePostCount >= $minPosts);

// Build JSON-safe slot list for Alpine.js initialisation
$slotTimes = array_map(
    fn(array $s): string => substr((string) $s['time_of_day'], 0, 5),
    $slots
);
$slotTimesJson = json_encode($slotTimes, JSON_HEX_QUOT | JSON_HEX_TAG);
?>
<div class="container py-4" style="max-width:720px">

    <div class="d-flex align-items-center mb-4 gap-3">
        <a href="<?= u('accounts') ?>" class="text-muted text-decoration-none">&larr; Workspaces</a>
        <h1 class="h3 mb-0">
            <?= htmlspecialchars((string) $account['name'], ENT_QUOTES, 'UTF-8') ?>
        </h1>
        <?php if ((int) $account['is_posting']): ?>
            <span class="badge bg-success">Posting</span>
        <?php else: ?>
            <span class="badge bg-secondary">Paused</span>
        <?php endif; ?>
    </div>

    <form method="POST" action="<?= u('accounts', 'update') ?>"
          enctype="multipart/form-data"
          x-data='{
              scheduleType: "<?= htmlspecialchars($scheduleType, ENT_QUOTES, 'UTF-8') ?>",
              interval:     "<?= htmlspecialchars($interval,     ENT_QUOTES, 'UTF-8') ?>",
              dynImages:    <?= $dynImages ? 'true' : 'false' ?>,
              schedulingOn: <?= $schedulingEnabled ? 'true' : 'false' ?>,
              slots: <?= $slotTimesJson ?>,
              addSlot()    { this.slots.push("") },
              removeSlot(i){ this.slots.splice(i, 1) }
          }'>

        <input type="hidden" name="id"         value="<?= (int) $account['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <!-- ================================================================
             Section 1 — Identity
        ================================================================ -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">Identity</div>
            <div class="card-body">

                <div class="mb-3">
                    <label for="name" class="form-label">Workspace name</label>
                    <input type="text" id="name" name="name" class="form-control"
                           value="<?= htmlspecialchars((string) $account['name'], ENT_QUOTES, 'UTF-8') ?>"
                           required>
                </div>

                <div class="mb-3">
                    <label for="connected_platform_id" class="form-label">Connected platform</label>
                    <select id="connected_platform_id" name="connected_platform_id" class="form-select" required>
                        <?php foreach ($platforms as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"
                            <?= (int) $p['id'] === (int) $account['connected_platform_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucfirst((string) $p['platform']), ENT_QUOTES, 'UTF-8') ?>
                            —
                            <?php if (!empty($p['platform_name'])): ?>
                                <?= htmlspecialchars((string) $p['platform_name'], ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                            <?php if (!empty($p['platform_username'])): ?>
                                (@<?= htmlspecialchars((string) $p['platform_username'], ENT_QUOTES, 'UTF-8') ?>)
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty($account['display_name'])): ?>
                    <div class="form-text">
                        Platform profile: <?= htmlspecialchars((string) $account['display_name'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="form-check">
                    <input type="checkbox" id="is_posting" name="is_posting"
                           class="form-check-input" value="1"
                           <?= (int) $account['is_posting'] ? 'checked' : '' ?>>
                    <label for="is_posting" class="form-check-label">Actively posting
                        <span data-bs-toggle="tooltip"
                              data-bs-title="Uncheck to pause the queue without deleting it."
                              class="text-muted ms-1" style="cursor:default">&#63;</span>
                    </label>
                </div>

            </div>
        </div>

        <!-- ================================================================
             Section 2 — Schedule
        ================================================================ -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">Schedule</div>
            <div class="card-body">

                <div class="form-check mb-2">
                    <input type="checkbox" id="scheduling_enabled" name="scheduling_enabled"
                           class="form-check-input" value="1"
                           x-model="schedulingOn"
                           <?= $schedulingEnabled ? 'checked' : '' ?>
                           <?= !$canEnableSchedule ? 'disabled' : '' ?>>
                    <label for="scheduling_enabled" class="form-check-label fw-semibold">
                        Enable automated scheduling
                        <span data-bs-toggle="tooltip"
                              data-bs-title="When enabled, the queue population engine automatically refills this workspace's schedule."
                              class="text-muted ms-1" style="cursor:default">&#63;</span>
                    </label>
                </div>
                <?php if (!$canEnableSchedule): ?>
                <div class="text-muted small mb-3">
                    Automated scheduling requires at least <?= $minPosts ?> active recyclable posts — you currently have <?= $activePostCount ?>.
                </div>
                <?php endif; ?>

                <div x-show="schedulingOn" x-cloak>

                <div class="mb-3">
                    <label class="form-label">Schedule type</label>
                    <div class="d-flex gap-4">
                        <div class="form-check">
                            <input type="radio" id="stype_interval" name="schedule_type"
                                   class="form-check-input" value="interval"
                                   x-model="scheduleType">
                            <label for="stype_interval" class="form-check-label">Interval</label>
                        </div>
                        <div class="form-check">
                            <input type="radio" id="stype_specific" name="schedule_type"
                                   class="form-check-input" value="time_specific"
                                   x-model="scheduleType">
                            <label for="stype_specific" class="form-check-label">Specific times</label>
                        </div>
                    </div>
                </div>

                <!-- Interval fields -->
                <div x-show="scheduleType === 'interval'" x-cloak>

                    <div class="mb-3">
                        <label for="interval" class="form-label">Posting frequency</label>
                        <select id="interval" name="interval" class="form-select" style="max-width:260px"
                                x-model="interval">
                            <option value="every_15min" <?= $interval === 'every_15min' ? 'selected' : '' ?>>Every 15 minutes</option>
                            <option value="every_30min" <?= $interval === 'every_30min' ? 'selected' : '' ?>>Every 30 minutes</option>
                            <option value="every_hour"  <?= $interval === 'every_hour'  ? 'selected' : '' ?>>Every hour</option>
                            <option value="every_2hr"   <?= $interval === 'every_2hr'   ? 'selected' : '' ?>>Every 2 hours</option>
                            <option value="every_4hr"   <?= $interval === 'every_4hr'   ? 'selected' : '' ?>>Every 4 hours</option>
                            <option value="every_8hr"   <?= $interval === 'every_8hr'   ? 'selected' : '' ?>>Every 8 hours</option>
                            <option value="custom"      <?= $interval === 'custom'      ? 'selected' : '' ?>>Custom</option>
                        </select>
                    </div>

                    <div class="mb-3" x-show="interval === 'custom'" x-cloak>
                        <label for="custom_interval_minutes" class="form-label">
                            Custom interval (minutes)
                        </label>
                        <input type="number" id="custom_interval_minutes"
                               name="custom_interval_minutes" class="form-control"
                               style="max-width:140px" min="1"
                               value="<?= $customMinutes ?>">
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-auto">
                            <label for="active_hours_start" class="form-label">Active hours start
                                <span data-bs-toggle="tooltip"
                                      data-bs-title="Start must be earlier than end. Select 24:00 to post through end of day."
                                      class="text-muted ms-1" style="cursor:default">&#63;</span>
                            </label>
                            <select id="active_hours_start" name="active_hours_start" class="form-select" style="max-width:120px">
                                <?php for ($h = 0; $h <= 23; $h++): ?>
                                <option value="<?= $h ?>" <?= $h === $hoursStart ? 'selected' : '' ?>>
                                    <?= sprintf('%02d:00', $h) ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <label for="active_hours_end" class="form-label">Active hours end</label>
                            <select id="active_hours_end" name="active_hours_end" class="form-select" style="max-width:200px">
                                <?php for ($h = 0; $h <= 24; $h++): ?>
                                <option value="<?= $h ?>" <?= $h === $hoursEnd ? 'selected' : '' ?>>
                                    <?= $h === 24 ? '24:00 (midnight — end of day)' : sprintf('%02d:00', $h) ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                </div>

                <!-- Time-specific slot fields -->
                <div x-show="scheduleType === 'time_specific'" x-cloak>

                    <label class="form-label">Post times
                        <span data-bs-toggle="tooltip"
                              data-bs-title="Times are snapped to the nearest 15-minute mark (:00, :15, :30, :45)."
                              class="text-muted ms-1" style="cursor:default">&#63;</span>
                    </label>

                    <div class="mb-2">
                        <template x-for="(slot, i) in slots" :key="i">
                            <div class="d-flex gap-2 mb-2 align-items-center">
                                <input type="time" name="time_slots[]"
                                       x-model="slots[i]"
                                       class="form-control"
                                       style="max-width:140px"
                                       step="900">
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        @click="removeSlot(i)">Remove</button>
                            </div>
                        </template>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            @click="addSlot()">+ Add time</button>

                </div>

                <!-- Timezone (shared by both modes) -->
                <div class="mt-3">
                    <label for="timezone" class="form-label">Timezone
                        <span data-bs-toggle="tooltip"
                              data-bs-title="All schedule times are interpreted in this timezone."
                              class="text-muted ms-1" style="cursor:default">&#63;</span>
                    </label>
                    <select id="timezone" name="timezone" class="form-select" style="max-width:320px">
                        <?php foreach ($timezones as $tzName): ?>
                        <option value="<?= htmlspecialchars($tzName, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $tzName === $tz ? 'selected' : '' ?>>
                            <?= htmlspecialchars($tzName, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                </div><!-- end x-show="schedulingOn" -->

            </div>
        </div>

        <!-- ================================================================
             Section 3 — Content settings
        ================================================================ -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">Content Settings</div>
            <div class="card-body">

                <div class="mb-3">
                    <label for="default_tags" class="form-label">Default tags
                        <span data-bs-toggle="tooltip"
                              data-bs-title="Words only, no # needed — tags are appended in order up to the platform limit."
                              class="text-muted ms-1" style="cursor:default">&#63;</span>
                    </label>
                    <input type="text" id="default_tags" name="default_tags"
                           class="form-control"
                           placeholder="marketing, saas, startup"
                           value="<?= htmlspecialchars($tagDisplay, ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="row g-3">
                    <div class="col-auto">
                        <label for="recycle_threshold" class="form-label">Recycle threshold
                            <span data-bs-toggle="tooltip"
                                  data-bs-title="Refill queue when pending drops below this number."
                                  class="text-muted ms-1" style="cursor:default">&#63;</span>
                        </label>
                        <input type="number" id="recycle_threshold" name="recycle_threshold"
                               class="form-control" style="max-width:120px" min="1"
                               value="<?= $threshold ?>">
                    </div>
                    <div class="col-auto">
                        <label for="recycle_lookahead_days" class="form-label">Lookahead days
                            <span data-bs-toggle="tooltip"
                                  data-bs-title="How far ahead to schedule posts."
                                  class="text-muted ms-1" style="cursor:default">&#63;</span>
                        </label>
                        <input type="number" id="recycle_lookahead_days" name="recycle_lookahead_days"
                               class="form-control" style="max-width:120px" min="1"
                               value="<?= $lookahead ?>">
                    </div>
                </div>

            </div>
        </div>

        <!-- ================================================================
             Section 4 — Dynamic images
        ================================================================ -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">Dynamic Images</div>
            <div class="card-body">

                <div class="form-check mb-3">
                    <input type="checkbox" id="dynamic_images_enabled"
                           name="dynamic_images_enabled" class="form-check-input" value="1"
                           <?= $dynImages ? 'checked' : '' ?>
                           x-model="dynImages">
                    <label for="dynamic_images_enabled" class="form-check-label">
                        Generate image from template when post has no image
                    </label>
                </div>

                <div x-show="dynImages" x-cloak>

                    <?php if (!empty($account['base_image_filename'])): ?>
                    <div class="mb-2 text-muted small">
                        Current base image:
                        <code><?= htmlspecialchars((string) $account['base_image_filename'], ENT_QUOTES, 'UTF-8') ?></code>
                    </div>
                    <input type="hidden" name="base_image_filename_existing"
                           value="<?= htmlspecialchars((string) $account['base_image_filename'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label for="base_image" class="form-label">
                            <?= !empty($account['base_image_filename']) ? 'Replace base image' : 'Upload base image' ?>
                            <span data-bs-toggle="tooltip"
                                  data-bs-title="JPG or PNG. Used by ImageService as the template for generated images."
                                  class="text-muted ms-1" style="cursor:default">&#63;</span>
                        </label>
                        <input type="file" id="base_image" name="base_image"
                               class="form-control" style="max-width:400px"
                               accept=".jpg,.jpeg,.png">
                    </div>

                    <div class="row g-3">
                        <div class="col-auto">
                            <label for="overlay_font_color" class="form-label">Overlay text color
                                <span data-bs-toggle="tooltip"
                                      data-bs-title="Hex color for the text overlay on generated images."
                                      class="text-muted ms-1" style="cursor:default">&#63;</span>
                            </label>
                            <input type="color" id="overlay_font_color" name="overlay_font_color"
                                   class="form-control form-control-color"
                                   value="<?= htmlspecialchars((string) ($account['overlay_font_color'] ?? '#000000'), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-auto">
                            <label for="overlay_font_size" class="form-label">Overlay font size
                                <span data-bs-toggle="tooltip"
                                      data-bs-title="Font size in points for the text overlay. Accepted range: 30–70."
                                      class="text-muted ms-1" style="cursor:default">&#63;</span>
                            </label>
                            <input type="number" id="overlay_font_size" name="overlay_font_size"
                                   class="form-control" style="max-width:100px"
                                   min="30" max="70"
                                   value="<?= (int) ($account['overlay_font_size'] ?? 48) ?>">
                        </div>
                    </div>

                </div>

            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a href="<?= u('accounts') ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>

    </form>

</div>
