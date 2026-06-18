<?php
declare(strict_types=1);

namespace Tests\Integration;

/**
 * Integration tests for the post store and update database operations.
 *
 * The store() and update() controller functions are not directly testable
 * (they depend on $_POST/$_FILES, header(), and exit). These tests execute
 * the equivalent prepared statements directly against the test database,
 * verifying the SQL patterns the controller relies on: normalize_body()
 * storage, attribution, recycle toggle, and the post-edit cascade delete.
 *
 * No PostRepository abstraction — direct SQL per the confirmed scope.
 */
class ContentStoreTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->resetDatabase();
    }

    // TC1 — INSERT stores normalize_body() result in body_normalized
    public function testInsertStoresNormalizedBody(): void
    {
        $body = 'Hello, World! https://example.com #tag';

        static::$pdo->prepare(
            'INSERT INTO posts
                 (account_id, body, body_normalized, is_recyclable, is_active, created_by)
             VALUES (?, ?, ?, 1, 1, 1)'
        )->execute([1, $body, normalize_body($body)]);

        $row = static::$pdo->query(
            "SELECT body_normalized FROM posts WHERE account_id=1 LIMIT 1"
        )->fetch();

        $this->assertSame(normalize_body($body), $row['body_normalized']);
        // Spot-check the normalization: URL stripped, punctuation removed, lowercased
        $this->assertStringNotContainsString('https://', (string) $row['body_normalized']);
        $this->assertSame('hello world tag', $row['body_normalized']);
    }

    // TC2 — INSERT stores attributed_to when supplied
    public function testInsertStoresAttributedTo(): void
    {
        static::$pdo->prepare(
            'INSERT INTO posts
                 (account_id, body, body_normalized, attributed_to, is_recyclable, is_active, created_by)
             VALUES (?, ?, ?, ?, 1, 1, 1)'
        )->execute([1, 'Quote text', normalize_body('Quote text'), 'Famous Person']);

        $row = static::$pdo->query(
            "SELECT attributed_to FROM posts WHERE account_id=1 LIMIT 1"
        )->fetch();

        $this->assertSame('Famous Person', $row['attributed_to']);
    }

    // TC3 — INSERT with is_recyclable=0 stored correctly; post starts is_active=1
    public function testInsertNonRecyclablePost(): void
    {
        static::$pdo->prepare(
            'INSERT INTO posts
                 (account_id, body, body_normalized, is_recyclable, is_active, created_by)
             VALUES (?, ?, ?, 0, 1, 1)'
        )->execute([1, 'One-shot post', normalize_body('One-shot post')]);

        $row = static::$pdo->query(
            "SELECT is_recyclable, is_active FROM posts WHERE account_id=1 LIMIT 1"
        )->fetch();

        $this->assertSame(0, (int) $row['is_recyclable']);
        $this->assertSame(1, (int) $row['is_active']);
    }

    // TC4 — update cascade: pending scheduled_posts rows are deleted before UPDATE
    public function testUpdateCascadeDeletesPendingRows(): void
    {
        // Insert a post
        $postId = $this->insertPost(1, 'Original body');

        // Queue it: 3 pending rows
        for ($i = 1; $i <= 3; $i++) {
            $this->insertPendingScheduledPost(
                1, $postId,
                date('Y-m-d H:i:s', strtotime("+{$i} hours")),
                'Original body'
            );
        }
        $this->assertSame(3, $this->countPendingForPost($postId));

        // Simulate the update cascade: DELETE pending rows, then UPDATE post
        static::$pdo->prepare(
            "DELETE FROM scheduled_posts WHERE post_id=? AND status='pending'"
        )->execute([$postId]);

        static::$pdo->prepare(
            'UPDATE posts SET body=?, body_normalized=? WHERE id=? AND is_active=1'
        )->execute(['Updated body', normalize_body('Updated body'), $postId]);

        // All pending rows gone
        $this->assertSame(0, $this->countPendingForPost($postId));

        // Post body updated
        $row = static::$pdo->query(
            "SELECT body, body_normalized FROM posts WHERE id={$postId}"
        )->fetch();
        $this->assertSame('Updated body', $row['body']);
        $this->assertSame(normalize_body('Updated body'), $row['body_normalized']);
    }

    // TC5 — update cascade preserves non-pending (posted/failed) history rows
    public function testUpdateCascadePreservesNonPendingRows(): void
    {
        $postId = $this->insertPost(1, 'Original body');

        // Insert one pending row and one posted row into scheduled_posts
        $pendingId = $this->insertPendingScheduledPost(
            1, $postId,
            date('Y-m-d H:i:s', strtotime('+1 hour')),
            'Original body'
        );
        // Mark the second row as 'posted' directly in DB
        $stmt = static::$pdo->prepare(
            "INSERT INTO scheduled_posts
                 (connected_platform_id, post_id, scheduled_time, final_body, status)
             VALUES (1, ?, DATE_SUB(NOW(), INTERVAL 1 HOUR), 'Original body', 'posted')"
        );
        $stmt->execute([$postId]);
        $postedId = (int) static::$pdo->lastInsertId();

        // Run cascade DELETE (only targets 'pending')
        static::$pdo->prepare(
            "DELETE FROM scheduled_posts WHERE post_id=? AND status='pending'"
        )->execute([$postId]);

        // Pending row is gone; posted row survives
        $remaining = static::$pdo->query(
            "SELECT id, status FROM scheduled_posts WHERE post_id={$postId}"
        )->fetchAll();

        $ids = array_column($remaining, 'id');
        $this->assertNotContains($pendingId, $ids);
        $this->assertContains($postedId, $ids);
        $this->assertSame('posted', $remaining[0]['status']);
    }

    // TC6 — UPDATE recalculates body_normalized from the new body text
    public function testUpdateBodyRecalculatesNormalized(): void
    {
        $postId = $this->insertPost(1, 'Hello World');

        $originalNormalized = static::$pdo->query(
            "SELECT body_normalized FROM posts WHERE id={$postId}"
        )->fetchColumn();

        $newBody = 'Goodbye World! https://dropped.example.com';
        static::$pdo->prepare(
            'UPDATE posts SET body=?, body_normalized=? WHERE id=? AND is_active=1'
        )->execute([$newBody, normalize_body($newBody), $postId]);

        $row = static::$pdo->query(
            "SELECT body_normalized FROM posts WHERE id={$postId}"
        )->fetch();

        $this->assertNotSame($originalNormalized, $row['body_normalized']);
        $this->assertSame('goodbye world', $row['body_normalized']);
    }

    // TC7 — flush cascade only removes source='queue' pending rows;
    //        source='share_now' and source='scheduled' rows are left intact
    public function testFlushCascadeOnlyDeletesQueueSourceRows(): void
    {
        $postId = $this->insertPost(1, 'Test post');

        // source='queue' — insertPendingScheduledPost() omits source, so the ENUM default applies
        $queueId = $this->insertPendingScheduledPost(
            1, $postId,
            date('Y-m-d H:i:s', strtotime('+1 hour')),
            'Test body'
        );

        // source='share_now'
        static::$pdo->prepare(
            "INSERT INTO scheduled_posts
                 (connected_platform_id, post_id, scheduled_time, final_body, status, source)
             VALUES (1, ?, DATE_ADD(NOW(), INTERVAL 2 HOUR), 'Test body', 'pending', 'share_now')"
        )->execute([$postId]);
        $shareNowId = (int) static::$pdo->lastInsertId();

        // source='scheduled'
        static::$pdo->prepare(
            "INSERT INTO scheduled_posts
                 (connected_platform_id, post_id, scheduled_time, final_body, status, source)
             VALUES (1, ?, DATE_ADD(NOW(), INTERVAL 3 HOUR), 'Test body', 'pending', 'scheduled')"
        )->execute([$postId]);
        $scheduledId = (int) static::$pdo->lastInsertId();

        // Run the flush cascade DELETE — mirrors queue_flush() in controllers/queue.php
        static::$pdo->prepare(
            "DELETE FROM scheduled_posts WHERE post_id=? AND status='pending' AND source='queue'"
        )->execute([$postId]);

        $remaining    = static::$pdo->query(
            "SELECT id FROM scheduled_posts WHERE post_id={$postId}"
        )->fetchAll();
        $remainingIds = array_map('intval', array_column($remaining, 'id'));

        $this->assertNotContains($queueId,    $remainingIds, "source='queue' row must be deleted");
        $this->assertContains($shareNowId,   $remainingIds, "source='share_now' row must survive");
        $this->assertContains($scheduledId,  $remainingIds, "source='scheduled' row must survive");
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    private function countPendingForPost(int $postId): int
    {
        $stmt = static::$pdo->prepare(
            "SELECT COUNT(*) FROM scheduled_posts WHERE post_id=? AND status='pending'"
        );
        $stmt->execute([$postId]);
        return (int) $stmt->fetchColumn();
    }
}
