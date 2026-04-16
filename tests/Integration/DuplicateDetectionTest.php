<?php
declare(strict_types=1);

namespace Tests\Integration;

/**
 * Integration tests for the duplicate detection SQL query.
 *
 * The query (from controllers/content.php :: content_duplicates()) uses a
 * correlated subquery to find body_normalized values with COUNT > 1 within
 * the same account. These tests execute the exact query against real data
 * to validate scope, exclusion rules, and grouping behaviour.
 *
 * All tests access only account_id=1 (the base fixture account) unless a
 * test explicitly creates a second account to verify cross-account isolation.
 */
class DuplicateDetectionTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->resetDatabase();
    }

    // TC1 — no posts → duplicate query returns empty result set
    public function testNoDuplicatesReturnsEmptySet(): void
    {
        $rows = $this->runDuplicateQuery([1]);

        $this->assertEmpty($rows);
    }

    // TC2 — two posts with the same normalized body → both returned as duplicates
    public function testTwoPostsSameBodyBothReturned(): void
    {
        // Same body text → same body_normalized
        $this->insertPost(1, 'Hello World', bodyNormalized: 'hello world');
        $this->insertPost(1, 'Hello World', bodyNormalized: 'hello world');

        $rows = $this->runDuplicateQuery([1]);

        $this->assertCount(2, $rows);
        $this->assertSame('hello world', $rows[0]['body_normalized']);
        $this->assertSame('hello world', $rows[1]['body_normalized']);
    }

    // TC3 — three posts with the same normalized body → all three returned
    public function testThreePostsSameBodyAllReturned(): void
    {
        $this->insertPost(1, 'Hello World', bodyNormalized: 'hello world');
        $this->insertPost(1, 'Hello World', bodyNormalized: 'hello world');
        $this->insertPost(1, 'Hello World', bodyNormalized: 'hello world');

        $rows = $this->runDuplicateQuery([1]);

        $this->assertCount(3, $rows);
    }

    // TC4 — posts with distinct normalized bodies → no duplicates detected
    public function testDistinctBodiesProduceNoDuplicates(): void
    {
        $this->insertPost(1, 'Post alpha', bodyNormalized: 'post alpha');
        $this->insertPost(1, 'Post beta',  bodyNormalized: 'post beta');
        $this->insertPost(1, 'Post gamma', bodyNormalized: 'post gamma');

        $rows = $this->runDuplicateQuery([1]);

        $this->assertEmpty($rows);
    }

    // TC5 — rows with body_normalized='' are excluded from duplicate detection
    public function testEmptyNormalizedBodyExcluded(): void
    {
        // Two rows with empty body_normalized (legacy/backfill state) must NOT trigger detection
        $this->insertPost(1, 'Post A', bodyNormalized: '');
        $this->insertPost(1, 'Post B', bodyNormalized: '');

        $rows = $this->runDuplicateQuery([1]);

        $this->assertEmpty($rows, "body_normalized='' rows must not appear as duplicates");
    }

    // TC6 — is_active=0 posts are excluded from duplicate detection
    public function testInactivePostsExcluded(): void
    {
        // Two inactive posts with the same normalized body — must not surface
        $this->insertPost(1, 'Hello World', isActive: 0, bodyNormalized: 'hello world');
        $this->insertPost(1, 'Hello World', isActive: 0, bodyNormalized: 'hello world');

        $rows = $this->runDuplicateQuery([1]);

        $this->assertEmpty($rows, 'Inactive posts must not appear in duplicate results');
    }

    // TC7 — duplicate detection is scoped per account; each account's groups are independent
    public function testDuplicatesArePerAccountScoped(): void
    {
        // Create a second connected platform and account
        static::$pdo->exec(
            "INSERT INTO connected_platforms
                 (id, company_id, platform, platform_account_id, platform_name, access_token, is_active)
             VALUES (2, 1, 'facebook', 'fb_456', 'Test Facebook', 'fb_token', 1)"
        );
        static::$pdo->exec(
            "INSERT INTO accounts
                 (id, company_id, connected_platform_id, name, is_posting, is_active)
             VALUES (2, 1, 2, 'Facebook Account', 1, 1)"
        );

        // Account 1: two duplicates
        $this->insertPost(1, 'Same text', bodyNormalized: 'same text');
        $this->insertPost(1, 'Same text', bodyNormalized: 'same text');

        // Account 2: also two duplicates (same body_normalized, different account)
        $this->insertPost(2, 'Same text', bodyNormalized: 'same text');
        $this->insertPost(2, 'Same text', bodyNormalized: 'same text');

        // Query for both accounts — each should surface its own duplicate group
        $rows = $this->runDuplicateQuery([1, 2]);

        // 2 rows from account 1 + 2 rows from account 2 = 4 rows total
        $this->assertCount(4, $rows);

        $byAccount = [];
        foreach ($rows as $row) {
            $byAccount[(int) $row['account_id']][] = $row;
        }
        $this->assertCount(2, $byAccount[1], 'Account 1 must have 2 duplicate rows');
        $this->assertCount(2, $byAccount[2], 'Account 2 must have 2 duplicate rows');
    }

    // TC8 — deleting one of a pair of duplicates removes the group from the result
    public function testDeleteOneDuplicateRemovesGroup(): void
    {
        $id1 = $this->insertPost(1, 'Hello World', bodyNormalized: 'hello world');
        $id2 = $this->insertPost(1, 'Hello World', bodyNormalized: 'hello world');

        // Confirm both are duplicates before deletion
        $before = $this->runDuplicateQuery([1]);
        $this->assertCount(2, $before);

        // Delete one of the pair
        static::$pdo->prepare(
            'UPDATE posts SET is_active=0 WHERE id=?'
        )->execute([$id2]);

        // Remaining single post is no longer a duplicate (COUNT=1 per account)
        $after = $this->runDuplicateQuery([1]);
        $this->assertEmpty($after, 'After removing one of the pair, no duplicates should remain');
    }

    // -----------------------------------------------------------------------
    // Duplicate detection query (mirrors content_duplicates() in controllers/content.php)
    // -----------------------------------------------------------------------

    /**
     * Executes the exact duplicate detection query from content_duplicates(),
     * scoped to the supplied account IDs.
     *
     * @param list<int> $accountIds
     * @return list<array<string, mixed>>
     */
    private function runDuplicateQuery(array $accountIds): array
    {
        $inList = implode(',', array_fill(0, count($accountIds), '?'));
        $stmt   = static::$pdo->prepare(
            "SELECT p.id, p.body, p.attributed_to, p.is_recyclable, p.created_at,
                    p.body_normalized, p.account_id, a.name AS account_name
               FROM posts p
               JOIN accounts a ON a.id = p.account_id
              WHERE p.account_id IN ({$inList})
                AND p.is_active = 1
                AND p.body_normalized != ''
                AND p.body_normalized IN (
                    SELECT body_normalized
                      FROM posts
                     WHERE account_id = p.account_id
                       AND is_active = 1
                       AND body_normalized != ''
                     GROUP BY body_normalized
                    HAVING COUNT(*) > 1
                )
              ORDER BY p.account_id ASC, p.body_normalized ASC, p.created_at ASC"
        );
        $stmt->execute($accountIds);
        return $stmt->fetchAll();
    }
}
