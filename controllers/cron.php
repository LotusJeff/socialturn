<?php
declare(strict_types=1);

use SocialTurn\Services\TagAppenderService;
use SocialTurn\Services\QueuePopulationService;
use SocialTurn\Services\RecycleService;

// -----------------------------------------------------------------------
// Controller actions
// -----------------------------------------------------------------------

/**
 * Main cron entry point — called every 5 minutes.
 *
 * For each active, posting account:
 *   1. Fetch due scheduled_posts rows
 *   2. Claim each row atomically before attempting to post
 *   3. Call the platform service (stub in Phase 3 — real in Phase 4)
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

    $tagger  = new TagAppenderService();
    $queue   = new QueuePopulationService($dbh, $tagger);
    $recycle = new RecycleService($dbh, $queue);

    $accounts       = cron_fetchActiveAccounts($dbh);
    $postsAttempted = 0;
    $postsSucceeded = 0;
    $postsFailed    = 0;

    foreach ($accounts as $account) {
        try {
            $due = cron_fetchDuePosts($dbh, (int) $account['connected_platform_id']);

            foreach ($due as $row) {
                if (!cron_claimPost($dbh, (int) $row['id'])) {
                    // Another overlapping cron run claimed this row first — skip it.
                    continue;
                }

                $postsAttempted++;

                // IMPORTANT: $account['access_token'] is passed only to the platform stub.
                // Tokens must never appear in JSON responses, error logs, or exception messages.
                // The $account array must never be serialized or logged wholesale.
                $stub = cron_callPlatformStub(
                    $account['platform'],
                    $account['access_token'],
                    $row['final_body']
                );

                if ($stub['success']) {
                    cron_markPosted($dbh, (int) $row['id']);
                    try {
                        cron_writePostHistory($dbh, [
                            'connected_platform_id' => (int) $account['connected_platform_id'],
                            'post_id'               => (int) $row['post_id'],
                            'scheduled_post_id'     => (int) $row['id'],
                            'platform'              => $account['platform'],
                            'platform_account_id'   => $account['platform_account_id'],
                            'body_snapshot'         => $row['final_body'],
                            'image_filename'        => $row['image_filename'],
                            'platform_post_id'      => $stub['platform_post_id'],
                            'status'                => 'posted',
                            'error_message'         => null,
                        ]);
                    } catch (Throwable) {
                        // post_history write failed — best-effort; scheduled_posts already marked posted.
                    }
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
                            'image_filename'        => $row['image_filename'],
                            'platform_post_id'      => null,
                            'status'                => 'failed',
                            'error_message'         => $stub['error'],
                        ]);
                    } catch (Throwable) {
                        // post_history write failed — best-effort; scheduled_posts already marked failed.
                    }
                    $postsFailed++;
                }
            }

            // Always check queue depth after processing, even when $due was empty.
            $recycle->check((int) $account['id']);

        } catch (Throwable $e) {
            $postsFailed++;
            // Do not include $e->getMessage() in any output — it may contain token data
            // if the exception originated inside a platform service call.
            // Do not serialize $account — it contains access_token.
            // Execution continues to the next account.
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
 *     connected_platform_id: string,
 *     platform: string,
 *     access_token: string,
 *     token_secret: string|null,
 *     platform_account_id: string
 * }>
 */
function cron_fetchActiveAccounts(PDO $dbh): array
{
    $stmt = $dbh->prepare(
        'SELECT a.id, a.connected_platform_id,
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
 *     image_filename: string|null
 * }>
 */
function cron_fetchDuePosts(PDO $dbh, int $connectedPlatformId): array
{
    $stmt = $dbh->prepare(
        "SELECT sp.id, sp.post_id, sp.final_body, p.image_filename
           FROM scheduled_posts sp
           JOIN posts p ON p.id = sp.post_id
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
 * Platform service stub — replaced in Phase 4 with real service classes.
 *
 * Accepts the platform identifier, the stored OAuth token, and the pre-rendered
 * final_body string. The signature is designed so Phase 4 can replace this
 * function body without changing any calling code.
 *
 * IMPORTANT: $token must never appear in any log output, response body,
 * or exception message. Pass it only to the platform API client.
 *
 * @return array{success: bool, platform_post_id: string|null, error: string|null}
 */
function cron_callPlatformStub(string $platform, string $token, string $finalBody): array
{
    return [
        'success'          => true,
        'platform_post_id' => 'stub_' . uniqid(),
        'error'            => null,
    ];
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
