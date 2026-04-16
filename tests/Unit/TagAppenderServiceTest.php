<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocialTurn\Services\TagAppenderService;

/**
 * Tests for TagAppenderService::append().
 *
 * TagAppenderService has no external dependencies — pure string operations.
 * All tests instantiate it directly with default or custom platform limits.
 */
class TagAppenderServiceTest extends TestCase
{
    private TagAppenderService $svc;

    protected function setUp(): void
    {
        $this->svc = new TagAppenderService();
    }

    // TC1 — null tags: body unchanged, counts zero, no error
    public function testNullTagsReturnsBodyUnchanged(): void
    {
        $result = $this->svc->append('Hello world', null, 'twitter');

        $this->assertSame('Hello world', $result['body']);
        $this->assertSame(0, $result['tags_appended']);
        $this->assertSame(0, $result['tags_skipped']);
        $this->assertNull($result['error']);
    }

    // TC2 — empty string tags: body unchanged
    public function testEmptyStringTagsReturnsBodyUnchanged(): void
    {
        $result = $this->svc->append('Hello world', '', 'twitter');

        $this->assertSame('Hello world', $result['body']);
        $this->assertSame(0, $result['tags_appended']);
    }

    // TC3 — empty array tags: body unchanged
    public function testEmptyArrayTagsReturnsBodyUnchanged(): void
    {
        $result = $this->svc->append('Hello world', [], 'twitter');

        $this->assertSame('Hello world', $result['body']);
        $this->assertSame(0, $result['tags_appended']);
    }

    // TC4 — malformed JSON string: error set, body unchanged
    public function testMalformedJsonSetsError(): void
    {
        $result = $this->svc->append('Hello', 'not json', 'twitter');

        $this->assertSame('Hello', $result['body']);
        $this->assertSame(0, $result['tags_appended']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('malformed', (string) $result['error']);
    }

    // TC5 — JSON that is not an array (e.g. a quoted string): error set, body unchanged
    public function testJsonNonArraySetsError(): void
    {
        $result = $this->svc->append('Hello', '"just a string"', 'twitter');

        $this->assertSame('Hello', $result['body']);
        $this->assertNotNull($result['error']);
    }

    // TC6 — JSON array with one tag that fits: tag appended
    public function testSingleTagFitsIsAppended(): void
    {
        $result = $this->svc->append('Hello world', '["php"]', 'twitter');

        $this->assertSame('Hello world #php', $result['body']);
        $this->assertSame(1, $result['tags_appended']);
        $this->assertSame(0, $result['tags_skipped']);
        $this->assertNull($result['error']);
    }

    // TC7 — JSON array with two tags, both fit: both appended
    public function testTwoTagsBothFitAreAppended(): void
    {
        $result = $this->svc->append('Hi', '["php", "laravel"]', 'twitter');

        $this->assertSame('Hi #php #laravel', $result['body']);
        $this->assertSame(2, $result['tags_appended']);
        $this->assertSame(0, $result['tags_skipped']);
    }

    // TC8 — first tag fits, second does not: remaining all counted as skipped
    // (priority order is preserved — once a tag fails, all remaining are skipped)
    public function testFirstTagFitsSecondDoesNotAllRemainingSkipped(): void
    {
        // Custom limit of 20: 'Hello world' = 11 chars
        // 'Hello world #abc' = 16 ≤ 20 → appended
        // 'Hello world #abc #defghij' = 25 > 20 → fails → 'defghij' and 'k' both counted skipped
        $svc    = new TagAppenderService(['twitter' => 20]);
        $result = $svc->append('Hello world', '["abc", "defghij", "k"]', 'twitter');

        $this->assertSame('Hello world #abc', $result['body']);
        $this->assertSame(1, $result['tags_appended']);
        $this->assertSame(2, $result['tags_skipped']);
    }

    // TC9 — pre-decoded array input behaves identically to JSON string
    public function testPreDecodedArrayBehavesLikeJsonString(): void
    {
        $fromJson  = $this->svc->append('Hello', '["php", "laravel"]', 'twitter');
        $fromArray = $this->svc->append('Hello', ['php', 'laravel'], 'twitter');

        $this->assertSame($fromJson['body'],          $fromArray['body']);
        $this->assertSame($fromJson['tags_appended'], $fromArray['tags_appended']);
        $this->assertSame($fromJson['tags_skipped'],  $fromArray['tags_skipped']);
    }

    // TC10 — body already at platform limit: no tags appended, all skipped
    public function testBodyAtLimitNoTagsAppended(): void
    {
        $body   = str_repeat('a', 280); // exactly at Twitter limit
        $result = $this->svc->append($body, '["php"]', 'twitter');

        $this->assertSame($body, $result['body']);
        $this->assertSame(0, $result['tags_appended']);
        $this->assertSame(1, $result['tags_skipped']);
    }

    // TC11 — body + tag exactly at limit: tag appended (boundary inclusive)
    public function testBodyPlusTagExactlyAtLimitIsAppended(): void
    {
        // 275 chars + ' #xyz' (5 chars) = 280 = Twitter limit
        $body   = str_repeat('a', 275);
        $result = $this->svc->append($body, '["xyz"]', 'twitter');

        $this->assertSame($body . ' #xyz', $result['body']);
        $this->assertSame(1, $result['tags_appended']);
        $this->assertSame(0, $result['tags_skipped']);
    }

    // TC12 — body + tag one character over limit: tag not appended
    public function testBodyPlusTagOneOverLimitNotAppended(): void
    {
        // 276 chars + ' #xyz' (5 chars) = 281 > 280
        $body   = str_repeat('a', 276);
        $result = $this->svc->append($body, '["xyz"]', 'twitter');

        $this->assertSame($body, $result['body']);
        $this->assertSame(0, $result['tags_appended']);
        $this->assertSame(1, $result['tags_skipped']);
    }

    // TC13 — unrecognized platform: error set, body unchanged
    public function testUnrecognizedPlatformSetsError(): void
    {
        $result = $this->svc->append('Hello', '["php"]', 'myspace');

        $this->assertSame('Hello', $result['body']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('myspace', (string) $result['error']);
    }

    // TC14 — custom platform limits passed to constructor are honoured
    public function testCustomPlatformLimitsHonoured(): void
    {
        $svc    = new TagAppenderService(['custom' => 20]);
        $result = $svc->append('Hi', '["tag1"]', 'custom');

        // 'Hi' = 2, ' #tag1' = 6, total = 8 ≤ 20 → appended
        $this->assertSame('Hi #tag1', $result['body']);
        $this->assertSame(1, $result['tags_appended']);
        $this->assertNull($result['error']);
    }

    // TC15 — array with empty string entries: empty strings filtered out
    public function testEmptyStringEntriesFiltered(): void
    {
        $result = $this->svc->append('Hello', ['php', '', 'laravel'], 'twitter');

        $this->assertSame(2, $result['tags_appended']);
        $this->assertStringContainsString('#php',     $result['body']);
        $this->assertStringContainsString('#laravel', $result['body']);
        $this->assertStringNotContainsString('# ', $result['body']); // no empty-tag artefact
    }

    // TC16 — array with non-string entries (int, null): non-strings filtered out
    public function testNonStringEntriesFiltered(): void
    {
        // Only 'php' and 'laravel' pass is_string() check; 42 and null are dropped
        $result = $this->svc->append('Hello', ['php', 42, null, 'laravel'], 'twitter');

        $this->assertSame(2, $result['tags_appended']);
    }

    // TC17 — Twitter platform limit (280) enforced
    public function testTwitterLimitEnforced(): void
    {
        // 278 chars + ' #ab' (4 chars) = 282 > 280 → not appended
        $body   = str_repeat('a', 278);
        $result = $this->svc->append($body, '["ab"]', 'twitter');

        $this->assertSame(0, $result['tags_appended']);
    }

    // TC18 — Instagram platform limit (2200) enforced
    public function testInstagramLimitEnforced(): void
    {
        // 2197 chars + ' #ab' (4 chars) = 2201 > 2200 → not appended
        $body   = str_repeat('a', 2197);
        $result = $this->svc->append($body, '["ab"]', 'instagram');

        $this->assertSame(0, $result['tags_appended']);

        // 2195 chars + ' #ab' = 2199 ≤ 2200 → appended
        $bodyFits = str_repeat('a', 2195);
        $fits     = $this->svc->append($bodyFits, '["ab"]', 'instagram');
        $this->assertSame(1, $fits['tags_appended']);
    }

    // TC19 — Facebook platform limit (63206) enforced
    public function testFacebookLimitEnforced(): void
    {
        // 63202 chars + ' #ab' (4) = 63206 ≤ 63206 → appended (boundary)
        $body   = str_repeat('a', 63202);
        $result = $this->svc->append($body, '["ab"]', 'facebook');

        $this->assertSame(1, $result['tags_appended']);

        // 63203 chars + ' #ab' = 63207 > 63206 → not appended
        $bodyOver = str_repeat('a', 63203);
        $over     = $this->svc->append($bodyOver, '["ab"]', 'facebook');
        $this->assertSame(0, $over['tags_appended']);
    }

    // TC20 — tag with # prefix already in the value: service blindly prepends #
    // Result contains double ## which is the documented behaviour
    public function testTagWithHashPrefixResultsInDoubleHash(): void
    {
        $result = $this->svc->append('Hello', ['#php'], 'twitter');

        $this->assertSame('Hello ##php', $result['body']);
        $this->assertSame(1, $result['tags_appended']);
    }
}
