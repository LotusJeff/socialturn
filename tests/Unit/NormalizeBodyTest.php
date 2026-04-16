<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for normalize_body() in libraries/shared.php.
 *
 * normalize_body() algorithm (in order):
 *   1. Lowercase
 *   2. Strip URLs (http/https)
 *   3. Strip all non-letter, non-number, non-whitespace characters
 *   4. Collapse all whitespace to a single space
 *   5. Trim
 *   6. Truncate to 280 characters (mb_substr)
 */
class NormalizeBodyTest extends TestCase
{
    // TC1 — empty string
    public function testEmptyStringReturnsEmpty(): void
    {
        $this->assertSame('', normalize_body(''));
    }

    // TC2 — punctuation only
    public function testPunctuationOnlyReturnsEmpty(): void
    {
        $this->assertSame('', normalize_body('!!!'));
    }

    // TC3 — whitespace only
    public function testWhitespaceOnlyReturnsEmpty(): void
    {
        $this->assertSame('', normalize_body('   '));
    }

    // TC4 — URL only
    public function testUrlOnlyReturnsEmpty(): void
    {
        $this->assertSame('', normalize_body('https://example.com'));
    }

    // TC5 — URL mid-sentence is stripped, surrounding words preserved
    public function testUrlMidSentenceStripped(): void
    {
        $result = normalize_body('Check out https://example.com for details');
        $this->assertSame('check out for details', $result);
    }

    // TC6 — mixed case lowercased
    public function testMixedCaseLowercased(): void
    {
        $this->assertSame('hello world', normalize_body('Hello World'));
    }

    // TC7 — punctuation stripped (apostrophe, comma, em-dash, exclamation)
    public function testPunctuationStripped(): void
    {
        // "it's great, really — amazing!" →
        // apostrophe, comma, em-dash, and exclamation mark all stripped
        $this->assertSame('its great really amazing', normalize_body("It's great, really \u{2014} amazing!"));
    }

    // TC8 — multiple consecutive spaces collapse to one
    public function testMultipleSpacesCollapsed(): void
    {
        $this->assertSame('hello world', normalize_body('Hello   World'));
    }

    // TC9 — leading and trailing whitespace trimmed
    public function testLeadingTrailingWhitespaceTrimmed(): void
    {
        $this->assertSame('hello', normalize_body('  hello  '));
    }

    // TC10 — accented Unicode letters preserved (\p{L} covers them)
    public function testUnicodeLettersPreserved(): void
    {
        $this->assertSame('café résumé', normalize_body('café résumé'));
    }

    // TC11 — ASCII numbers preserved (\p{N} covers digits)
    public function testNumbersPreserved(): void
    {
        $this->assertSame('post 42 examples', normalize_body('post 42 examples'));
    }

    // TC12 — string exactly 280 characters is returned unchanged
    public function testExactly280CharsNotTruncated(): void
    {
        $body   = str_repeat('a', 280);
        $result = normalize_body($body);
        $this->assertSame(280, mb_strlen($result));
        $this->assertSame($body, $result);
    }

    // TC13 — string 281 characters is truncated to 280
    public function test281CharsTruncatedTo280(): void
    {
        $body   = str_repeat('a', 281);
        $result = normalize_body($body);
        $this->assertSame(280, mb_strlen($result));
    }

    // TC14 — string 500 characters is truncated to 280
    public function test500CharsTruncatedTo280(): void
    {
        $body   = str_repeat('a', 500);
        $result = normalize_body($body);
        $this->assertSame(280, mb_strlen($result));
    }

    // TC15 — multibyte string with byte length > 280 but char length ≤ 280 is NOT truncated
    // 'é' is 2 bytes in UTF-8; 140 × 'é' = 280 bytes but 140 characters → not truncated
    public function testMultibyteCharLengthUnder280NotTruncated(): void
    {
        $body   = str_repeat('é', 140); // 140 chars, 280 bytes
        $result = normalize_body($body);
        // mb_substr must have been used — if substr() were used instead,
        // this would incorrectly truncate at byte 280 → 140 chars, matching
        // the input, so this alone isn't sufficient. TC16 provides the real proof.
        $this->assertSame(140, mb_strlen($result));
        $this->assertSame(str_repeat('é', 140), $result);
    }

    // TC16 — multibyte string with char length > 280 is truncated at 280 *characters* not bytes
    // 300 × 'é' = 300 chars, 600 bytes.
    // If substr() were used: truncation at byte 280 → 140 chars (wrong).
    // If mb_substr() is used: truncation at char 280 → 280 chars (correct).
    public function testMultibyteCharLengthOver280TruncatedAtCharBoundary(): void
    {
        $body   = str_repeat('é', 300); // 300 chars, 600 bytes
        $result = normalize_body($body);
        $this->assertSame(280, mb_strlen($result));          // exactly 280 characters
        $this->assertSame(str_repeat('é', 280), $result);   // not cut at byte 280
    }

    // TC17 — hashtag symbol stripped, word retained
    public function testHashtagSymbolStripped(): void
    {
        $this->assertSame('socialmedia', normalize_body('#SocialMedia'));
    }

    // TC18 — at-sign stripped, word retained
    public function testAtSignStripped(): void
    {
        $this->assertSame('username', normalize_body('@username'));
    }

    // TC19 — newlines collapse to single space
    public function testNewlinesCollapsedToSpace(): void
    {
        $this->assertSame('line1 line2 line3', normalize_body("line1\nline2\nline3"));
    }

    // TC20 — tabs collapse to single space
    public function testTabsCollapsedToSpace(): void
    {
        $this->assertSame('hello world', normalize_body("hello\tworld"));
    }
}
