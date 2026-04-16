<?php
declare(strict_types=1);

namespace Tests\Integration;

use SocialTurn\Services\ImageService;
use SocialTurn\Services\QueuePopulationService;
use SocialTurn\Services\RecycleService;
use SocialTurn\Services\TagAppenderService;

/**
 * Integration tests for RecycleService::check().
 *
 * RecycleService is a thin coordinator: it reads queue depth, compares it to
 * the account threshold, and delegates to QueuePopulationService if needed.
 *
 * These tests use a real MySQL test database and a real QueuePopulationService
 * backed by a mock ImageService (no filesystem). Individual tests that need to
 * verify exception handling inject a mock QueuePopulationService.
 */
class RecycleServiceTest extends IntegrationTestCase
{
    private QueuePopulationService $queue;
    private RecycleService $svc;

    protected function setUp(): void
    {
        $this->resetDatabase();

        // Default: 3 recyclable posts so QueuePopulationService can succeed
        $this->insertPost(1, 'Recyclable post A');
        $this->insertPost(1, 'Recyclable post B');
        $this->insertPost(1, 'Recyclable post C');

        $imageService = $this->createMock(ImageService::class);
        $imageService->method('prepareForPlatform')->willReturn(null);
        $imageService->method('generateFromTemplate')->willReturn(null);

        $this->queue = new QueuePopulationService(
            static::$pdo,
            new TagAppenderService(),
            $imageService
        );
        $this->svc = new RecycleService(static::$pdo, $this->queue);
    }

    // TC1 — pending queue depth above threshold: populate is NOT triggered
    public function testQueueAboveThresholdReturnsNull(): void
    {
        // threshold=5; insert 6 pending rows → depth(6) > threshold(5)
        for ($i = 1; $i <= 6; $i++) {
            $this->insertPendingScheduledPost(
                1, 1,
                date('Y-m-d H:i:s', strtotime("+{$i} hours")),
                "Post body {$i}"
            );
        }

        $result = $this->svc->check(1);

        $this->assertNull($result, 'check() must return null when queue depth exceeds threshold');
    }

    // TC2 — pending queue depth equals threshold: populate IS triggered
    public function testQueueAtThresholdTriggersPopulate(): void
    {
        // threshold=5; insert exactly 5 pending rows → depth(5) <= threshold(5)
        for ($i = 1; $i <= 5; $i++) {
            $this->insertPendingScheduledPost(
                1, 1,
                date('Y-m-d H:i:s', strtotime("+{$i} hours")),
                "Post body {$i}"
            );
        }

        $result = $this->svc->check(1);

        $this->assertNotNull($result, 'check() must return a result array when depth == threshold');
        $this->assertIsArray($result);
    }

    // TC3 — pending queue depth below threshold: populate IS triggered
    public function testQueueBelowThresholdTriggersPopulate(): void
    {
        // threshold=5; queue is empty → depth(0) <= threshold(5)
        $result = $this->svc->check(1);

        $this->assertNotNull($result, 'check() must return a result array when depth < threshold');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('posts_scheduled', $result);
    }

    // TC4 — non-existent account_id: fetchConnectedPlatformId returns null → check returns null
    public function testNonExistentAccountReturnsNull(): void
    {
        $result = $this->svc->check(9999);

        $this->assertNull($result);
    }

    // TC5 — QueuePopulationService result is propagated verbatim through check()
    public function testPopulateResultPropagatedThroughCheck(): void
    {
        // Queue is empty (below threshold) so populate will be triggered
        $result = $this->svc->check(1);

        $this->assertNotNull($result);
        // Result must carry all expected keys from QueuePopulationService
        $this->assertArrayHasKey('account_id',          $result);
        $this->assertArrayHasKey('slots_examined',       $result);
        $this->assertArrayHasKey('posts_scheduled',      $result);
        $this->assertArrayHasKey('duplicates_skipped',   $result);
        $this->assertArrayHasKey('tags_truncated',       $result);
        $this->assertArrayHasKey('error',                $result);
        $this->assertSame(1, $result['account_id']);
    }

    // TC6 — exception thrown inside check(): caught internally, null returned
    public function testInternalExceptionCaughtReturnsNull(): void
    {
        $throwingQueue = $this->createMock(QueuePopulationService::class);
        $throwingQueue
            ->method('populate')
            ->willThrowException(new \RuntimeException('Simulated failure'));

        $svc = new RecycleService(static::$pdo, $throwingQueue);

        // Queue is empty → populate would be triggered → throws → caught → null
        $result = $svc->check(1);

        $this->assertNull($result);
    }
}
