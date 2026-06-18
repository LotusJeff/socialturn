<?php
declare(strict_types=1);

namespace Tests\Integration;

use SocialTurn\Services\ImageService;
use SocialTurn\Services\QueuePopulationService;

/**
 * Integration tests for QueuePopulationService::populate().
 *
 * Uses a real MySQL test database (see IntegrationTestCase for setup).
 * ImageService is mocked — no filesystem access.
 *
 * Requires TEST_DB_* environment variables. Run with:
 *   composer test:integration
 */
class QueuePopulationServiceTest extends IntegrationTestCase
{
    private ImageService $imageService;
    private QueuePopulationService $svc;

    protected function setUp(): void
    {
        $this->resetDatabase();

        // Default mock: image methods return null (text-only posts by default)
        $this->imageService = $this->createMock(ImageService::class);
        $this->imageService->method('prepareForPlatform')->willReturn(null);
        $this->imageService->method('generateFromTemplate')->willReturn(null);

        $this->svc    = new QueuePopulationService(
            static::$pdo,
            $this->imageService
        );
    }

    // -----------------------------------------------------------------------
    // TC1 — non-existent account_id → error returned, no DB writes
    // -----------------------------------------------------------------------
    public function testAccountNotFoundReturnsError(): void
    {
        $result = $this->svc->populate(9999);

        $this->assertNotNull($result['error']);
        $this->assertSame(0, $result['posts_scheduled']);
        $this->assertSame(0, $this->countScheduledPosts());
    }

    // TC2 — account is_active=0 is excluded by the WHERE clause → error
    public function testAccountInactiveReturnsError(): void
    {
        static::$pdo->exec("UPDATE accounts SET is_active=0 WHERE id=1");
        $this->insertPost(1, 'Test post');

        $result = $this->svc->populate(1);

        $this->assertNotNull($result['error']);
        $this->assertSame(0, $this->countScheduledPosts());
    }

    // TC3 — account is_posting=0 passes the WHERE but fails the is_posting check → error
    public function testAccountNotPostingReturnsError(): void
    {
        static::$pdo->exec("UPDATE accounts SET is_posting=0 WHERE id=1");
        $this->insertPost(1, 'Test post');

        $result = $this->svc->populate(1);

        $this->assertNotNull($result['error']);
        $this->assertSame(0, $this->countScheduledPosts());
    }

    // TC4 — account has no schedule row → error
    public function testNoScheduleReturnsError(): void
    {
        static::$pdo->exec("DELETE FROM account_schedules WHERE account_id=1");
        $this->insertPost(1, 'Test post');

        $result = $this->svc->populate(1);

        $this->assertNotNull($result['error']);
        $this->assertSame(0, $this->countScheduledPosts());
    }

    // TC5 — no recyclable posts → error; inactive or non-recyclable excluded
    public function testNoRecyclablePostsReturnsError(): void
    {
        // Posts exist but neither is recyclable
        $this->insertPost(1, 'One-shot post A', isRecyclable: 0);
        $this->insertPost(1, 'One-shot post B', isRecyclable: 0);

        $result = $this->svc->populate(1);

        $this->assertNotNull($result['error']);
        $this->assertSame(0, $this->countScheduledPosts());
    }

    // TC6 — happy path: valid account, schedule, and posts → rows inserted
    public function testBasicPopulateInsertsScheduledRows(): void
    {
        $this->insertPost(1, 'Post A');
        $this->insertPost(1, 'Post B');
        $this->insertPost(1, 'Post C');

        $result = $this->svc->populate(1);

        $this->assertNull($result['error']);
        $this->assertGreaterThan(0, $result['posts_scheduled']);
        $this->assertSame($result['posts_scheduled'], $this->countScheduledPosts());
    }

    // TC7 — posts_scheduled in result equals the number of rows actually written to DB
    public function testPostsScheduledCountMatchesDbCount(): void
    {
        $this->insertPost(1, 'Post A');
        $this->insertPost(1, 'Post B');

        $result = $this->svc->populate(1);

        $this->assertNull($result['error']);
        $this->assertSame($result['posts_scheduled'], $this->countScheduledPosts());
    }

    // TC8 — slots_examined is positive after a successful run
    public function testSlotsExaminedIsPositive(): void
    {
        $this->insertPost(1, 'Post A');

        $result = $this->svc->populate(1);

        $this->assertNull($result['error']);
        $this->assertGreaterThan(0, $result['slots_examined']);
    }

    // TC9 — running populate twice: second run detects all slots already filled
    public function testDuplicateSlotsSkippedOnRepopulate(): void
    {
        $this->insertPost(1, 'Post A');
        $this->insertPost(1, 'Post B');

        $first  = $this->svc->populate(1);
        $second = $this->svc->populate(1);

        $this->assertNull($second['error']);
        // All slots from the first run are now pending — second run should skip them all
        $this->assertGreaterThan(0, $second['duplicates_skipped']);
        // No new rows should be inserted on the second run
        $this->assertSame(0, $second['posts_scheduled']);
    }

    // TC10 — duplicates_skipped on second run equals posts_scheduled from first run
    public function testDuplicatesSkippedCountEqualsFirstPopulate(): void
    {
        $this->insertPost(1, 'Post A');

        $first  = $this->svc->populate(1);
        $second = $this->svc->populate(1);

        // Every slot scheduled in the first run should be seen and skipped in the second
        $this->assertSame($first['posts_scheduled'], $second['duplicates_skipped']);
    }

    // TC11 — custom interval schedule: uses custom_interval_minutes instead of enum
    public function testCustomIntervalSchedule(): void
    {
        static::$pdo->exec(
            "UPDATE account_schedules
                SET schedule_type = 'interval', `interval` = 'custom', custom_interval_minutes = 90
              WHERE account_id = 1"
        );
        $this->insertPost(1, 'Post A');

        $result = $this->svc->populate(1);

        $this->assertNull($result['error']);
        $this->assertGreaterThan(0, $result['posts_scheduled']);
    }

    // TC12 — time_specific schedule: reads account_schedule_slots → posts inserted
    public function testTimeSpecificScheduleInsertsRows(): void
    {
        static::$pdo->exec(
            "UPDATE account_schedules SET schedule_type = 'time_specific' WHERE account_id = 1"
        );
        // Insert two time slots well within the 7-day lookahead window
        static::$pdo->exec(
            "INSERT INTO account_schedule_slots (account_id, time_of_day, is_active)
             VALUES (1, '09:00:00', 1), (1, '15:00:00', 1)"
        );
        $this->insertPost(1, 'Post A');

        $result = $this->svc->populate(1);

        $this->assertNull($result['error']);
        $this->assertGreaterThan(0, $result['posts_scheduled']);
    }

    // TC13 — account default_tags are appended to final_body at population time
    public function testDefaultTagsAppendedToFinalBody(): void
    {
        static::$pdo->exec(
            "UPDATE accounts SET default_tags = '[\"testTag\"]' WHERE id = 1"
        );
        $this->insertPost(1, 'Hello world');

        $result = $this->svc->populate(1);

        $this->assertNull($result['error']);
        // Fetch the first scheduled row and check its final_body
        $row = static::$pdo->query(
            "SELECT final_body FROM scheduled_posts WHERE status='pending' LIMIT 1"
        )->fetch();
        $this->assertStringContainsString('#testTag', (string) $row['final_body']);
    }

    // TC15 — all generated slots fall within the active_hours_start/end window
    public function testActiveHoursWindowRespected(): void
    {
        // Schedule: every_hour, active 08–20 UTC
        // Every scheduled_time (stored in UTC) must have hour >= 8 and < 20
        $this->insertPost(1, 'Post A');

        $this->svc->populate(1);

        $rows = static::$pdo->query(
            "SELECT scheduled_time FROM scheduled_posts WHERE status='pending'"
        )->fetchAll();

        $this->assertNotEmpty($rows, 'Expected scheduled rows for TC15');
        foreach ($rows as $row) {
            $hour = (int) date('G', strtotime((string) $row['scheduled_time']));
            $this->assertGreaterThanOrEqual(8,  $hour, "Hour {$hour} is before active start");
            $this->assertLessThan(         20, $hour, "Hour {$hour} is at or after active end");
        }
    }

    // TC16 — post_ids already in pending are excluded from the pool for new slots
    public function testExcludedPostIdsNotUsedInNewSlots(): void
    {
        $id1 = $this->insertPost(1, 'Post A');
        $id2 = $this->insertPost(1, 'Post B');

        // Pre-queue post 1 at a past time (not in future slots but in post_id exclusion list)
        $this->insertPendingScheduledPost(1, $id1, '2000-01-01 09:00:00', 'Post A');

        $this->svc->populate(1);

        // Only new rows (created by populate) should reference post 2, not post 1
        // We identify new rows as those NOT at the pre-seeded timestamp
        $newPostIds = static::$pdo->query(
            "SELECT DISTINCT post_id FROM scheduled_posts
              WHERE status='pending' AND scheduled_time > '2000-01-01 09:00:00'"
        )->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertContains($id2, array_map('intval', $newPostIds));
        $this->assertNotContains($id1, array_map('intval', $newPostIds));
    }

    // TC17 — when all post_ids are excluded, populate falls back to the full pool
    public function testFallbackToFullPoolWhenAllExcluded(): void
    {
        $id1 = $this->insertPost(1, 'Post A');
        $id2 = $this->insertPost(1, 'Post B');
        $id3 = $this->insertPost(1, 'Post C');

        // Exclude all three post_ids by placing them in pending with a past time
        $this->insertPendingScheduledPost(1, $id1, '2000-01-01 09:00:00', 'Post A');
        $this->insertPendingScheduledPost(1, $id2, '2000-01-01 10:00:00', 'Post B');
        $this->insertPendingScheduledPost(1, $id3, '2000-01-01 11:00:00', 'Post C');

        $result = $this->svc->populate(1);

        // Fallback to full pool — should succeed and schedule rows
        $this->assertNull($result['error']);
        $this->assertGreaterThan(0, $result['posts_scheduled']);
    }

    // TC18 — post with image_filename → ImageService::prepareForPlatform() called,
    //         returned filename stored as JSON array in final_image_filenames
    public function testImageFilenameSetFromPrepareForPlatform(): void
    {
        $returnedFilename = 'processed/twitter/test_processed.jpg';

        $imageService = $this->createMock(ImageService::class);
        $imageService
            ->expects($this->atLeastOnce())
            ->method('prepareForPlatform')
            ->willReturn($returnedFilename);

        $svc = new QueuePopulationService(static::$pdo, $imageService);

        $postId = $this->insertPost(1, 'Post with image');
        $this->insertPostImage($postId, 'original.jpg');

        $svc->populate(1);

        $row = static::$pdo->query(
            "SELECT final_image_filenames FROM scheduled_posts WHERE status='pending' LIMIT 1"
        )->fetch();
        $this->assertSame([$returnedFilename], json_decode($row['final_image_filenames'], true));
    }

    // TC19 — post with two images → both processed filenames encoded as JSON array
    public function testMultipleImagesEncodedAsJsonInScheduledPost(): void
    {
        $imageService = $this->createMock(ImageService::class);
        $imageService
            ->expects($this->atLeast(2))
            ->method('prepareForPlatform')
            ->willReturnMap([
                ['original_a.jpg', 'twitter', 'processed/twitter/image_a.jpg'],
                ['original_b.jpg', 'twitter', 'processed/twitter/image_b.jpg'],
            ]);

        $svc = new QueuePopulationService(static::$pdo, $imageService);

        $postId = $this->insertPost(1, 'Post with two images');
        $this->insertPostImage($postId, 'original_a.jpg', 0);
        $this->insertPostImage($postId, 'original_b.jpg', 1);

        $svc->populate(1);

        $row = static::$pdo->query(
            "SELECT final_image_filenames FROM scheduled_posts WHERE status='pending' LIMIT 1"
        )->fetch();

        $decoded = json_decode($row['final_image_filenames'], true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        $this->assertContains('processed/twitter/image_a.jpg', $decoded);
        $this->assertContains('processed/twitter/image_b.jpg', $decoded);
    }

    // TC20 — recycle_lookahead_days bounds the number of slots generated
    public function testLookaheadDaysBoundsSlotCount(): void
    {
        $this->insertPost(1, 'Post A');

        // Narrow window: 1 day
        static::$pdo->exec(
            "UPDATE account_settings SET recycle_lookahead_days=1 WHERE account_id=1"
        );
        $short = $this->svc->populate(1);
        $shortCount = $this->countScheduledPosts();

        // Wipe and re-run with wider window: 14 days
        // Use DELETE (not TRUNCATE) — post_history FK prevents TRUNCATE when FK checks are on
        static::$pdo->exec("DELETE FROM scheduled_posts");
        static::$pdo->exec(
            "UPDATE account_settings SET recycle_lookahead_days=14 WHERE account_id=1"
        );
        $long = $this->svc->populate(1);
        $longCount = $this->countScheduledPosts();

        $this->assertNull($short['error']);
        $this->assertNull($long['error']);
        $this->assertGreaterThan($shortCount, $longCount, '14-day window should produce more slots than 1-day');
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    private function countScheduledPosts(): int
    {
        return (int) static::$pdo->query(
            "SELECT COUNT(*) FROM scheduled_posts WHERE status='pending'"
        )->fetchColumn();
    }
}
