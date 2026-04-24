<?php
declare(strict_types=1);

use SocialTurn\Services\StorageService;

/**
 * Account management controller — Admin only (type=1).
 *
 * An account is a posting identity that wraps a connected platform. It owns
 * the content library, queue, schedule definition, and queue settings for
 * that platform connection. An account cannot be created without a connected
 * platform (connected_platform_id NOT NULL).
 *
 * Functions:
 *   index()   — list active accounts and unattached platform connections
 *   create()  — render create form
 *   store()   — process create POST; seeds schedule and settings rows
 *   edit()    — render edit form with full account configuration
 *   update()  — process edit POST; cascades pending queue on schedule change
 *   delete()  — soft-delete (is_active=0); preserves posts and history
 */

checkPermission(1);

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------

function accounts_companyId(): int
{
    return (int) ($_SESSION['user']['company_id'] ?? $_SESSION['user']['companyid'] ?? 0);
}

// -----------------------------------------------------------------------
// Actions
// -----------------------------------------------------------------------

/**
 * Index — lists active accounts joined to their platform, plus any
 * connected platforms that have no account yet (to prompt creation).
 */
function index(): void
{
    global $dbh, $template;

    $companyId = accounts_companyId();

    $stmt = $dbh->prepare(
        'SELECT a.id, a.name, a.display_name, a.is_posting, a.dynamic_images_enabled,
                cp.id AS cp_id, cp.platform, cp.platform_name, cp.platform_username,
                cp.is_active AS platform_active, cp.token_expires_at
           FROM accounts a
           JOIN connected_platforms cp ON cp.id = a.connected_platform_id
          WHERE a.company_id = ? AND a.is_active = 1
          ORDER BY a.name ASC'
    );
    $stmt->execute([$companyId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Connected platforms not yet used by any active account
    $stmt = $dbh->prepare(
        'SELECT cp.id, cp.platform, cp.platform_name, cp.platform_username, cp.is_active
           FROM connected_platforms cp
          WHERE cp.company_id = ?
            AND NOT EXISTS (
                SELECT 1 FROM accounts a
                 WHERE a.connected_platform_id = cp.id
                   AND a.is_active = 1
            )
          ORDER BY cp.platform ASC, cp.platform_name ASC'
    );
    $stmt->execute([$companyId]);
    $unconnected = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $template->set('accounts',    $accounts);
    $template->set('unconnected', $unconnected);
    $template->set('csrfToken',   csrf_token());
}

/**
 * Create — GET: renders the create form.
 *
 * If no connected platforms exist, redirects to index with an info notice.
 * Accepts ?platform_id= to pre-select a specific connection.
 */
function create(): void
{
    global $dbh, $template;

    $companyId = accounts_companyId();

    $stmt = $dbh->prepare(
        'SELECT id, platform, platform_name, platform_username
           FROM connected_platforms
          WHERE company_id = ? AND is_active = 1
          ORDER BY platform ASC, platform_name ASC'
    );
    $stmt->execute([$companyId]);
    $platforms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($platforms)) {
        $_SESSION['notification'] = [
            'type'    => 'info',
            'message' => 'Connect a platform before creating an account.',
        ];
        header('Location: ' . u('accounts'));
        exit;
    }

    $preselect = (int) ($_GET['platform_id'] ?? 0);

    $template->set('platforms', $platforms);
    $template->set('preselect', $preselect);
    $template->set('csrfToken', csrf_token());
}

/**
 * Store — POST: creates an account and seeds its schedule and settings rows.
 *
 * Reads display_name from connected_platforms.platform_name so the account
 * row starts with the platform's own name. Redirects to edit/{id} so the
 * admin can configure the schedule immediately.
 */
function store(): void
{
    global $dbh;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . u('accounts', 'create'));
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('accounts', 'create'));
        exit;
    }

    $companyId           = accounts_companyId();
    $connectedPlatformId = (int) ($_POST['connected_platform_id'] ?? 0);
    $name                = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 100);
    $isPosting           = isset($_POST['is_posting']) ? 1 : 0;

    if ($name === '' || $connectedPlatformId === 0) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Account name and platform are required.'];
        header('Location: ' . u('accounts', 'create'));
        exit;
    }

    // Verify the platform belongs to this company and is active
    $stmt = $dbh->prepare(
        'SELECT id, platform_name
           FROM connected_platforms
          WHERE id = ? AND company_id = ? AND is_active = 1'
    );
    $stmt->execute([$connectedPlatformId, $companyId]);
    $platform = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($platform['id'])) {
        header('Location: ' . u('accounts', 'create'));
        exit;
    }

    $displayName = (string) ($platform['platform_name'] ?? '');
    $threshold   = defined('RECYCLE_THRESHOLD_DEFAULT')  ? (int) RECYCLE_THRESHOLD_DEFAULT  : 10;
    $lookahead   = defined('RECYCLE_LOOKAHEAD_DAYS')       ? (int) RECYCLE_LOOKAHEAD_DAYS       : 30;

    $dbh->beginTransaction();
    try {
        $dbh->prepare(
            'INSERT INTO accounts
                 (company_id, connected_platform_id, name, display_name, is_posting, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$companyId, $connectedPlatformId, $name, $displayName, $isPosting]);
        $accountId = (int) $dbh->lastInsertId();

        // Default schedule: interval, hourly, 08:00–20:00 UTC
        $dbh->prepare(
            "INSERT INTO account_schedules
                 (account_id, schedule_type, `interval`, active_hours_start, active_hours_end, timezone)
             VALUES (?, 'interval', 'every_hour', 8, 20, 'UTC')"
        )->execute([$accountId]);

        $dbh->prepare(
            'INSERT INTO account_settings
                 (account_id, recycle_threshold, recycle_lookahead_days, scheduling_enabled)
             VALUES (?, ?, ?, 0)'
        )->execute([$accountId, $threshold, $lookahead]);

        $dbh->commit();
    } catch (Throwable) {
        $dbh->rollBack();
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Could not create account. Please try again.'];
        header('Location: ' . u('accounts', 'create'));
        exit;
    }

    $_SESSION['notification'] = ['type' => 'success', 'message' => 'Account created. Configure the schedule below.'];
    header('Location: ' . u('accounts', 'edit', ['id' => $accountId]));
    exit;
}

/**
 * Edit — GET: loads all account configuration for the edit form.
 *
 * Loads: account + platform join, schedule, settings, time slots (if
 * time_specific), all available platforms for the reassignment dropdown,
 * and the full PHP timezone list.
 */
function edit(): void
{
    global $dbh, $template;

    $companyId = accounts_companyId();
    $accountId = (int) ($_GET['id'] ?? 0);

    if ($accountId === 0) {
        error404();
    }

    $stmt = $dbh->prepare(
        'SELECT a.*, cp.platform, cp.platform_name, cp.platform_username
           FROM accounts a
           JOIN connected_platforms cp ON cp.id = a.connected_platform_id
          WHERE a.id = ? AND a.company_id = ? AND a.is_active = 1'
    );
    $stmt->execute([$accountId, $companyId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($account['id'])) {
        error404();
    }

    $stmt = $dbh->prepare('SELECT * FROM account_schedules WHERE account_id = ?');
    $stmt->execute([$accountId]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $dbh->prepare('SELECT * FROM account_settings WHERE account_id = ?');
    $stmt->execute([$accountId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $dbh->prepare(
        'SELECT id, time_of_day, is_active
           FROM account_schedule_slots
          WHERE account_id = ?
          ORDER BY time_of_day ASC'
    );
    $stmt->execute([$accountId]);
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // All active platforms for the reassignment dropdown
    $stmt = $dbh->prepare(
        'SELECT id, platform, platform_name, platform_username
           FROM connected_platforms
          WHERE company_id = ? AND is_active = 1
          ORDER BY platform ASC, platform_name ASC'
    );
    $stmt->execute([$companyId]);
    $platforms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert stored JSON tags to comma-separated display string
    $tagDisplay = '';
    if (!empty($account['default_tags'])) {
        $decoded = json_decode((string) $account['default_tags'], true);
        if (is_array($decoded)) {
            $tagDisplay = implode(', ', $decoded);
        }
    }

    $stmt = $dbh->prepare(
        'SELECT COUNT(*) FROM posts WHERE account_id = ? AND is_active = 1 AND is_recyclable = 1'
    );
    $stmt->execute([$accountId]);
    $activePostCount = (int) $stmt->fetchColumn();

    $template->set('account',         $account);
    $template->set('schedule',        $schedule);
    $template->set('settings',        $settings);
    $template->set('slots',           $slots);
    $template->set('platforms',       $platforms);
    $template->set('tagDisplay',      $tagDisplay);
    $template->set('timezones',       timezone_identifiers_list());
    $template->set('activePostCount', $activePostCount);
    $template->set('csrfToken',       csrf_token());
}

/**
 * Update — POST: saves all account configuration in one transaction.
 *
 * Tag cleaning: strips leading #, trims whitespace, removes empty entries
 * and duplicates, stores as a JSON array without # prefix.
 *
 * Time slot snapping: each submitted time_of_day is snapped to the nearest
 * 15-minute boundary using floor($minutes / 15) * 15 before inserting.
 * Invalid or non-time values are silently skipped.
 *
 * Post-edit cascade: all pending rows in scheduled_posts for posts belonging
 * to this account are deleted so the queue engine repopulates with the new
 * schedule on the next cron run.
 */
function update(): void
{
    global $dbh;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . u('accounts'));
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('accounts'));
        exit;
    }

    $companyId = accounts_companyId();
    $accountId = (int) ($_POST['id'] ?? 0);

    if ($accountId === 0) {
        header('Location: ' . u('accounts'));
        exit;
    }

    // Verify account ownership
    $stmt = $dbh->prepare(
        'SELECT id FROM accounts WHERE id = ? AND company_id = ? AND is_active = 1'
    );
    $stmt->execute([$accountId, $companyId]);
    if (!$stmt->fetchColumn()) {
        error404();
    }

    // -----------------------------------------------------------------------
    // Identity
    // -----------------------------------------------------------------------

    $name                = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 100);
    $connectedPlatformId = (int) ($_POST['connected_platform_id'] ?? 0);
    $isPosting           = isset($_POST['is_posting'])           ? 1 : 0;
    $dynamicImages       = isset($_POST['dynamic_images_enabled']) ? 1 : 0;

    $rawFontColor     = trim((string) ($_POST['overlay_font_color'] ?? ''));
    $overlayFontColor = preg_match('/^#[0-9a-fA-F]{6}$/', $rawFontColor) ? $rawFontColor : '#000000';

    $rawFontSize     = (int) ($_POST['overlay_font_size'] ?? 0);
    $overlayFontSize = ($rawFontSize >= 30 && $rawFontSize <= 70) ? $rawFontSize : 48;

    if ($name === '' || $connectedPlatformId === 0) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Account name and platform are required.'];
        header('Location: ' . u('accounts', 'edit', ['id' => $accountId]));
        exit;
    }

    // Verify the platform belongs to this company
    $stmt = $dbh->prepare(
        'SELECT id FROM connected_platforms WHERE id = ? AND company_id = ?'
    );
    $stmt->execute([$connectedPlatformId, $companyId]);
    if (!$stmt->fetchColumn()) {
        header('Location: ' . u('accounts', 'edit', ['id' => $accountId]));
        exit;
    }

    // -----------------------------------------------------------------------
    // Tags — strip #, trim, deduplicate, encode as JSON array
    // -----------------------------------------------------------------------

    $rawTags = (string) ($_POST['default_tags'] ?? '');
    $tags = array_values(array_unique(
        array_filter(
            array_map(
                fn(string $t): string => ltrim(trim($t), '#'),
                explode(',', $rawTags)
            ),
            fn(string $t): bool => $t !== ''
        )
    ));
    $defaultTags = !empty($tags) ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null;

    // -----------------------------------------------------------------------
    // Base image — optional upload; existing filename preserved via hidden input
    // -----------------------------------------------------------------------

    $storage = new StorageService();

    $baseImageFilename = mb_substr(trim((string) ($_POST['base_image_filename_existing'] ?? '')), 0, 255) ?: null;

    $uploadFailed = false;

    if (isset($_FILES['base_image']) && $_FILES['base_image']['error'] === UPLOAD_ERR_OK) {
        $ext  = strtolower((string) pathinfo((string) $_FILES['base_image']['name'], PATHINFO_EXTENSION));
        $mime = getMimeType((string) $_FILES['base_image']['tmp_name']);
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true) && in_array($mime, ['image/jpeg', 'image/png'], true)) {
            $newFilename = bin2hex(random_bytes(8)) . '.' . $ext;
            if ($storage->store((string) $_FILES['base_image']['tmp_name'], 'originals/' . $newFilename)) {
                $baseImageFilename = $newFilename;
            } else {
                $uploadFailed = true;
            }
        }
    }

    if ($uploadFailed) {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Image upload failed. Check that the images/ directory is writable by the web server.',
        ];
        header('Location: ' . u('accounts', 'edit', ['id' => $accountId]));
        exit;
    }

    if ($dynamicImages === 1 && empty($baseImageFilename)) {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'A base image is required when dynamic images are enabled. Upload an image or disable dynamic images.',
        ];
        header('Location: ' . u('accounts', 'edit', ['id' => $accountId]));
        exit;
    }

    // -----------------------------------------------------------------------
    // Schedule
    // -----------------------------------------------------------------------

    $validIntervals = ['every_15min', 'every_30min', 'every_hour', 'every_2hr', 'every_4hr', 'every_8hr', 'custom'];

    $scheduleType = in_array((string) ($_POST['schedule_type'] ?? ''), ['interval', 'time_specific'], true)
        ? (string) $_POST['schedule_type'] : 'interval';

    $interval = in_array((string) ($_POST['interval'] ?? ''), $validIntervals, true)
        ? (string) $_POST['interval'] : 'every_hour';

    $customMinutes    = ($interval === 'custom')
        ? max(1, (int) ($_POST['custom_interval_minutes'] ?? 60))
        : null;

    $activeHoursStart = max(0, min(23, (int) ($_POST['active_hours_start'] ?? 8)));
    $activeHoursEnd   = max(0, min(24, (int) ($_POST['active_hours_end']   ?? 20)));

    $timezone = in_array((string) ($_POST['timezone'] ?? 'UTC'), timezone_identifiers_list(), true)
        ? (string) $_POST['timezone'] : 'UTC';

    if ($scheduleType === 'interval' && $activeHoursStart >= $activeHoursEnd) {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Active hours start must be earlier than end. Cross-midnight windows are not supported.',
        ];
        header('Location: ' . u('accounts', 'edit', ['id' => $accountId]));
        exit;
    }

    // -----------------------------------------------------------------------
    // Queue settings
    // -----------------------------------------------------------------------

    $recycleThreshold  = max(1, (int) ($_POST['recycle_threshold']     ?? 10));
    $lookaheadDays     = max(1, (int) ($_POST['recycle_lookahead_days'] ?? 30));
    $schedulingEnabled = isset($_POST['scheduling_enabled']) ? 1 : 0;

    // Enforce minimum post count only when enabling (0 → 1); already-enabled accounts are not rechecked.
    if ($schedulingEnabled === 1) {
        $stmt = $dbh->prepare('SELECT scheduling_enabled FROM account_settings WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $currentEnabled = (int) ($stmt->fetchColumn() ?: 0);

        if ($currentEnabled === 0) {
            $stmt = $dbh->prepare(
                'SELECT COUNT(*) FROM posts WHERE account_id = ? AND is_active = 1 AND is_recyclable = 1'
            );
            $stmt->execute([$accountId]);
            $activePostCount = (int) $stmt->fetchColumn();
            $minPosts = defined('SCHEDULE_MIN_POSTS') ? (int) SCHEDULE_MIN_POSTS : 25;

            if ($activePostCount < $minPosts) {
                $_SESSION['notification'] = [
                    'type'    => 'error',
                    'message' => 'Automated scheduling requires at least ' . $minPosts
                               . ' active recyclable posts. You currently have ' . $activePostCount . '.',
                ];
                header('Location: ' . u('accounts', 'edit', ['id' => $accountId]));
                exit;
            }
        }
    }

    // -----------------------------------------------------------------------
    // Generated image invalidation — runs before save
    // -----------------------------------------------------------------------

    $stmt = $dbh->prepare(
        'SELECT dynamic_images_enabled, base_image_filename FROM accounts WHERE id = ? AND company_id = ?'
    );
    $stmt->execute([$accountId, $companyId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    $imageSettingsChanged = $current
        && ((int) $current['dynamic_images_enabled'] !== $dynamicImages
            || (string) ($current['base_image_filename'] ?? '') !== (string) ($baseImageFilename ?? ''));

    if ($imageSettingsChanged) {
        $stmt = $dbh->prepare(
            "SELECT pi.id, pi.image_filename
               FROM post_images pi
               JOIN posts p ON pi.post_id = p.id
              WHERE p.account_id = ? AND pi.image_source = 'generated'"
        );
        $stmt->execute([$accountId]);
        $generatedImages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($generatedImages as $gi) {
            if (!empty($gi['image_filename'])) {
                $storage->delete((string) $gi['image_filename']);
            }
        }

        $dbh->prepare(
            "DELETE pi FROM post_images pi
               JOIN posts p ON pi.post_id = p.id
              WHERE p.account_id = ? AND pi.image_source = 'generated'"
        )->execute([$accountId]);
    }

    // -----------------------------------------------------------------------
    // Write — single transaction covers all tables
    // -----------------------------------------------------------------------

    $dbh->beginTransaction();
    try {
        $dbh->prepare(
            'UPDATE accounts
                SET name = ?, connected_platform_id = ?, default_tags = ?,
                    is_posting = ?, dynamic_images_enabled = ?, base_image_filename = ?,
                    overlay_font_color = ?, overlay_font_size = ?
              WHERE id = ? AND company_id = ?'
        )->execute([
            $name, $connectedPlatformId, $defaultTags,
            $isPosting, $dynamicImages, $baseImageFilename,
            $overlayFontColor, $overlayFontSize,
            $accountId, $companyId,
        ]);

        if ($scheduleType === 'time_specific') {
            $interval         = null;
            $customMinutes    = null;
            $activeHoursStart = null;
            $activeHoursEnd   = null;
        }

        $dbh->prepare(
            "UPDATE account_schedules
                SET schedule_type = ?, `interval` = ?, custom_interval_minutes = ?,
                    active_hours_start = ?, active_hours_end = ?, timezone = ?
              WHERE account_id = ?"
        )->execute([
            $scheduleType, $interval, $customMinutes,
            $activeHoursStart, $activeHoursEnd, $timezone,
            $accountId,
        ]);

        if ($scheduleType === 'interval') {
            // Clean up any slots left over from a previous time_specific configuration
            $dbh->prepare('DELETE FROM account_schedule_slots WHERE account_id = ?')
                ->execute([$accountId]);
        } else {
            // time_specific: full replace — delete existing then re-insert submitted slots
            $dbh->prepare('DELETE FROM account_schedule_slots WHERE account_id = ?')
                ->execute([$accountId]);

            $rawSlots = $_POST['time_slots'] ?? [];
            if (is_array($rawSlots)) {
                $stmt = $dbh->prepare(
                    'INSERT IGNORE INTO account_schedule_slots (account_id, time_of_day, is_active)
                     VALUES (?, ?, 1)'
                );
                foreach ($rawSlots as $rawTime) {
                    $rawTime = trim((string) $rawTime);
                    // Accept HH:MM or H:MM
                    if (!preg_match('/^\d{1,2}:\d{2}$/', $rawTime)) {
                        continue;
                    }
                    [$hourStr, $minStr] = explode(':', $rawTime);
                    $hour    = max(0, min(23, (int) $hourStr));
                    $minutes = (int) $minStr;
                    // Snap to nearest 15-minute boundary
                    $minutes = (int) (floor($minutes / 15) * 15);
                    $stmt->execute([$accountId, sprintf('%02d:%02d:00', $hour, $minutes)]);
                }
            }
        }

        $dbh->prepare(
            'UPDATE account_settings
                SET recycle_threshold = ?, recycle_lookahead_days = ?, scheduling_enabled = ?
              WHERE account_id = ?'
        )->execute([$recycleThreshold, $lookaheadDays, $schedulingEnabled, $accountId]);

        // Post-edit cascade: clear queue-generated pending rows so the engine repopulates with the new schedule
        $dbh->prepare(
            "DELETE sp FROM scheduled_posts sp
             INNER JOIN posts p ON sp.post_id = p.id
             WHERE p.account_id = ? AND sp.status = 'pending' AND sp.source = 'queue'"
        )->execute([$accountId]);

        $dbh->commit();
    } catch (Throwable) {
        $dbh->rollBack();
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Could not save changes. Please try again.'];
        header('Location: ' . u('accounts', 'edit', ['id' => $accountId]));
        exit;
    }

    $_SESSION['notification'] = ['type' => 'success', 'message' => 'Account settings saved.'];
    header('Location: ' . u('accounts', 'edit', ['id' => $accountId]));
    exit;
}

/**
 * Delete — POST: soft-deletes an account (is_active=0).
 *
 * Preserves all posts, scheduled_posts, post_history, and account_schedules
 * rows. The account simply disappears from all views. The queue engine will
 * stop processing it because is_active=0 excludes it from all queries.
 */
function delete(): void
{
    global $dbh;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . u('accounts'));
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('accounts'));
        exit;
    }

    $companyId = accounts_companyId();
    $accountId = (int) ($_POST['id'] ?? 0);

    $stmt = $dbh->prepare(
        'SELECT id, name FROM accounts WHERE id = ? AND company_id = ? AND is_active = 1'
    );
    $stmt->execute([$accountId, $companyId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($account['id'])) {
        error404();
    }

    $dbh->prepare('UPDATE accounts SET is_active = 0 WHERE id = ? AND company_id = ?')
        ->execute([$accountId, $companyId]);

    $_SESSION['notification'] = [
        'type'    => 'success',
        'message' => '"' . htmlspecialchars((string) $account['name'], ENT_QUOTES, 'UTF-8') . '" has been archived.',
    ];
    header('Location: ' . u('accounts'));
    exit;
}

/**
 * Log — GET: shows the 48-hour activity log for a connected platform.
 *
 * Scoped by the account's connected_platform_id, bounded to the last
 * 48 hours (matching the cron purge window), and ordered most-recent-first.
 *
 * Accepts an optional ?event_type= filter. The value is validated against
 * the full ENUM list before being used in the query; invalid values are
 * silently ignored and treated as "no filter."
 */
function accounts_log(): void
{
    global $dbh, $template;

    $companyId = accounts_companyId();
    $accountId = (int) ($_GET['id'] ?? 0);

    if ($accountId === 0) {
        error404();
    }

    // Load the account so we have connected_platform_id and the display name
    $stmt = $dbh->prepare(
        'SELECT id, name, connected_platform_id
           FROM accounts
          WHERE id = ? AND company_id = ? AND is_active = 1'
    );
    $stmt->execute([$accountId, $companyId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($account['id'])) {
        error404();
    }

    $connectedPlatformId = (int) $account['connected_platform_id'];

    // Valid event_type values — mirrors the activity_log ENUM exactly
    $validEventTypes = [
        'cron_run',
        'post_success',
        'post_failure',
        'token_refresh',
        'token_verify',
        'queue_populate',
        'connection_test',
    ];

    $activeFilter = '';
    $rawFilter    = trim((string) ($_GET['event_type'] ?? ''));
    if (in_array($rawFilter, $validEventTypes, true)) {
        $activeFilter = $rawFilter;
    }

    if ($activeFilter !== '') {
        $stmt = $dbh->prepare(
            'SELECT id, event_type, message, context, created_at
               FROM activity_log
              WHERE connected_platform_id = ?
                AND company_id = ?
                AND event_type = ?
                AND created_at >= NOW() - INTERVAL 48 HOUR
              ORDER BY created_at DESC'
        );
        $stmt->execute([$connectedPlatformId, $companyId, $activeFilter]);
    } else {
        $stmt = $dbh->prepare(
            'SELECT id, event_type, message, context, created_at
               FROM activity_log
              WHERE connected_platform_id = ?
                AND company_id = ?
                AND created_at >= NOW() - INTERVAL 48 HOUR
              ORDER BY created_at DESC'
        );
        $stmt->execute([$connectedPlatformId, $companyId]);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $template->set('account',      $account);
    $template->set('rows',         $rows);
    $template->set('activeFilter', $activeFilter);
    $template->set('eventTypes',   $validEventTypes);
}
