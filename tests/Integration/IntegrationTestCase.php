<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for all Tier 2 integration tests.
 *
 * Applies the full database schema once per test class via setUpBeforeClass(),
 * then truncates data tables and re-seeds a standard fixture before each test.
 *
 * Schema setup note
 * -----------------
 * Migrations 002, 003, 006, and 007 are ALTER TABLE statements that assume
 * legacy tables already exist from a pre-migration install. Running those files
 * against a blank database would fail with "Table doesn't exist." schema.sql is
 * the authoritative fresh-install equivalent of all 23 migrations applied from
 * that legacy starting point, so it is used here instead.
 *
 * Schema import uses mysqli::multi_query() which handles semicolons inside SQL
 * string literals (e.g. COMMENT 'e.g. 07:15:00; interpreted in ...') correctly,
 * unlike a naive split-on-semicolon approach.
 *
 * Test DB credentials
 * -------------------
 * Set via environment variables before running:
 *   TEST_DB_HOST, TEST_DB_NAME, TEST_DB_USER, TEST_DB_PASSWORD
 * Defaults: 127.0.0.1 / socialturn_test / root / (empty)
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static PDO $pdo;

    /**
     * All data tables, ordered so a TRUNCATE sweep respects FK children-first.
     * Schema tables (no data) are omitted.
     */
    private const DATA_TABLES = [
        'post_history',
        'scheduled_posts',
        'post_images',
        'posts',
        'account_schedule_slots',
        'account_schedules',
        'account_settings',
        'activity_log',
        'oauth_states',
        'users_accounts',
        'accounts',
        'connected_platforms',
        'users',
        'invites',
        'companies',
    ];

    // -----------------------------------------------------------------------
    // Schema lifecycle (once per class)
    // -----------------------------------------------------------------------

    public static function setUpBeforeClass(): void
    {
        $host = (string) (getenv('TEST_DB_HOST') ?: '127.0.0.1');
        $name = (string) (getenv('TEST_DB_NAME') ?: 'socialturn_test');
        $user = (string) (getenv('TEST_DB_USER') ?: 'root');
        $pass = (string) (getenv('TEST_DB_PASSWORD') ?: '');

        static::$pdo = new PDO(
            "mysql:host={$host};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        static::applySchema($host, $name, $user, $pass);
    }

    /**
     * Drops all known tables and reimports schema.sql via mysqli multi_query.
     */
    private static function applySchema(
        string $host,
        string $name,
        string $user,
        string $pass
    ): void {
        static::$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::DATA_TABLES) as $table) {
            static::$pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }
        static::$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $schemaPath = dirname(__DIR__, 2) . '/db/schema.sql';
        $schema     = (string) file_get_contents($schemaPath);

        // mysqli handles semicolons inside string literals correctly
        $mysqli = new \mysqli($host, $user, $pass, $name);
        if ($mysqli->connect_error) {
            throw new \RuntimeException(
                'mysqli connect failed: ' . $mysqli->connect_error
            );
        }
        $mysqli->multi_query($schema);
        do {
            if ($result = $mysqli->store_result()) {
                $result->free();
            }
        } while ($mysqli->more_results() && $mysqli->next_result());

        if ($mysqli->error) {
            throw new \RuntimeException(
                'Schema import error: ' . $mysqli->error
            );
        }
        $mysqli->close();
    }

    // -----------------------------------------------------------------------
    // Per-test fixture management
    // -----------------------------------------------------------------------

    /**
     * Truncates all data tables and re-seeds the standard base fixture.
     * Call this at the top of each concrete test class's setUp().
     */
    protected function resetDatabase(): void
    {
        static::$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::DATA_TABLES as $table) {
            static::$pdo->exec("TRUNCATE TABLE `{$table}`");
        }
        static::$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->seedBaseFixture();
    }

    /**
     * Inserts the minimum rows needed by every integration test:
     *
     *   companies(1)              — Test Co
     *   users(1)                  — admin, company 1
     *   connected_platforms(1)    — twitter, company 1
     *   accounts(1)               — is_posting=1, is_active=1
     *   account_settings(1)       — threshold=5, lookahead=7
     *   account_schedules(1)      — interval, every_hour, 08–20 UTC
     */
    protected function seedBaseFixture(): void
    {
        static::$pdo->exec(
            "INSERT INTO companies (id, name) VALUES (1, 'Test Co')"
        );
        static::$pdo->exec(
            "INSERT INTO users (id, company_id, email, password, type, active)
             VALUES (1, 1, 'test@example.com', 'irrelevant', 1, 1)"
        );
        static::$pdo->exec(
            "INSERT INTO connected_platforms
                 (id, company_id, platform, platform_account_id, platform_name, access_token, is_active)
             VALUES (1, 1, 'twitter', 'tw_123', 'Test Twitter', 'test_token', 1)"
        );
        static::$pdo->exec(
            "INSERT INTO accounts
                 (id, company_id, connected_platform_id, name,
                  is_posting, is_active, dynamic_images_enabled)
             VALUES (1, 1, 1, 'Test Account', 1, 1, 0)"
        );
        static::$pdo->exec(
            "INSERT INTO account_settings (account_id, recycle_threshold, recycle_lookahead_days)
             VALUES (1, 5, 7)"
        );
        static::$pdo->exec(
            "INSERT INTO account_schedules
                 (account_id, schedule_type, `interval`,
                  active_hours_start, active_hours_end, timezone)
             VALUES (1, 'interval', 'every_hour', 8, 20, 'UTC')"
        );
    }

    // -----------------------------------------------------------------------
    // Shared insert helpers
    // -----------------------------------------------------------------------

    /**
     * Inserts a post row and returns its auto-increment id.
     * body_normalized defaults to normalize_body($body) if not supplied.
     */
    protected function insertPost(
        int     $accountId,
        string  $body,
        int     $isRecyclable    = 1,
        int     $isActive        = 1,
        ?string $bodyNormalized  = null,
        ?string $attributedTo    = null
    ): int {
        $stmt = static::$pdo->prepare(
            'INSERT INTO posts
                 (account_id, body, body_normalized, attributed_to,
                  is_recyclable, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $accountId,
            $body,
            $bodyNormalized ?? normalize_body($body),
            $attributedTo,
            $isRecyclable,
            $isActive,
        ]);
        return (int) static::$pdo->lastInsertId();
    }

    /**
     * Inserts a post_images row for the given post and returns nothing.
     */
    protected function insertPostImage(
        int    $postId,
        string $imageFilename,
        int    $sortOrder   = 0,
        string $imageSource = 'uploaded'
    ): void {
        static::$pdo->prepare(
            'INSERT INTO post_images (post_id, sort_order, image_filename, image_source)
             VALUES (?, ?, ?, ?)'
        )->execute([$postId, $sortOrder, $imageFilename, $imageSource]);
    }

    /**
     * Inserts a pending scheduled_posts row and returns its id.
     */
    protected function insertPendingScheduledPost(
        int    $connectedPlatformId,
        int    $postId,
        string $scheduledTime,
        string $finalBody = 'test body'
    ): int {
        $stmt = static::$pdo->prepare(
            "INSERT INTO scheduled_posts
                 (connected_platform_id, post_id, scheduled_time, final_body, status)
             VALUES (?, ?, ?, ?, 'pending')"
        );
        $stmt->execute([$connectedPlatformId, $postId, $scheduledTime, $finalBody]);
        return (int) static::$pdo->lastInsertId();
    }
}
