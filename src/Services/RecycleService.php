<?php
declare(strict_types=1);

namespace SocialTurn\Services;

use PDO;
use PDOException;
use Throwable;

/**
 * RecycleService
 *
 * Checks queue depth for a connected platform account and triggers
 * QueuePopulationService::populate() when pending post count is at or
 * below the account's recycle_threshold.
 *
 * Called by cron only — never from a web request.
 * Never throws — all exceptions are caught and surfaced in the result array.
 */
class RecycleService
{
    public function __construct(
        private readonly PDO $dbh,
        private readonly QueuePopulationService $queue
    ) {}

    /**
     * Check queue depth for an account and trigger population if needed.
     *
     * Returns the QueuePopulationService result array when population was
     * triggered, or null when queue depth was above threshold (no action
     * taken) or when the account could not be resolved.
     *
     * Never throws — all exceptions are caught internally.
     *
     * @return array{
     *     account_id:         int,
     *     slots_examined:     int,
     *     posts_scheduled:    int,
     *     duplicates_skipped: int,
     *     tags_truncated:     int,
     *     error:              string|null
     * }|null
     */
    public function check(int $accountId): ?array
    {
        $populateResult = null;

        try {
            $connectedPlatformId = $this->fetchConnectedPlatformId($accountId);

            if ($connectedPlatformId === null) {
                return null;
            }

            if (!$this->fetchSchedulingEnabled($accountId)) {
                return null;
            }

            $depth     = $this->countPendingPosts($connectedPlatformId, $accountId);
            $threshold = $this->fetchThreshold($accountId);

            if ($depth <= $threshold) {
                $populateResult = $this->queue->populate($accountId);
            }

        } catch (Throwable $e) {
            error_log('[SocialTurn] RecycleService::check() failed for account #' . $accountId . ': ' . $e->getMessage());
            return null;
        }

        return $populateResult;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Resolves connected_platform_id from the accounts table.
     * Returns null if the account does not exist or has no connected platform.
     */
    private function fetchConnectedPlatformId(int $accountId): ?int
    {
        $stmt = $this->dbh->prepare(
            'SELECT connected_platform_id
               FROM accounts
              WHERE id = ?'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false || $row['connected_platform_id'] === null) {
            return null;
        }

        return (int) $row['connected_platform_id'];
    }

    /**
     * Counts pending rows in scheduled_posts for a given connected_platform_id.
     */
    private function countPendingPosts(int $connectedPlatformId, int $accountId): int
    {
        $stmt = $this->dbh->prepare(
            "SELECT COUNT(*)
               FROM scheduled_posts sp
               JOIN posts p ON sp.post_id = p.id
              WHERE sp.connected_platform_id = ?
                AND p.account_id = ?
                AND sp.status = 'pending'"
        );
        $stmt->execute([$connectedPlatformId, $accountId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns true if automated scheduling is enabled for this account.
     * Falls back to false if no row exists — safe default prevents unintended population.
     */
    private function fetchSchedulingEnabled(int $accountId): bool
    {
        $stmt = $this->dbh->prepare(
            'SELECT scheduling_enabled FROM account_settings WHERE account_id = ?'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false && (bool) $row['scheduling_enabled'];
    }

    /**
     * Returns recycle_threshold from account_settings.
     * Falls back to RECYCLE_THRESHOLD_DEFAULT constant if no row exists,
     * then to 10 if the constant is not defined.
     */
    private function fetchThreshold(int $accountId): int
    {
        $stmt = $this->dbh->prepare(
            'SELECT recycle_threshold
               FROM account_settings
              WHERE account_id = ?'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row !== false && $row['recycle_threshold'] !== null) {
            return (int) $row['recycle_threshold'];
        }

        return defined('RECYCLE_THRESHOLD_DEFAULT') ? (int) RECYCLE_THRESHOLD_DEFAULT : 10;
    }
}
