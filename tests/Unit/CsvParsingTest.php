<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SocialTurn\Services\CsvParser;
use SocialTurn\Services\StorageService;

/**
 * Tests for CsvParser::parse().
 *
 * StorageService is mocked in all tests — no filesystem access.
 * Temporary CSV files are written to the system temp directory and
 * cleaned up in tearDown().
 */
class CsvParsingTest extends TestCase
{
    private StorageService $storage;
    private CsvParser      $parser;

    /** @var list<string> Temp file paths to clean up after each test */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->storage = $this->createMock(StorageService::class);
        // Default: storage->exists() returns false (image not found).
        // Tests that need it to return true configure the mock individually.
        $this->storage->method('exists')->willReturn(false);

        $this->parser = new CsvParser($this->storage);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }
        $this->tmpFiles = [];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeCsvFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csvtest_');
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;
        return $path;
    }

    // -----------------------------------------------------------------------
    // TC1 — UTF-8 BOM is stripped; header row parsed correctly
    // -----------------------------------------------------------------------
    public function testBomPresentIsStripped(): void
    {
        $path = $this->makeCsvFile("\xEF\xBB\xBFbody\nHello world");

        $result = $this->parser->parse($path, PHP_INT_MAX, '', 1);

        $this->assertNull($result['parse_error']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('Hello world', $result['rows'][0]['body']);
    }

    // TC2 — no BOM present: header row parsed correctly without offset error
    public function testNoBomParsedCorrectly(): void
    {
        $path = $this->makeCsvFile("body\nHello world");

        $result = $this->parser->parse($path, PHP_INT_MAX, '', 1);

        $this->assertNull($result['parse_error']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('Hello world', $result['rows'][0]['body']);
    }

    // TC3 — rows whose first cell starts with # are skipped
    public function testCommentRowsSkipped(): void
    {
        $csv  = "# This is a comment\nbody\n# Another comment\nHello world";
        $path = $this->makeCsvFile($csv);

        $result = $this->parser->parse($path, PHP_INT_MAX, '', 1);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('Hello world', $result['rows'][0]['body']);
    }

    // TC4 — all-empty rows (trailing blank lines) are skipped
    public function testAllEmptyRowsSkipped(): void
    {
        $csv  = "body\nHello world\n,,,\n";
        $path = $this->makeCsvFile($csv);

        $result = $this->parser->parse($path, PHP_INT_MAX, '', 1);

        $this->assertCount(1, $result['rows']);
    }

    // TC5 — header with only 'body' column: optional fields default to null
    public function testHeaderBodyOnlyOptionalFieldsNull(): void
    {
        $path = $this->makeCsvFile("body\nHello world");

        $result = $this->parser->parse($path, PHP_INT_MAX, '', 1);

        $row = $result['rows'][0];
        $this->assertSame('Hello world', $row['body']);
        $this->assertNull($row['attributed_to']);
        $this->assertNull($row['image_filename']);
        $this->assertNull($row['internal_note']);
        $this->assertSame(1, $row['is_recyclable']); // falls back to isRecyclableDefault=1
    }

    // TC6 — header with all columns: all fields mapped; image found in storage
    public function testAllColumnsHeaderMapped(): void
    {
        $storage = $this->createMock(StorageService::class);
        $storage->method('exists')->willReturn(true); // image found
        $parser = new CsvParser($storage);

        $csv  = "body,attributed_to,image_filename,is_recyclable,internal_note\n"
              . "Hello world,Author,photo.jpg,1,My note";
        $path = $this->makeCsvFile($csv);

        $result = $parser->parse($path, PHP_INT_MAX, '', 0);

        $this->assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        $this->assertSame('Hello world', $row['body']);
        $this->assertSame('Author',      $row['attributed_to']);
        $this->assertSame('photo.jpg',   $row['image_filename']);
        $this->assertSame(1,             $row['is_recyclable']);
        $this->assertSame('My note',     $row['internal_note']);
    }

    // TC7 — columns in non-standard order: indices resolved correctly
    public function testNonStandardColumnOrderResolved(): void
    {
        $csv  = "is_recyclable,body,internal_note\n1,Hello world,my note";
        $path = $this->makeCsvFile($csv);

        $result = $this->parser->parse($path, PHP_INT_MAX, '', 0);

        $row = $result['rows'][0];
        $this->assertSame('Hello world', $row['body']);
        $this->assertSame(1,             $row['is_recyclable']);
        $this->assertSame('my note',     $row['internal_note']);
    }

    // TC8 — no 'body' column in header: parse_error returned
    public function testMissingBodyColumnSetsParseFatal(): void
    {
        $path = $this->makeCsvFile("attributed_to,is_recyclable\nAuthor,1");

        $result = $this->parser->parse($path, PHP_INT_MAX, '', 1);

        $this->assertNotNull($result['parse_error']);
        $this->assertStringContainsString('"body"', (string) $result['parse_error']);
        $this->assertEmpty($result['rows']);
    }

    // TC9 — empty body: row counted as skipped, error message recorded
    // Two-column CSV so the data row is not all-empty (name='John' keeps it alive)
    // but the body cell is empty — triggering the empty-body skip path.
    public function testEmptyBodySkippedWithError(): void
    {
        $path = $this->makeCsvFile("body,name\n,John");

        $result = $this->parser->parse($path, PHP_INT_MAX, '', 1);

        $this->assertSame(1, $result['skipped']);
        $this->assertCount(1, $result['errors']);
        $this->assertEmpty($result['rows']);
        $this->assertStringContainsString('body is empty', $result['errors'][0]);
    }

    // TC10 — body over character limit: row counted as failed, error recorded
    public function testBodyOverCharLimitFailed(): void
    {
        // charLimit = 10, body = 11 chars
        $path = $this->makeCsvFile("body\n12345678901");

        $result = $this->parser->parse($path, 10, 'twitter', 1);

        $this->assertSame(1, $result['failed']);
        $this->assertCount(1, $result['errors']);
        $this->assertEmpty($result['rows']);
        $this->assertStringContainsString('twitter', $result['errors'][0]);
    }

    // TC11 — body exactly at character limit: row accepted
    public function testBodyExactlyAtLimitAccepted(): void
    {
        // charLimit = 10, body = exactly 10 chars
        $path = $this->makeCsvFile("body\n1234567890");

        $result = $this->parser->parse($path, 10, 'twitter', 1);

        $this->assertCount(1, $result['rows']);
        $this->assertSame(0, $result['failed']);
    }

    // TC12 — body one character over limit: row rejected
    public function testBodyOneCharOverLimitRejected(): void
    {
        // charLimit = 10, body = 11 chars
        $path = $this->makeCsvFile("body\n12345678901");

        $result = $this->parser->parse($path, 10, 'twitter', 1);

        $this->assertEmpty($result['rows']);
        $this->assertSame(1, $result['failed']);
    }

    // TC13 — truthy is_recyclable variations all parsed as 1
    #[DataProvider('recyclableTruthyProvider')]
    public function testIsRecyclableTruthyParsedAsOne(string $value): void
    {
        $path = $this->makeCsvFile("body,is_recyclable\nHello,{$value}");

        $result = $this->parser->parse($path, PHP_INT_MAX, '', 0);

        $this->assertSame(1, $result['rows'][0]['is_recyclable']);
    }

    public static function recyclableTruthyProvider(): array
    {
        return [['1'], ['true'], ['True'], ['yes'], ['on']];
    }

    // TC14 — falsy is_recyclable variations all parsed as 0
    #[DataProvider('recyclableFalsyProvider')]
    public function testIsRecyclableFalsyParsedAsZero(string $value): void
    {
        // isRecyclableDefault = 1, but explicit value overrides it
        $path = $this->makeCsvFile("body,is_recyclable\nHello,{$value}");

        $result = $this->parser->parse($path, PHP_INT_MAX, '', 1);

        $this->assertSame(0, $result['rows'][0]['is_recyclable']);
    }

    public static function recyclableFalsyProvider(): array
    {
        return [['0'], ['false'], ['no'], ['off']];
    }

    // TC15 — unrecognised is_recyclable value falls back to isRecyclableDefault
    #[DataProvider('recyclableFallbackProvider')]
    public function testIsRecyclableUnknownFallsBackToDefault(string $value): void
    {
        $path = $this->makeCsvFile("body,is_recyclable\nHello,{$value}");

        $result = $this->parser->parse($path, PHP_INT_MAX, '', 1);

        $this->assertSame(1, $result['rows'][0]['is_recyclable']); // default=1 used
    }

    public static function recyclableFallbackProvider(): array
    {
        return [[''], ['maybe'], ['yes please'], ['2']];
    }

    // TC16 — 5001 data rows triggers cap_exceeded; first 5000 rows collected
    public function testRowCapExceededAt5001(): void
    {
        $lines = ['body'];
        for ($i = 1; $i <= 5001; $i++) {
            $lines[] = "Post number {$i}";
        }
        $path = $this->makeCsvFile(implode("\n", $lines));

        $result = $this->parser->parse($path, PHP_INT_MAX, '', 1);

        $this->assertTrue($result['cap_exceeded']);
        $this->assertNull($result['parse_error']);
        $this->assertCount(5000, $result['rows']);
    }

    // TC17 — attributed_to whitespace-only collapses to null
    public function testAttributedToWhitespaceStoredAsNull(): void
    {
        $path = $this->makeCsvFile("body,attributed_to\nHello,   ");

        $result = $this->parser->parse($path, PHP_INT_MAX, '', 1);

        $this->assertNull($result['rows'][0]['attributed_to']);
    }

    // TC18 — internal_note empty string stored as null
    public function testInternalNoteEmptyStoredAsNull(): void
    {
        $path = $this->makeCsvFile("body,internal_note\nHello,");

        $result = $this->parser->parse($path, PHP_INT_MAX, '', 1);

        $this->assertNull($result['rows'][0]['internal_note']);
    }

    // TC19 — image not found in storage: warning added, row imported without image
    public function testImageNotFoundInStorageAddsWarning(): void
    {
        // storage->exists() returns false by default (set in setUp)
        $path = $this->makeCsvFile("body,image_filename\nHello world,missing.jpg");

        $result = $this->parser->parse($path, PHP_INT_MAX, '', 1);

        $this->assertCount(1, $result['rows']);
        $this->assertNull($result['rows'][0]['image_filename']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('missing.jpg', $result['warnings'][0]);
    }
}
