<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocialTurn\Services\StorageService;

/**
 * Unit tests for StorageService::storeFromBytes() — local driver only.
 *
 * StorageService::localBaseDir is hardcoded to {project_root}/images/ and
 * is not injectable. The success-path test (TC1) requires that directory to
 * be writable by the process running the test suite; it is marked skipped
 * when it is not. TC2 and TC3 test the failure/cleanup paths and run
 * regardless of directory permissions.
 */
class StorageServiceTest extends TestCase
{
    private StorageService $service;

    /** @var list<string> Absolute filesystem paths to remove in tearDown. */
    private array $cleanupPaths = [];

    protected function setUp(): void
    {
        $this->service = new StorageService();
        // Suppress PHP E_WARNING from copy() and error_log() output during failure-path tests.
        // StorageService::store() already records failures via error_log(); the native
        // copy() warning and the log message are not the behavior under test here.
        ini_set('error_log', '/dev/null');
        set_error_handler(static fn() => true, E_WARNING);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        ini_set('error_log', '');
        foreach ($this->cleanupPaths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        $this->cleanupPaths = [];
    }

    // TC1 — valid bytes are written to the resolved path; content matches
    public function testStoreFromBytesWritesContent(): void
    {
        $filename   = 'test_sfb_' . uniqid() . '.txt';
        $storedPath = $this->service->retrieve($filename);

        if (!is_writable(dirname($storedPath))) {
            $this->markTestSkipped(
                'Local storage directory is not writable in this environment — success-path test skipped.'
            );
        }

        $this->cleanupPaths[] = $storedPath;

        $result = $this->service->storeFromBytes('hello storage content', $filename);

        $this->assertTrue($result);
        $this->assertFileExists($storedPath);
        $this->assertStringEqualsFile($storedPath, 'hello storage content');
    }

    // TC2 — no st_img_* temp file survives after a storeFromBytes() call that fails
    //        due to the destination directory being unwritable (or succeeds — either way)
    public function testStoreFromBytesNoTempFileLeaked(): void
    {
        $filename   = 'test_sfb_leak_' . uniqid() . '.txt';
        $storedPath = $this->service->retrieve($filename);
        $this->cleanupPaths[] = $storedPath;

        $before = count((array) glob(sys_get_temp_dir() . '/st_img_*'));
        $this->service->storeFromBytes('hello', $filename);
        $after  = count((array) glob(sys_get_temp_dir() . '/st_img_*'));

        $this->assertSame($before, $after, 'storeFromBytes() must not leave a st_img_* temp file behind');
    }

    // TC3 — failure due to non-existent subdirectory: returns false and cleans up the temp file
    public function testStoreFromBytesFailureReturnsFalseAndCleansUp(): void
    {
        // The subdirectory does not exist under /images/ — copy() will fail
        $filename = 'nonexistent_subdir_' . uniqid() . '/image.jpg';

        $before = count((array) glob(sys_get_temp_dir() . '/st_img_*'));
        $result = $this->service->storeFromBytes('hello', $filename);
        $after  = count((array) glob(sys_get_temp_dir() . '/st_img_*'));

        $this->assertFalse($result);
        $this->assertSame($before, $after, 'Temp file must be cleaned up by the finally block even on failure');
    }
}
