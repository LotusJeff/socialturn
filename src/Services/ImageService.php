<?php
declare(strict_types=1);

namespace SocialTurn\Services;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * ImageService
 *
 * Prepares and generates images for platform posting using PHP GD only.
 * No Composer dependencies — GD is a standard PHP extension (php-gd).
 *
 * Two public operations:
 *   prepareForPlatform()    — resizes and center-crops an existing post image
 *   generateFromTemplate()  — creates a branded image with post text overlaid
 *
 * Both methods return a storage-relative path on success, null on any failure.
 * GD resources are destroyed in finally blocks (mirroring TwitterService::uploadMedia())
 * to prevent memory exhaustion during high-volume cron runs.
 *
 * Image storage layout (all paths relative to /images/):
 *   originals/               Source images — post images and base templates
 *   processed/{platform}/    Output of prepareForPlatform()
 *   generated/{platform}/    Output of generateFromTemplate()
 *
 * StorageService interaction:
 *   Input:  loadImage() calls getReadStream() → imagecreatefromstring().
 *           Works identically for local (filesystem stream) and S3 (HTTP stream)
 *           without any driver-awareness in this class.
 *   Output: saveTempJpeg() writes a temp JPEG; store() moves it into managed
 *           storage. The temp file is always cleaned up in the finally block.
 */
class ImageService
{
    public function __construct(private readonly StorageService $storage) {}

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Resize and crop a post image to the correct dimensions for a platform.
     *
     * Loads originals/{filename}, applies resize-to-cover + center crop to
     * the platform's target dimensions, saves as JPEG to processed/{platform}/.
     *
     * GD resources ($src, $output) and the temp file are destroyed/deleted
     * in the finally block regardless of outcome.
     *
     * @param string $filename  Bare filename within originals/ (e.g. 'photo.jpg')
     * @param string $platform  'twitter', 'facebook', or 'instagram'
     * @return string|null      Storage-relative path on success (e.g. 'processed/twitter/twitter_photo_abc.jpg'),
     *                          null on any failure — never throws
     */
    public function prepareForPlatform(string $filename, string $platform): ?string
    {
        $src     = null;
        $output  = null;
        $tmpFile = null;

        try {
            [$targetW, $targetH] = $this->platformDimensions($platform);

            $src    = $this->loadImage('originals/' . $filename);
            $output = $this->resizeAndCrop($src, $targetW, $targetH);

            $stem        = pathinfo($filename, PATHINFO_FILENAME);
            $outFilename = $platform . '_' . $stem . '_' . uniqid() . '.jpg';
            $storedPath  = 'processed/' . $platform . '/' . $outFilename;

            $tmpFile = $this->saveTempJpeg($output);

            if (!$this->storage->store($tmpFile, $storedPath)) {
                return null;
            }

            return $storedPath;

        } catch (Throwable) {
            return null;
        } finally {
            if ($src    !== null) { imagedestroy($src); }
            if ($output !== null) { imagedestroy($output); }
            // store() unlinks on local success; this catches failures + S3 path
            if ($tmpFile !== null && file_exists($tmpFile)) { @unlink($tmpFile); }
        }
    }

    /**
     * Generate a branded image from the account's base template with post text overlaid.
     *
     * Loads originals/{baseImageFilename}, resizes/crops to platform dimensions,
     * renders the post text as a lower-third text overlay, saves as JPEG to
     * generated/{platform}/.
     *
     * GD resources ($src, $canvas) and the temp file are destroyed/deleted
     * in the finally block regardless of outcome.
     *
     * @param string $baseImageFilename  Bare filename within originals/ (e.g. 'template.jpg')
     * @param string $text               Post caption text to render as lower-third overlay
     * @param string $platform           'twitter', 'facebook', or 'instagram'
     * @return string|null               Storage-relative path on success, null on any failure — never throws
     */
    public function generateFromTemplate(string $baseImageFilename, string $text, string $platform): ?string
    {
        $src     = null;
        $canvas  = null;
        $tmpFile = null;

        try {
            [$targetW, $targetH] = $this->platformDimensions($platform);

            $src    = $this->loadImage('originals/' . $baseImageFilename);
            $canvas = $this->resizeAndCrop($src, $targetW, $targetH);

            $this->overlayText($canvas, $text, $targetW, $targetH);

            $outFilename = $platform . '_gen_' . uniqid() . '.jpg';
            $storedPath  = 'generated/' . $platform . '/' . $outFilename;

            $tmpFile = $this->saveTempJpeg($canvas);

            if (!$this->storage->store($tmpFile, $storedPath)) {
                return null;
            }

            return $storedPath;

        } catch (Throwable) {
            return null;
        } finally {
            if ($src    !== null) { imagedestroy($src); }
            if ($canvas !== null) { imagedestroy($canvas); }
            if ($tmpFile !== null && file_exists($tmpFile)) { @unlink($tmpFile); }
        }
    }

    /**
     * Returns target [width, height] for a given platform.
     *
     *   twitter   → [1200, 675]   16:9 summary card
     *   facebook  → [1200, 630]   link preview aspect ratio
     *   instagram → [1080, 1080]  square
     *
     * @return array{0: int, 1: int}
     * @throws InvalidArgumentException for unrecognized platform names
     */
    public function platformDimensions(string $platform): array
    {
        return match ($platform) {
            'twitter'   => [1200,  675],
            'facebook'  => [1200,  630],
            'instagram' => [1080, 1080],
            default     => throw new InvalidArgumentException(
                "ImageService: unknown platform '{$platform}'. Expected twitter, facebook, or instagram."
            ),
        };
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Resize-to-cover then center-crop to exact target dimensions.
     *
     * Scale factor = max(targetW / srcW, targetH / srcH) ensures the image
     * covers the full target canvas with no letterboxing. The center crop is
     * back-calculated into original image coordinates so a single
     * imagecopyresampled() call performs both scale and crop together.
     *
     * @throws RuntimeException if imagecreatetruecolor() fails
     */
    private function resizeAndCrop(\GdImage $src, int $targetW, int $targetH): \GdImage
    {
        $srcW = imagesx($src);
        $srcH = imagesy($src);

        // Scale to cover: largest factor ensuring both dimensions are filled
        $scale = max($targetW / $srcW, $targetH / $srcH);

        // Back-calculate source region in original coordinates that maps to
        // exactly targetW × targetH after scaling (center crop)
        $srcCropW = (int) round($targetW / $scale);
        $srcCropH = (int) round($targetH / $scale);
        $srcCropX = (int) round(($srcW - $srcCropW) / 2);
        $srcCropY = (int) round(($srcH - $srcCropH) / 2);

        $output = imagecreatetruecolor($targetW, $targetH);
        if ($output === false) {
            throw new RuntimeException('ImageService: imagecreatetruecolor() failed.');
        }

        // Single call — scale + crop
        imagecopyresampled(
            $output, $src,
            0,         0,
            $srcCropX, $srcCropY,
            $targetW,  $targetH,
            $srcCropW, $srcCropH
        );

        return $output;
    }

    /**
     * Render post text as a lower-third overlay on the canvas.
     *
     * Uses GD built-in font 5 (9×15 px per character). No TTF font file
     * required — guaranteed to render on any server with GD enabled.
     *
     * Layout:
     *   - Text is word-wrapped to fit canvas width minus 16px horizontal padding each side
     *   - A semi-transparent dark bar (~70% opaque) spans the full width behind the text
     *   - Bar sits 20px from the bottom edge of the canvas
     *   - White text with 12px vertical padding inside the bar
     *
     * Modifies $canvas in place. No return value.
     */
    private function overlayText(\GdImage $canvas, string $text, int $canvasW, int $canvasH): void
    {
        $fontW       = imagefontwidth(5);    // 9 px per character
        $fontH       = imagefontheight(5);   // 15 px per line
        $padH        = 16;                   // horizontal padding each side
        $padV        = 12;                   // vertical padding top and bottom inside bar
        $lineSpacing = 4;                    // extra pixels between lines

        $usableWidth  = $canvasW - ($padH * 2);
        $charsPerLine = max(1, (int) floor($usableWidth / $fontW));

        $wrapped   = wordwrap($text, $charsPerLine, "\n", true);
        $lines     = explode("\n", $wrapped);
        $lineCount = count($lines);

        $lineHeight = $fontH + $lineSpacing;
        $barHeight  = ($padV * 2) + ($lineCount * $lineHeight) - $lineSpacing;
        $barTop     = $canvasH - $barHeight - 20;

        // Semi-transparent dark background (alpha 38 ≈ 70% opaque; GD: 0=opaque, 127=transparent)
        $darkBg = imagecolorallocatealpha($canvas, 0, 0, 0, 38);
        imagefilledrectangle($canvas, 0, $barTop, $canvasW - 1, $canvasH - 20, $darkBg);

        $white = imagecolorallocate($canvas, 255, 255, 255);
        foreach ($lines as $i => $line) {
            $y = $barTop + $padV + ($i * $lineHeight);
            imagestring($canvas, 5, $padH, $y, $line, $white);
        }
    }

    /**
     * Load an image from managed storage into a GD resource.
     *
     * Uses getReadStream() for both drivers: local returns a filesystem stream,
     * S3 returns an HTTP stream. stream_get_contents() reads the bytes and
     * imagecreatefromstring() auto-detects JPEG, PNG, GIF, and WebP.
     *
     * @throws RuntimeException if the stream cannot be read or GD cannot decode the image
     */
    private function loadImage(string $storagePath): \GdImage
    {
        $stream = $this->storage->getReadStream($storagePath);
        $bytes  = stream_get_contents($stream);
        fclose($stream);

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException(
                "ImageService: could not read image data from storage: '{$storagePath}'"
            );
        }

        $image = imagecreatefromstring($bytes);

        if ($image === false) {
            throw new RuntimeException(
                "ImageService: GD could not decode image from storage: '{$storagePath}'"
            );
        }

        return $image;
    }

    /**
     * Write a GD image resource to a temp file as JPEG at quality 90.
     *
     * Returns the absolute temp file path. The caller is responsible for
     * cleanup — typically done in a finally block alongside imagedestroy().
     * StorageService::store() on the local driver unlinks the temp on success;
     * the finally block catches failures and the S3 path where store() does not unlink.
     *
     * @throws RuntimeException if the temp file cannot be created or written
     */
    private function saveTempJpeg(\GdImage $image): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'socialturn_img_');

        if ($tmpFile === false) {
            throw new RuntimeException('ImageService: could not create temp file.');
        }

        if (!imagejpeg($image, $tmpFile, 90)) {
            @unlink($tmpFile);
            throw new RuntimeException('ImageService: imagejpeg() failed to write temp file.');
        }

        return $tmpFile;
    }
}
