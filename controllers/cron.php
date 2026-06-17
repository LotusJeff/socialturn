<?php
declare(strict_types=1);

use SocialTurn\Services\StorageService;
use SocialTurn\Services\ImageService;
use SocialTurn\Services\TwitterService;
use SocialTurn\Services\FacebookService;
use SocialTurn\Services\InstagramService;
use SocialTurn\Services\TagAppenderService;
use SocialTurn\Services\QueuePopulationService;
use SocialTurn\Services\RecycleService;
use SocialTurn\Services\NotificationService;

// -----------------------------------------------------------------------
// Controller actions
// -----------------------------------------------------------------------

/**
 * Main cron entry point — called every 5 minutes.
 *
 * For each active, posting account:
 *   1. Fetch due scheduled_posts rows
 *   2. Claim each row atomically before attempting to post
 *   3. Dispatch to platform service
 *   4. Record outcome in scheduled_posts and post_history
 *   5. Run RecycleService to refill the queue if depth is low
 *
 * Never crashes — all per-account exceptions are caught and execution
 * continues to the next account.
 *
 * Returns JSON: accounts_processed, posts_attempted, posts_succeeded, posts_failed.
 */
function post(): void
{
    global $dbh;

    $tagger    = new TagAppenderService();
    $storage   = new StorageService();
    $images    = new ImageService($storage);
    $queue     = new QueuePopulationService($dbh, $tagger, $images);
    $recycle   = new RecycleService($dbh, $queue);
    $notifier  = new NotificationService();
    $twitter   = new TwitterService($storage);
    $facebook  = new FacebookService($dbh, $storage);
    $instagram = new InstagramService($dbh, $storage);

    // Reset stale locks older than 10 minutes so those rows can be retried.
    $dbh->exec("UPDATE scheduled_posts SET locked_at = NULL WHERE locked_at < NOW() - INTERVAL 10 MINUTE");

    // Purge activity log entries older than 48 hours — rolling window only.
    $dbh->exec("DELETE FROM activity_log WHERE created_at < NOW() - INTERVAL 48 HOUR");

    // Purge abandoned OAuth handshake state older than 15 minutes.
    $dbh->exec("DELETE FROM oauth_states WHERE created_at < NOW() - INTERVAL 15 MINUTE");

    $accounts       = cron_fetchActiveAccounts($dbh);
    $postsAttempted = 0;
    $postsSucceeded = 0;
    $postsFailed    = 0;

    $companyId = cron_fetchCompanyId($dbh);
    if ($companyId > 0) {
        cron_logActivity($dbh, $companyId, 'cron_run', 'Cron run started.', null, null, [
            'accounts_found' => count($accounts),
        ]);
    }

    foreach ($accounts as $account) {
        try {
            $due = cron_fetchDuePosts($dbh, (int) $account['connected_platform_id']);

            foreach ($due as $row) {
                if (!cron_claimPost($dbh, (int) $row['id'])) {
                    // Another overlapping cron run claimed this row first — skip it.
                    continue;
                }

                $postsAttempted++;

                // IMPORTANT: $account['access_token'] is passed only to the platform service.
                // Tokens must never appear in JSON responses, error logs, or exception messages.
                // The $account array must never be serialized or logged wholesale.
                $result = cron_dispatchToPlatform($account, $row, $twitter, $facebook, $instagram);

                if ($result['success']) {
                    cron_markPosted($dbh, (int) $row['id']);
                    try {
                        cron_writePostHistory($dbh, [
                            'connected_platform_id' => (int) $account['connected_platform_id'],
                            'post_id'               => (int) $row['post_id'],
                            'scheduled_post_id'     => (int) $row['id'],
                            'platform'              => $account['platform'],
                            'platform_account_id'   => $account['platform_account_id'],
                            'body_snapshot'         => $row['final_body'],
                            'image_filenames'       => $row['final_image_filenames'],
                            'platform_post_id'      => $result['platform_post_id'],
                            'status'                => 'posted',
                            'error_message'         => null,
                        ]);
                    } catch (Throwable) {
                        // post_history write failed — best-effort; scheduled_posts already marked posted.
                    }
                    cron_logActivity($dbh, (int) $account['company_id'], 'post_success', 'Post sent successfully.',
                        (int) $account['id'], (int) $account['connected_platform_id'], [
                            'scheduled_post_id' => (int) $row['id'],
                            'platform_post_id'  => $result['platform_post_id'],
                        ]);
                    $postsSucceeded++;
                } else {
                    cron_markFailed($dbh, (int) $row['id']);
                    try {
                        cron_writePostHistory($dbh, [
                            'connected_platform_id' => (int) $account['connected_platform_id'],
                            'post_id'               => (int) $row['post_id'],
                            'scheduled_post_id'     => (int) $row['id'],
                            'platform'              => $account['platform'],
                            'platform_account_id'   => $account['platform_account_id'],
                            'body_snapshot'         => $row['final_body'],
                            'image_filenames'       => $row['final_image_filenames'],
                            'platform_post_id'      => null,
                            'status'                => 'failed',
                            'error_message'         => $result['error'],
                        ]);
                    } catch (Throwable) {
                        // post_history write failed — best-effort; scheduled_posts already marked failed.
                    }
                    cron_logActivity($dbh, (int) $account['company_id'], 'post_failure',
                        'Post failed: ' . ($result['error'] ?? 'unknown error'),
                        (int) $account['id'], (int) $account['connected_platform_id'], [
                            'scheduled_post_id' => (int) $row['id'],
                        ]);
                    $postsFailed++;

                    try {
                        if (defined('NOTIFY_POST_FAILURE') && NOTIFY_POST_FAILURE === '1') {
                            $notifier->sendFailureAlert(
                                (string) $account['name'],
                                (string) $account['platform'],
                                $result['error'] ?? 'Unknown error',
                                (string) $row['final_body'],
                                date('Y-m-d H:i:s'),
                                (string) ($account['timezone'] ?? 'UTC')
                            );
                        }
                    } catch (Throwable $e) {
                        error_log('[SocialTurn] Notification error: ' . $e->getMessage());
                    }
                }
            }

            // Always check queue depth after processing, even when $due was empty.
            // Returns populate_result array if population was triggered, null otherwise.
            $recycleResult = $recycle->check((int) $account['id']);
            if ($recycleResult !== null) {
                cron_logActivity($dbh, (int) $account['company_id'], 'queue_populate',
                    'Queue population triggered.',
                    (int) $account['id'], (int) $account['connected_platform_id'],
                    $recycleResult);
            }

        } catch (Throwable $e) {
            $postsFailed++;
            // Do not include $e->getMessage() in any output — it may contain token data
            // if the exception originated inside a platform service call.
            // Do not serialize $account — it contains access_token.
            // Execution continues to the next account.
        }
    }

    // Recap email — fires at most once per configured period.
    // All datetime comparisons use UTC (date_default_timezone_set('UTC') in cron.php).
    if (defined('NOTIFY_RECAP_FREQUENCY') && NOTIFY_RECAP_FREQUENCY !== 'never') {
        $lastSent  = defined('NOTIFY_RECAP_LAST_SENT') ? (string) NOTIFY_RECAP_LAST_SENT : '';
        $recapDue  = false;

        if (NOTIFY_RECAP_FREQUENCY === 'daily') {
            // Due when last_sent date (UTC) is before today's date (UTC).
            $recapDue = ($lastSent === '' || date('Y-m-d', strtotime($lastSent)) < date('Y-m-d'));
        } elseif (NOTIFY_RECAP_FREQUENCY === 'weekly') {
            // Due when last_sent timestamp (UTC) is more than 7 days ago (UTC).
            $recapDue = ($lastSent === '' || strtotime($lastSent) < strtotime('-7 days'));
        }

        if ($recapDue) {
            try {
                $periodStart = ($lastSent !== '') ? $lastSent : date('Y-m-d H:i:s', strtotime('-7 days'));
                $nowStr      = date('Y-m-d H:i:s');
                $stats       = cron_fetchRecapStats($dbh, $periodStart, $nowStr);
                $periodLabel = date('M j', strtotime($periodStart)) . ' – ' . date('M j, Y', strtotime($nowStr));
                $notifier->sendRecapEmail(
                    NOTIFY_RECAP_FREQUENCY,
                    $periodLabel,
                    $stats['total_posted'],
                    $stats['total_failed'],
                    $stats['accounts']
                );
                cron_updateAdminSetting($dbh, 'notify_recap_last_sent', $nowStr);
            } catch (Throwable) {
                // Best-effort — never disrupt cron output.
            }
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success'            => true,
        'accounts_processed' => count($accounts),
        'posts_attempted'    => $postsAttempted,
        'posts_succeeded'    => $postsSucceeded,
        'posts_failed'       => $postsFailed,
    ]);
    exit;
}

// -----------------------------------------------------------------------
// Private helpers (prefixed cron_ to avoid global function collisions)
// -----------------------------------------------------------------------

/**
 * Returns all active, posting accounts joined to their connected platform.
 * Only includes connections where cp.is_active = 1 — expired or disconnected
 * platform connections are skipped entirely.
 *
 * IMPORTANT: Returned rows contain access_token values.
 * Never serialize, log, or include these rows in any response output.
 * Pass access_token only to platform service calls — never anywhere else.
 *
 * @return list<array{
 *     id: string,
 *     company_id: string,
 *     connected_platform_id: string,
 *     name: string,
 *     platform: string,
 *     access_token: string,
 *     token_secret: string|null,
 *     platform_account_id: string
 * }>
 */
function cron_fetchActiveAccounts(PDO $dbh): array
{
    $stmt = $dbh->prepare(
        'SELECT a.id, a.company_id, a.connected_platform_id, a.name,
                cp.platform, cp.access_token, cp.token_secret, cp.platform_account_id,
                COALESCE(s.timezone, \'UTC\') AS timezone
           FROM accounts a
           JOIN connected_platforms cp ON cp.id = a.connected_platform_id
           LEFT JOIN account_schedules s ON s.account_id = a.id
          WHERE a.is_active = 1
            AND a.is_posting = 1
            AND cp.is_active = 1'
    );
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Returns scheduled_posts rows due for sending on a given platform connection.
 * Joins posts to carry image_filename for post_history.
 * Only returns rows with locked_at IS NULL — already-claimed rows are skipped.
 *
 * @return list<array{
 *     id: string,
 *     post_id: string,
 *     final_body: string,
 *     final_image_filename: string|null
 * }>
 */
function cron_fetchDuePosts(PDO $dbh, int $connectedPlatformId): array
{
    $stmt = $dbh->prepare(
        "SELECT sp.id, sp.post_id, sp.final_body, sp.final_image_filenames
           FROM scheduled_posts sp
          WHERE sp.connected_platform_id = ?
            AND sp.status = 'pending'
            AND sp.scheduled_time <= NOW()
            AND sp.locked_at IS NULL"
    );
    $stmt->execute([$connectedPlatformId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Atomically claims a scheduled_post row by setting locked_at = NOW().
 * The WHERE clause guards against double-claiming when two cron runs overlap.
 * Returns true only if exactly one row was updated.
 */
function cron_claimPost(PDO $dbh, int $scheduledPostId): bool
{
    $stmt = $dbh->prepare(
        "UPDATE scheduled_posts
            SET locked_at = NOW()
          WHERE id = ?
            AND locked_at IS NULL
            AND status = 'pending'"
    );
    $stmt->execute([$scheduledPostId]);

    return $stmt->rowCount() === 1;
}

/**
 * Dispatch a scheduled post to the correct platform service.
 *
 * Reads $account['platform'] to select the service, builds the platform-specific
 * $context array, and calls the uniform post() interface on the selected service.
 * Returns the service result array unchanged.
 *
 * Context keys per platform:
 *   twitter   — empty array (token + tokenSecret carry everything needed)
 *   facebook  — page_id, connected_platform_id
 *   instagram — ig_user_id, connected_platform_id
 *
 * IMPORTANT: $account['access_token'] and $account['token_secret'] are passed
 * only to the service post() call — never logged, never serialized, never returned.
 *
 * @return array{success: bool, platform_post_id: string|null, error: string|null}
 */
function cron_dispatchToPlatform(
    array            $account,
    array            $scheduledPost,
    TwitterService   $twitter,
    FacebookService  $facebook,
    InstagramService $instagram
): array {
    $token       = $account['access_token'];
    $tokenSecret = $account['token_secret'] ?? null;

    $images = [];
    if (!empty($scheduledPost['final_image_filenames'])) {
        $decoded = json_decode($scheduledPost['final_image_filenames'], true);
        if (is_array($decoded)) {
            $images = $decoded;
        }
    }

    switch ($account['platform']) {
        case 'twitter':
            return $twitter->post($scheduledPost, $token, $tokenSecret, [
                'images' => $images,
            ]);

        case 'facebook':
            return $facebook->post($scheduledPost, $token, $tokenSecret, [
                'page_id'               => $account['platform_account_id'],
                'connected_platform_id' => $account['connected_platform_id'],
                'images'                => $images,
            ]);

        case 'instagram':
            return $instagram->post($scheduledPost, $token, $tokenSecret, [
                'ig_user_id'            => $account['platform_account_id'],
                'connected_platform_id' => $account['connected_platform_id'],
                'images'                => $images,
            ]);

        default:
            return [
                'success'          => false,
                'platform_post_id' => null,
                'error'            => 'Unrecognized platform: ' . $account['platform'],
            ];
    }
}

/**
 * Marks a scheduled_post as successfully sent and clears locked_at.
 */
function cron_markPosted(PDO $dbh, int $scheduledPostId): void
{
    $stmt = $dbh->prepare(
        "UPDATE scheduled_posts
            SET status = 'posted', locked_at = NULL
          WHERE id = ?"
    );
    $stmt->execute([$scheduledPostId]);
}

/**
 * Marks a scheduled_post as failed and clears locked_at so the row
 * does not remain permanently locked after a posting error.
 */
function cron_markFailed(PDO $dbh, int $scheduledPostId): void
{
    $stmt = $dbh->prepare(
        "UPDATE scheduled_posts
            SET status = 'failed', locked_at = NULL
          WHERE id = ?"
    );
    $stmt->execute([$scheduledPostId]);
}

/**
 * Returns the company ID for this single-tenant installation.
 * Reads the first row from companies. Returns 0 if the table is empty.
 */
function cron_fetchCompanyId(PDO $dbh): int
{
    $stmt = $dbh->prepare('SELECT id FROM companies LIMIT 1');
    $stmt->execute();
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Writes one row to activity_log.
 *
 * Never throws — all exceptions are silently caught so that a logging
 * failure never disrupts cron posting.
 *
 * IMPORTANT: Never pass token data in $message or $context. Tokens must
 * never appear in logs, responses, or views — see Security Rules.
 */
function cron_logActivity(
    PDO     $dbh,
    int     $companyId,
    string  $eventType,
    string  $message,
    ?int    $accountId          = null,
    ?int    $connectedPlatformId = null,
    ?array  $context            = null
): void {
    try {
        $stmt = $dbh->prepare(
            'INSERT INTO activity_log
                    (company_id, account_id, connected_platform_id, event_type, message, context)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $companyId,
            $accountId,
            $connectedPlatformId,
            $eventType,
            $message,
            $context !== null ? json_encode($context) : null,
        ]);
    } catch (Throwable) {
        // Best-effort — never let activity logging disrupt cron.
    }
}

/**
 * Inserts one row into post_history.
 *
 * body_snapshot is always populated from final_body — the pre-rendered string
 * stored at queue population time. Never use posts.body here; the source post
 * may have been edited since the queue row was created.
 *
 * This function is best-effort: the caller wraps it in its own try/catch so
 * a history write failure does not mask the scheduled_posts status already set.
 *
 * @param array{
 *     connected_platform_id: int,
 *     post_id: int,
 *     scheduled_post_id: int,
 *     platform: string,
 *     platform_account_id: string,
 *     body_snapshot: string,
 *     image_filenames: string|null,
 *     platform_post_id: string|null,
 *     status: string,
 *     error_message: string|null
 * } $data
 */
function cron_writePostHistory(PDO $dbh, array $data): void
{
    $stmt = $dbh->prepare(
        'INSERT INTO post_history
                (connected_platform_id, post_id, scheduled_post_id,
                 platform, platform_account_id, body_snapshot, image_filenames,
                 platform_post_id, status, error_message)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $data['connected_platform_id'],
        $data['post_id'],
        $data['scheduled_post_id'],
        $data['platform'],
        $data['platform_account_id'],
        $data['body_snapshot'],
        $data['image_filenames'],
        $data['platform_post_id'],
        $data['status'],
        $data['error_message'],
    ]);
}

/**
 * Returns per-account posting stats for the recap email.
 * Drives from accounts (same pattern as queue/index) so each account
 * row carries current queue state plus period-scoped history counts.
 * A second query per failing account fetches the failure detail rows.
 *
 * @return array{
 *     accounts: list<array{
 *         account_id: int,
 *         account_name: string,
 *         platform: string,
 *         recycled_count: int,
 *         pending_count: int,
 *         period_posted: int,
 *         period_failed: int,
 *         failures: list<array{body_snapshot:string,error_message:string}>
 *     }>,
 *     total_posted: int,
 *     total_failed: int
 * }
 */
function cron_fetchRecapStats(PDO $dbh, string $periodStart, string $periodEnd): array
{
    $stmt = $dbh->prepare(
        "SELECT a.id   AS account_id,
                a.name AS account_name,
                cp.platform,
                (SELECT COUNT(*)
                   FROM posts p
                  WHERE p.account_id = a.id
                    AND p.is_active = 1
                    AND p.is_recyclable = 1)                AS recycled_count,
                (SELECT COUNT(*)
                   FROM scheduled_posts sp
                   JOIN posts p ON p.id = sp.post_id
                  WHERE p.account_id = a.id
                    AND sp.status = 'pending')              AS pending_count,
                (SELECT COUNT(*)
                   FROM post_history ph
                   JOIN posts p ON p.id = ph.post_id
                  WHERE p.account_id = a.id
                    AND ph.status = 'posted'
                    AND ph.posted_at BETWEEN ? AND ?)       AS period_posted,
                (SELECT COUNT(*)
                   FROM post_history ph
                   JOIN posts p ON p.id = ph.post_id
                  WHERE p.account_id = a.id
                    AND ph.status = 'failed'
                    AND ph.posted_at BETWEEN ? AND ?)       AS period_failed
           FROM accounts a
           JOIN connected_platforms cp ON cp.id = a.connected_platform_id
          WHERE a.is_active = 1
          ORDER BY a.name ASC"
    );
    $stmt->execute([$periodStart, $periodEnd, $periodStart, $periodEnd]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $accounts    = [];
    $totalPosted = 0;
    $totalFailed = 0;

    foreach ($rows as $row) {
        $periodPosted = (int) $row['period_posted'];
        $periodFailed = (int) $row['period_failed'];
        $totalPosted += $periodPosted;
        $totalFailed += $periodFailed;

        $failures = [];
        if ($periodFailed > 0) {
            $fStmt = $dbh->prepare(
                "SELECT ph.body_snapshot, ph.error_message
                   FROM post_history ph
                   JOIN posts p ON p.id = ph.post_id
                  WHERE p.account_id = ?
                    AND ph.status = 'failed'
                    AND ph.posted_at BETWEEN ? AND ?
                  ORDER BY ph.posted_at ASC"
            );
            $fStmt->execute([(int) $row['account_id'], $periodStart, $periodEnd]);
            foreach ($fStmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $failures[] = [
                    'body_snapshot' => (string) $f['body_snapshot'],
                    'error_message' => (string) ($f['error_message'] ?? ''),
                ];
            }
        }

        $accounts[] = [
            'account_id'    => (int) $row['account_id'],
            'account_name'  => (string) $row['account_name'],
            'platform'      => (string) $row['platform'],
            'recycled_count' => (int) $row['recycled_count'],
            'pending_count'  => (int) $row['pending_count'],
            'period_posted'  => $periodPosted,
            'period_failed'  => $periodFailed,
            'failures'       => $failures,
        ];
    }

    return [
        'accounts'     => $accounts,
        'total_posted' => $totalPosted,
        'total_failed' => $totalFailed,
    ];
}

/**
 * Updates a single admin_settings key. Used by cron to persist
 * notify_recap_last_sent without loading controllers/settings.php.
 */
function cron_updateAdminSetting(PDO $dbh, string $key, string $val): void
{
    $stmt = $dbh->prepare(
        'INSERT INTO admin_settings (setting_key, setting_val) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)'
    );
    $stmt->execute([$key, $val]);
}
