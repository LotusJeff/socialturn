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
                            'image_filename'        => $row['final_image_filename'],
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
                            'image_filename'        => $row['final_image_filename'],
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
                                date('Y-m-d H:i:s')
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
                $periodLabel = date('M j', strtotime($periodStart)) . ' \u{2013} ' . date('M j, Y', strtotime($nowStr));
                $notifier->sendRecapEmail(
                    NOTIFY_RECAP_FREQUENCY,
                    $periodLabel,
                    $stats['succeeded'],
                    $stats['failed'],
                    $stats['failures']
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
                cp.platform, cp.access_token, cp.token_secret, cp.platform_account_id
           FROM accounts a
           JOIN connected_platforms cp ON cp.id = a.connected_platform_id
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
        "SELECT sp.id, sp.post_id, sp.final_body, sp.final_image_filename
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

    switch ($account['platform']) {
        case 'twitter':
            return $twitter->post($scheduledPost, $token, $tokenSecret, []);

        case 'facebook':
            return $facebook->post($scheduledPost, $token, $tokenSecret, [
                'page_id'               => $account['platform_account_id'],
                'connected_platform_id' => $account['connected_platform_id'],
            ]);

        case 'instagram':
            return $instagram->post($scheduledPost, $token, $tokenSecret, [
                'ig_user_id'            => $account['platform_account_id'],
                'connected_platform_id' => $account['connected_platform_id'],
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
 *     image_filename: string|null,
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
                 platform, platform_account_id, body_snapshot, image_filename,
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
        $data['image_filename'],
        $data['platform_post_id'],
        $data['status'],
        $data['error_message'],
    ]);
}

/**
 * Returns posting stats from post_history for the recap email.
 * Looks up account name via accounts.connected_platform_id to avoid
 * duplicating rows when multiple accounts share a connected_platform.
 *
 * @return array{
 *     succeeded: int,
 *     failed: int,
 *     failures: list<array{platform:string,account_name:string,body_snapshot:string,error_message:string}>
 * }
 */
function cron_fetchRecapStats(PDO $dbh, string $periodStart, string $periodEnd): array
{
    $stmt = $dbh->prepare(
        "SELECT ph.platform,
                ph.body_snapshot,
                ph.error_message,
                ph.status,
                COALESCE(
                    (SELECT a.name FROM accounts a
                      WHERE a.connected_platform_id = ph.connected_platform_id
                      LIMIT 1),
                    ph.platform_account_id
                ) AS account_name
           FROM post_history ph
          WHERE ph.posted_at >= ?
            AND ph.posted_at <= ?
          ORDER BY ph.posted_at ASC"
    );
    $stmt->execute([$periodStart, $periodEnd]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $succeeded = 0;
    $failed    = 0;
    $failures  = [];

    foreach ($rows as $row) {
        if ($row['status'] === 'posted') {
            $succeeded++;
        } else {
            $failed++;
            $failures[] = [
                'platform'      => (string) $row['platform'],
                'account_name'  => (string) $row['account_name'],
                'body_snapshot' => (string) $row['body_snapshot'],
                'error_message' => (string) ($row['error_message'] ?? ''),
            ];
        }
    }

    return compact('succeeded', 'failed', 'failures');
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
