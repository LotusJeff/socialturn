<?php
declare(strict_types=1);

namespace SocialTurn\Services;

use RuntimeException;
use InvalidArgumentException;

/**
 * StorageService
 *
 * Abstraction layer for all image file operations.
 * Supports two drivers controlled by the STORAGE_DRIVER constant in config.php:
 *
 *   'local' — reads/writes from the /images/ directory on this server.
 *             No additional packages required.
 *
 *   's3'    — reads/writes from AWS S3.
 *             Requires aws/aws-sdk-php installed separately:
 *             composer require aws/aws-sdk-php
 *             Throws RuntimeException at instantiation if the SDK is missing.
 *
 * Never call fopen, file_get_contents, copy, unlink, or S3 directly in
 * controllers or other services — all file operations go through this class.
 */
class StorageService
{
    private readonly string $driver;
    private readonly string $localBaseDir;

    /** @var \Aws\S3\S3Client|null Lazily instantiated on first S3 call. */
    private mixed $s3 = null;

    public function __construct()
    {
        $this->driver = defined('STORAGE_DRIVER') ? (string) STORAGE_DRIVER : 'local';

        if (!in_array($this->driver, ['local', 's3'], true)) {
            throw new InvalidArgumentException(
                "Unknown STORAGE_DRIVER value: '{$this->driver}'. Expected 'local' or 's3'."
            );
        }

        // Fail loudly at instantiation if S3 is selected but the SDK is absent.
        // aws/aws-sdk-php is intentionally excluded from composer.json so that
        // local installations carry no AWS dependency.
        if ($this->driver === 's3' && !class_exists(\Aws\S3\S3Client::class)) {
            throw new RuntimeException(
                "STORAGE_DRIVER is set to 's3' but aws/aws-sdk-php is not installed. " .
                "Run: composer require aws/aws-sdk-php"
            );
        }

        // Resolve to /images/ at the project root regardless of the web server's
        // working directory. src/Services/ is two levels below the project root.
        $this->localBaseDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'images';
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Move a file from a temporary path into managed storage.
     *
     * Local: copies $tmpPath to /images/$filename, then removes the source.
     * S3:    uploads the file at $tmpPath to the configured bucket.
     *
     * @param string $tmpPath  Absolute path to the source file (e.g. PHP upload tmp)
     * @param string $filename Target filename within managed storage
     */
    public function store(string $tmpPath, string $filename): bool
    {
        if ($this->driver === 's3') {
            try {
                $this->s3Client()->putObject([
                    'Bucket'     => S3_BUCKET,
                    'Key'        => $filename,
                    'SourceFile' => $tmpPath,
                ]);
                return true;
            } catch (\Throwable $e) {
                error_log("StorageService::store() S3 failed for '{$filename}': " . $e->getMessage());
                return false;
            }
        }

        $destination = $this->localPath($filename);

        if (!copy($tmpPath, $destination)) {
            error_log("StorageService::store() failed — could not copy '{$tmpPath}' to '{$destination}'");
            return false;
        }

        @unlink($tmpPath);
        return true;
    }

    /**
     * Write raw bytes into managed storage without touching the filesystem
     * in the caller. Creates a temp file internally, delegates to store(),
     * and guarantees cleanup in all paths.
     *
     * @param string $bytes    Raw file bytes (e.g. from a curl fetch)
     * @param string $filename Target filename within managed storage
     */
    public function storeFromBytes(string $bytes, string $filename): bool
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'st_img_');
        if ($tmpPath === false) {
            return false;
        }
        try {
            if (file_put_contents($tmpPath, $bytes) === false) {
                return false;
            }
            return $this->store($tmpPath, $filename);
        } finally {
            // store() unlinks on the local driver; this catches the S3 path
            // and any failure path where store() did not consume the file.
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    /**
     * Resolve a filename to its canonical readable location.
     *
     * -----------------------------------------------------------------------
     * DRIVER BEHAVIOUR — READ BEFORE CALLING
     * -----------------------------------------------------------------------
     * Local driver: returns the absolute filesystem path.
     *   Example: /var/www/html/images/photo.jpg
     *   This is a SERVER-SIDE PATH, not a URL. It is suitable for direct file
     *   operations (reading, checking size, etc.) but NOT for passing to any
     *   platform API that expects an HTTP URL.
     *
     * S3 driver: returns the public HTTPS object URL.
     *   Example: https://my-bucket.s3.us-east-1.amazonaws.com/photo.jpg
     *   This is suitable for passing directly to any platform API.
     *
     * -----------------------------------------------------------------------
     * WHICH METHOD TO USE PER PLATFORM
     * -----------------------------------------------------------------------
     * TwitterService media upload:
     *   DO NOT use retrieve(). Use getReadStream() instead. Twitter requires
     *   the raw file bytes streamed via multipart upload, not a URL.
     *
     * FacebookService and InstagramService image posts:
     *   The Meta Graph API requires a publicly accessible HTTP URL for image posts.
     *   - S3 driver:    retrieve() works directly — it returns a public HTTPS URL.
     *   - Local driver: retrieve() returns a filesystem path, which the Graph API
     *                   cannot use. Callers must construct the public URL as:
     *                   BASE_URL . 'images/' . $filename
     *
     * Any direct file operation (exists check, size, etc.):
     *   Local: retrieve() gives you the path you need.
     *   S3:    use exists() to check; for reading, prefer getReadStream().
     * -----------------------------------------------------------------------
     */
    public function retrieve(string $filename): string
    {
        if ($this->driver === 's3') {
            $region = defined('S3_REGION') ? S3_REGION : 'us-east-1';
            $bucket = defined('S3_BUCKET') ? S3_BUCKET : '';

            return "https://{$bucket}.s3.{$region}.amazonaws.com/{$filename}";
        }

        return $this->localPath($filename);
    }

    /**
     * Delete a file from managed storage.
     *
     * Local: unlinks /images/$filename.
     * S3:    deletes the object from the configured bucket.
     */
    public function delete(string $filename): bool
    {
        if ($this->driver === 's3') {
            try {
                $this->s3Client()->deleteObject([
                    'Bucket' => S3_BUCKET,
                    'Key'    => $filename,
                ]);
                return true;
            } catch (\Throwable $e) {
                error_log("StorageService::delete() S3 failed for '{$filename}': " . $e->getMessage());
                return false;
            }
        }

        $path = $this->localPath($filename);

        if (!file_exists($path)) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * Check whether a file exists in managed storage.
     *
     * Local: wraps file_exists().
     * S3:    issues a HeadObject request; returns false on NoSuchKey or any error.
     */
    public function exists(string $filename): bool
    {
        if ($this->driver === 's3') {
            try {
                $this->s3Client()->headObject([
                    'Bucket' => S3_BUCKET,
                    'Key'    => $filename,
                ]);
                return true;
            } catch (\Throwable $e) {
                error_log("StorageService::exists() S3 failed for '{$filename}': " . $e->getMessage());
                return false;
            }
        }

        return file_exists($this->localPath($filename));
    }

    /**
     * Open a readable stream for a file without loading it fully into memory.
     *
     * This is the correct method for TwitterService media uploads. Twitter
     * requires raw file bytes streamed via multipart upload — never a URL.
     * For all other platforms, see the retrieve() docblock.
     *
     * Local: returns fopen($path, 'rb').
     * S3:    returns the stream resource from a GetObject response body.
     *
     * @return resource
     * @throws RuntimeException if the file cannot be opened or does not exist
     */
    public function getReadStream(string $filename): mixed
    {
        if ($this->driver === 's3') {
            try {
                $result = $this->s3Client()->getObject([
                    'Bucket' => S3_BUCKET,
                    'Key'    => $filename,
                ]);
                return $result['Body']->detach();
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    "StorageService: could not open S3 stream for '{$filename}': " . $e->getMessage()
                );
            }
        }

        $path = $this->localPath($filename);

        if (!file_exists($path)) {
            throw new RuntimeException(
                "StorageService: file not found in local storage: '{$filename}'"
            );
        }

        $stream = @fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException(
                "StorageService: could not open local file for reading: '{$filename}'"
            );
        }

        return $stream;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Returns the absolute local filesystem path for a given filename.
     * Derived from dirname(__DIR__, 2) so it resolves correctly from
     * src/Services/ regardless of the web server's working directory.
     */
    private function localPath(string $filename): string
    {
        return $this->localBaseDir . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Lazily instantiates and returns an S3Client using S3_* constants.
     * Only ever called when driver === 's3'.
     */
    private function s3Client(): \Aws\S3\S3Client
    {
        if ($this->s3 === null) {
            $this->s3 = new \Aws\S3\S3Client([
                'version'     => 'latest',
                'region'      => defined('S3_REGION') ? S3_REGION : 'us-east-1',
                'credentials' => [
                    'key'    => defined('S3_KEY')    ? S3_KEY    : '',
                    'secret' => defined('S3_SECRET') ? S3_SECRET : '',
                ],
            ]);
        }

        return $this->s3;
    }
}
