<?php
declare(strict_types=1);

namespace SocialTurn\Services;

use PDO;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDOException;

/**
 * QueuePopulationService
 *
 * Fills scheduled_posts with future slots for a given account.
 * Called by cron when pending queue depth falls below the account's
 * recycle_threshold. Never called from a web request.
 *
 * Idempotency: existing pending slots and post_id assignments are
 * read before population so the engine never creates duplicates.
 */
class QueuePopulationService
{
    public function __construct(
        private readonly PDO                $dbh,
        private readonly TagAppenderService $tagger,
        private readonly ImageService       $imageService
    ) {}

    /**
     * Populate the queue for one account.
     *
     * @return array{
     *     account_id: int,
     *     slots_examined: int,
     *     posts_scheduled: int,
     *     duplicates_skipped: int,
     *     error: string|null
     * }
     */
    public function populate(int $accountId): array
    {
        $result = [
            'account_id'         => $accountId,
            'slots_examined'     => 0,
            'posts_scheduled'    => 0,
            'duplicates_skipped' => 0,
            'error'              => null,
        ];

        try {
            $account = $this->fetchAccount($accountId);
            if ($account === null) {
                $result['error'] = "Account {$accountId} not found, inactive, or not posting.";
                return $result;
            }

            $schedule = $this->fetchSchedule($accountId);
            if ($schedule === null) {
                $result['error'] = "No schedule defined for account {$accountId}.";
                return $result;
            }

            $settings    = $this->fetchSettings($accountId);
            if ((int) $settings['scheduling_enabled'] !== 1) {
                $result['error'] = "Scheduling disabled for account {$accountId}.";
                return $result;
            }
            $lookahead   = (int) $settings['recycle_lookahead_days'];
            $tz          = new DateTimeZone($schedule['timezone']);

            $existing  = $this->fetchExistingPending((int) $account['connected_platform_id']);
            $slots     = $this->buildSlots($schedule, $accountId, $tz, $lookahead);

            // Filter slots already covered by a pending row
            $existingTimes = array_flip($existing['times']);
            $newSlots = [];
            foreach ($slots as $utcTime) {
                $result['slots_examined']++;
                if (isset($existingTimes[$utcTime])) {
                    $result['duplicates_skipped']++;
                } else {
                    $newSlots[] = $utcTime;
                }
            }

            if (empty($newSlots)) {
                return $result;
            }

            $postPool = $this->fetchPostPool($accountId, $existing['post_ids']);
            if (empty($postPool)) {
                $result['error'] = "No active recyclable posts available for account {$accountId}.";
                return $result;
            }

            $postIds    = array_column($postPool, 'id');
            $postImages = $this->fetchPostImages(array_map('intval', $postIds));

            foreach ($postPool as &$post) {
                $post['post_images'] = $postImages[(int) $post['id']] ?? [];
            }
            unset($post);

            $connectedPlatformId = (int) $account['connected_platform_id'];
            $postCount           = count($postPool);

            // Assign posts to slots using round-robin over the shuffled pool
            $rows = [];
            foreach ($newSlots as $i => $utcTime) {
                $post     = $postPool[$i % $postCount];
                $finalBody = build_final_body($post['body'], $post['attributed_to'] ?? null, $post['post_tags'] ?? null, $account['default_tags'], $account['platform']);

                // Determine final_image_filenames at population time so cron dispatches
                // ready-to-post images without any processing overhead at send time.
                $finalImageFilenames = [];

                if (!empty($post['post_images'])) {
                    foreach ($post['post_images'] as $img) {
                        if ($img['image_source'] === 'uploaded') {
                            $processed = $this->imageService->prepareForPlatform(
                                $img['image_filename'],
                                $account['platform']
                            );
                            if ($processed !== null) {
                                $finalImageFilenames[] = $processed;
                            }
                        } elseif ($img['image_source'] === 'generated') {
                            $finalImageFilenames[] = $img['image_filename'];
                        }
                    }
                } elseif (
                    (int) $account['dynamic_images_enabled'] === 1
                    && !empty($account['base_image_filename'])
                ) {
                    $generated = $this->imageService->generateFromTemplate(
                        $account['base_image_filename'],
                        $post['body'],
                        $account['platform'],
                        $post['attributed_to'] ?? null
                    );
                    if ($generated !== null) {
                        $this->dbh->prepare(
                            "INSERT INTO post_images (post_id, sort_order, image_filename, image_source)
                             VALUES (?, 0, ?, 'generated')
                             ON DUPLICATE KEY UPDATE image_filename = VALUES(image_filename)"
                        )->execute([(int) $post['id'], $generated]);
                        $finalImageFilenames[] = $generated;
                    }
                }

                $encoded = empty($finalImageFilenames) ? null : json_encode($finalImageFilenames);
                $rows[] = [$connectedPlatformId, (int) $post['id'], $utcTime, $finalBody, $encoded];
            }

            $this->insertScheduledPosts($rows);
            $result['posts_scheduled'] = count($rows);

        } catch (InvalidArgumentException $e) {
            $result['error'] = 'Schedule configuration error: ' . $e->getMessage();
        } catch (PDOException $e) {
            $result['error'] = 'Database error during queue population: ' . $e->getMessage();
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // Data fetching
    // -----------------------------------------------------------------------

    /**
     * Returns the account row if active and is_posting=1, otherwise null.
     */
    private function fetchAccount(int $accountId): ?array
    {
        $stmt = $this->dbh->prepare(
            'SELECT a.id, a.connected_platform_id, a.is_posting,
                    a.default_tags, a.dynamic_images_enabled, a.base_image_filename,
                    cp.platform
               FROM accounts a
               JOIN connected_platforms cp ON cp.id = a.connected_platform_id
              WHERE a.id = ? AND a.is_active = 1'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false || (int) $row['is_posting'] !== 1) {
            return null;
        }

        return $row;
    }

    /**
     * Returns the account's schedule definition, or null if none exists.
     */
    private function fetchSchedule(int $accountId): ?array
    {
        $stmt = $this->dbh->prepare(
            'SELECT schedule_type,
                    `interval`,
                    custom_interval_minutes,
                    active_hours_start,
                    active_hours_end,
                    timezone
               FROM account_schedules
              WHERE account_id = ?'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Returns per-account queue settings.
     * Falls back to RECYCLE_LOOKAHEAD_DAYS when no per-account row exists.
     */
    private function fetchSettings(int $accountId): array
    {
        $stmt = $this->dbh->prepare(
            'SELECT recycle_threshold, recycle_lookahead_days, scheduling_enabled
               FROM account_settings
              WHERE account_id = ?'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            return $row;
        }

        return [
            'recycle_threshold'      => defined('RECYCLE_THRESHOLD_DEFAULT')  ? RECYCLE_THRESHOLD_DEFAULT  : 10,
            'recycle_lookahead_days' => defined('RECYCLE_LOOKAHEAD_DAYS')      ? RECYCLE_LOOKAHEAD_DAYS      : 30,
            'scheduling_enabled'     => defined('SCHEDULING_ENABLED_DEFAULT') ? SCHEDULING_ENABLED_DEFAULT : 0,
        ];
    }

    /**
     * Returns times and post_ids already in pending status for this platform connection.
     * Used to skip duplicate slots and avoid repeating recently queued posts.
     *
     * @return array{times: list<string>, post_ids: list<int>}
     */
    private function fetchExistingPending(int $connectedPlatformId): array
    {
        $stmt = $this->dbh->prepare(
            "SELECT scheduled_time, post_id
               FROM scheduled_posts
              WHERE connected_platform_id = ? AND status = 'pending'"
        );
        $stmt->execute([$connectedPlatformId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $times   = [];
        $postIds = [];
        foreach ($rows as $row) {
            $times[]   = $row['scheduled_time'];
            $postIds[] = (int) $row['post_id'];
        }

        return ['times' => $times, 'post_ids' => array_unique($postIds)];
    }

    /**
     * Returns a shuffled pool of recyclable, active posts for the account.
     * Already-pending post IDs are excluded to maximize variety.
     */
    private function fetchPostPool(int $accountId, array $excludePostIds): array
    {
        $excludePostIds = array_map('intval', (array) $excludePostIds);

        if (!empty($excludePostIds)) {
            $placeholders = implode(',', array_fill(0, count($excludePostIds), '?'));
            $sql = "SELECT id, body, attributed_to, post_tags
                      FROM posts
                     WHERE account_id = ?
                       AND is_recyclable = 1
                       AND is_active = 1
                       AND id NOT IN ({$placeholders})";
            $params = array_merge([$accountId], $excludePostIds);
        } else {
            $sql    = 'SELECT id, body, attributed_to, post_tags FROM posts WHERE account_id = ? AND is_recyclable = 1 AND is_active = 1';
            $params = [$accountId];
        }

        $stmt = $this->dbh->prepare($sql);
        $stmt->execute($params);
        $pool = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // If exclusions left the pool empty, fall back to all recyclable posts
        if (empty($pool) && !empty($excludePostIds)) {
            $stmt = $this->dbh->prepare(
                'SELECT id, body, attributed_to, post_tags FROM posts WHERE account_id = ? AND is_recyclable = 1 AND is_active = 1'
            );
            $stmt->execute([$accountId]);
            $pool = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        shuffle($pool);
        return $pool;
    }

    /**
     * Fetches all images for each post in one query, ordered by sort_order.
     * Returns an array keyed by post_id; each value is an ordered list of images.
     *
     * @param  list<int> $postIds
     * @return array<int, list<array{image_filename: string, image_source: string}>>
     */
    private function fetchPostImages(array $postIds): array
    {
        if (empty($postIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $stmt = $this->dbh->prepare(
            "SELECT post_id, image_filename, image_source
               FROM post_images
              WHERE post_id IN ({$placeholders})
              ORDER BY post_id ASC, sort_order ASC"
        );
        $stmt->execute($postIds);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['post_id']][] = [
                'image_filename' => $row['image_filename'],
                'image_source'   => $row['image_source'],
            ];
        }

        return $map;
    }

    // -----------------------------------------------------------------------
    // Slot building
    // -----------------------------------------------------------------------

    /**
     * Dispatches to the appropriate slot builder based on schedule_type.
     *
     * @return list<string> UTC datetime strings ('Y-m-d H:i:s')
     */
    private function buildSlots(
        array $schedule,
        int $accountId,
        DateTimeZone $tz,
        int $lookaheadDays
    ): array {
        return match ($schedule['schedule_type']) {
            'interval'      => $this->buildIntervalSlots($schedule, $tz, $lookaheadDays),
            'time_specific' => $this->buildTimeSpecificSlots($accountId, $tz, $lookaheadDays),
            default         => throw new InvalidArgumentException(
                "Unknown schedule_type: {$schedule['schedule_type']}"
            ),
        };
    }

    /**
     * Builds slots from a posting interval within an active-hours window.
     * All times are snapped to the nearest 15-minute floor boundary to
     * prevent schedule drift, then converted to UTC for storage.
     *
     * @return list<string> UTC datetime strings ('Y-m-d H:i:s')
     */
    private function buildIntervalSlots(
        array $schedule,
        DateTimeZone $tz,
        int $lookaheadDays
    ): array {
        $intervalMinutes = $this->intervalToMinutes(
            (string) $schedule['interval'],
            isset($schedule['custom_interval_minutes'])
                ? (int) $schedule['custom_interval_minutes']
                : null
        );

        $activeStart = (int) $schedule['active_hours_start'];
        $activeEnd   = (int) $schedule['active_hours_end'];
        $utcZone     = new DateTimeZone('UTC');

        $now    = new DateTimeImmutable('now', $tz);
        $cutoff = $now->modify("+{$lookaheadDays} days");

        // Anchor to active_hours_start:00:00 today in the account
        // timezone, then walk forward by $intervalMinutes until the
        // cursor is in the future. This preserves the configured
        // interval rhythm from the start hour without jumping to
        // tomorrow and without scheduling anything in the past.
        $cursor = new DateTimeImmutable(
            $now->format('Y-m-d') . ' ' . sprintf('%02d:00:00', $activeStart),
            $tz
        );
        while ($cursor <= $now) {
            $cursor = $cursor->modify("+{$intervalMinutes} minutes");
        }

        $slots = [];
        $seen  = [];

        while ($cursor <= $cutoff) {
            $hour = (int) $cursor->format('G');

            if ($hour >= $activeStart && $hour < $activeEnd) {
                // snapToQuarterHour() is only called here (buildIntervalSlots).
                // buildTimeSpecificSlots must NOT call it — operator-defined times
                // are stored verbatim without snapping.
                $snapped = $this->snapToQuarterHour($cursor);
                $key     = $snapped->format('Y-m-d H:i');

                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $slots[]    = $snapped->setTimezone($utcZone)->format('Y-m-d H:i:s');
                }
            }

            $cursor = $cursor->modify("+{$intervalMinutes} minutes");
        }

        return $slots;
    }

    /**
     * Builds slots from operator-defined exact times in account_schedule_slots.
     *
     * snapToQuarterHour() is intentionally NOT called in this method.
     * Time-specific slots are exact times defined by the operator and must be
     * stored verbatim. Snapping applies only to calculated interval slots.
     *
     * @return list<string> UTC datetime strings ('Y-m-d H:i:s')
     */
    private function buildTimeSpecificSlots(
        int $accountId,
        DateTimeZone $tz,
        int $lookaheadDays
    ): array {
        $stmt = $this->dbh->prepare(
            'SELECT time_of_day
               FROM account_schedule_slots
              WHERE account_id = ? AND is_active = 1
              ORDER BY time_of_day'
        );
        $stmt->execute([$accountId]);
        $slotRows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($slotRows)) {
            return [];
        }

        $utcZone = new DateTimeZone('UTC');
        $now     = new DateTimeImmutable('now', $tz);
        $cutoff  = $now->modify("+{$lookaheadDays} days");

        $slots = [];
        $seen  = [];

        // Walk day by day, emitting one slot per defined time per day
        $dayCursor = new DateTimeImmutable($now->format('Y-m-d'), $tz);
        while ($dayCursor <= $cutoff) {
            $dateStr = $dayCursor->format('Y-m-d');

            foreach ($slotRows as $timeOfDay) {
                // Combine the date with the operator-defined time — no snapping
                $localDt = new DateTimeImmutable("{$dateStr} {$timeOfDay}", $tz);

                if ($localDt <= $now) {
                    continue;
                }
                if ($localDt > $cutoff) {
                    break;
                }

                $key = $localDt->format('Y-m-d H:i');
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $slots[]    = $localDt->setTimezone($utcZone)->format('Y-m-d H:i:s');
                }
            }

            $dayCursor = $dayCursor->modify('+1 day');
        }

        return $slots;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Snap a datetime to the 15-minute floor boundary (:00, :15, :30, :45).
     * Seconds are zeroed.
     *
     * Only called from buildIntervalSlots — never from buildTimeSpecificSlots.
     */
    private function snapToQuarterHour(DateTimeImmutable $dt): DateTimeImmutable
    {
        $minutes        = (int) $dt->format('i');
        $snappedMinutes = (int) floor($minutes / 15) * 15;

        return $dt->setTime((int) $dt->format('G'), $snappedMinutes, 0);
    }

    /**
     * Converts a schedule interval enum value to minutes.
     *
     * @throws InvalidArgumentException if interval is unknown or custom_interval_minutes is invalid
     */
    private function intervalToMinutes(string $interval, ?int $customMinutes): int
    {
        $map = [
            'every_15min' => 15,
            'every_30min' => 30,
            'every_hour'  => 60,
            'every_2hr'   => 120,
            'every_4hr'   => 240,
            'every_8hr'   => 480,
        ];

        if (isset($map[$interval])) {
            return $map[$interval];
        }

        if ($interval === 'custom') {
            if ($customMinutes === null || $customMinutes <= 0) {
                throw new InvalidArgumentException(
                    "custom_interval_minutes must be a positive integer when interval=custom."
                );
            }
            return $customMinutes;
        }

        throw new InvalidArgumentException("Unknown interval value: '{$interval}'.");
    }

    // -----------------------------------------------------------------------
    // Write
    // -----------------------------------------------------------------------

    /**
     * Bulk-inserts scheduled post rows inside a transaction.
     *
     * @param list<array{0: int, 1: int, 2: string, 3: string, 4: string|null}> $rows
     *        [connected_platform_id, post_id, scheduled_time, final_body, final_image_filenames]
     */
    private function insertScheduledPosts(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $placeholders = implode(
            ', ',
            array_fill(0, count($rows), "(?, ?, ?, ?, ?, 'pending', 'queue')")
        );

        $sql  = "INSERT INTO scheduled_posts (connected_platform_id, post_id, scheduled_time, final_body, final_image_filenames, status, source) VALUES {$placeholders}";
        $flat = [];
        foreach ($rows as [$connectedPlatformId, $postId, $scheduledTime, $finalBody, $finalImageFilenames]) {
            $flat[] = $connectedPlatformId;
            $flat[] = $postId;
            $flat[] = $scheduledTime;
            $flat[] = $finalBody;
            $flat[] = $finalImageFilenames;
        }

        $this->dbh->beginTransaction();
        try {
            $stmt = $this->dbh->prepare($sql);
            $stmt->execute($flat);
            $this->dbh->commit();
        } catch (PDOException $e) {
            $this->dbh->rollBack();
            throw $e;
        }
    }
}
