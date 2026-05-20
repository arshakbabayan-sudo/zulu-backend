<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Re-encodes uploaded images so embedded metadata (EXIF GPS coordinates,
 * camera info, software fingerprints) does not reach public storage.
 *
 * Uses PHP-GD natively rather than pulling in Intervention/Image — for a
 * single re-encode pass the dependency cost is not justified. GD's decode +
 * encode round-trip inherently drops every ancillary chunk (EXIF, XMP, IPTC,
 * thumbnails) because GD only preserves the pixel raster.
 *
 * Supported MIME types match the upload validator in MediaUploadController:
 * image/jpeg, image/png, image/webp. Animated WebP / GIF are NOT supported
 * — re-encoding would flatten to a single frame and we don't accept GIFs
 * in the upload contract anyway.
 */
class ImageSanitizer
{
    /** JPEG quality for re-encode. Matches Intervention defaults (~90). */
    private const JPEG_QUALITY = 90;

    /** PNG compression level 0-9 (9 = max compression, lossless). */
    private const PNG_COMPRESSION = 6;

    /** WebP quality 0-100. */
    private const WEBP_QUALITY = 90;

    /**
     * Returns a new UploadedFile pointing at a temp file with metadata stripped.
     * The original UploadedFile is not modified.
     */
    public function stripExif(UploadedFile $file): UploadedFile
    {
        $mime = strtolower((string) $file->getMimeType());
        $sourcePath = $file->getRealPath();

        if ($sourcePath === false || ! is_readable($sourcePath)) {
            throw new RuntimeException('ImageSanitizer: source file unreadable.');
        }

        $image = $this->decode($sourcePath, $mime);
        if ($image === null) {
            // Unsupported MIME (or decode failed) — return original untouched.
            // The controller's validator should already exclude these, but we
            // fail-open rather than break uploads.
            return $file;
        }

        try {
            $tmpPath = tempnam(sys_get_temp_dir(), 'zulu-img-');
            if ($tmpPath === false) {
                throw new RuntimeException('ImageSanitizer: tempnam failed.');
            }

            $this->encode($image, $tmpPath, $mime);
        } finally {
            imagedestroy($image);
        }

        return new UploadedFile(
            $tmpPath,
            $file->getClientOriginalName(),
            $mime,
            null,
            true, // test mode = trust the path; required for moveable temp files
        );
    }

    /** @return \GdImage|null */
    private function decode(string $path, string $mime)
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => null,
        };

        return $image ?: null;
    }

    /** @param \GdImage $image */
    private function encode($image, string $path, string $mime): void
    {
        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($image, $path, self::JPEG_QUALITY),
            'image/png' => imagepng($image, $path, self::PNG_COMPRESSION),
            'image/webp' => imagewebp($image, $path, self::WEBP_QUALITY),
            default => false,
        };

        if (! $ok) {
            throw new RuntimeException('ImageSanitizer: encode failed for '.$mime);
        }
    }
}
