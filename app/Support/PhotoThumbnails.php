<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Small JPEG thumbnails for photo grids, so a grid of ten phone photos does not
 * download ten multi-megabyte originals.
 *
 * Every method is best-effort and never throws: when a thumbnail cannot be made
 * (GD missing, image too large for the memory limit, unsupported type) the
 * caller simply keeps using the original photo.
 */
final class PhotoThumbnails
{
    /** Longest edge of a thumbnail, in pixels. */
    public const MAX_EDGE = 640;

    private const QUALITY = 78;

    /** The thumbnail lives next to its original: "dir/abc123.jpg" -> "dir/abc123-thumb.jpg". */
    public static function pathFor(string $originalPath): string
    {
        $directory = pathinfo($originalPath, PATHINFO_DIRNAME);
        $name = preg_replace('/[^A-Za-z0-9_-]/', '', pathinfo($originalPath, PATHINFO_FILENAME)) ?: 'photo';

        return ($directory !== '.' && $directory !== '' ? $directory.'/' : '').$name.'-thumb.jpg';
    }

    /** Create and store a thumbnail for a just-uploaded file. Returns its path, or null. */
    public static function storeForUpload(UploadedFile $file, string $disk, string $originalPath): ?string
    {
        try {
            $real = $file->getRealPath();

            if (is_string($real) && $real !== '' && is_file($real)) {
                $binary = self::render($real);
            } else {
                $binary = self::renderFromContents((string) $file->get());
            }

            return self::put($binary, $disk, $originalPath);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /** Create a thumbnail for a file that is already in storage (used by the backfill command). */
    public static function storeForStoredFile(string $disk, string $originalPath): ?string
    {
        $temp = null;

        try {
            $stream = Storage::disk($disk)->readStream($originalPath);

            if (! is_resource($stream)) {
                return null;
            }

            $temp = tempnam(sys_get_temp_dir(), 'thumb');
            $target = $temp === false ? false : fopen($temp, 'wb');

            if (! is_resource($target)) {
                fclose($stream);

                return null;
            }

            stream_copy_to_stream($stream, $target);
            fclose($stream);
            fclose($target);

            return self::put(self::render($temp), $disk, $originalPath);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        } finally {
            if (is_string($temp)) {
                @unlink($temp);
            }
        }
    }

    private static function put(?string $binary, string $disk, string $originalPath): ?string
    {
        if ($binary === null) {
            return null;
        }

        $thumbPath = self::pathFor($originalPath);

        return Storage::disk($disk)->put($thumbPath, $binary) ? $thumbPath : null;
    }

    private static function renderFromContents(string $contents): ?string
    {
        if ($contents === '') {
            return null;
        }

        $temp = tempnam(sys_get_temp_dir(), 'thumb');

        try {
            if ($temp === false || file_put_contents($temp, $contents) === false) {
                return null;
            }

            return self::render($temp);
        } finally {
            if (is_string($temp)) {
                @unlink($temp);
            }
        }
    }

    /** Decode the image at $path and return a resized JPEG as a binary string. */
    private static function render(string $path): ?string
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagejpeg')) {
            return null;
        }

        $info = @getimagesize($path);

        if ($info === false) {
            return null;
        }

        [$width, $height, $type] = $info;

        if ($width < 1 || $height < 1 || ! self::fitsInMemory($width, $height)) {
            return null;
        }

        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        if ($source === false) {
            return null;
        }

        $scale = min(1, self::MAX_EDGE / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);

        // White background so transparent PNGs do not turn black as JPEG.
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        unset($source);

        // Phone photos are often stored sideways with an EXIF flag that the
        // browser honors on the original. Bake it in, on the small image.
        $angle = self::exifRotation($path, $type);

        if ($angle !== 0) {
            $rotated = imagerotate($canvas, $angle, 0);

            if ($rotated !== false) {
                $canvas = $rotated;
            }
        }

        ob_start();
        $ok = imagejpeg($canvas, null, self::QUALITY);
        $binary = ob_get_clean();

        return $ok && is_string($binary) && $binary !== '' ? $binary : null;
    }

    private static function exifRotation(string $path, int $type): int
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return 0;
        }

        $exif = @exif_read_data($path);

        return match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
    }

    /** GD decodes to ~4 bytes per pixel; skip images that would exhaust PHP's memory limit. */
    private static function fitsInMemory(int $width, int $height): bool
    {
        $limit = self::memoryLimitBytes();

        if ($limit === null) {
            return true;
        }

        $needed = ($width * $height * 5) + memory_get_usage(true);

        return $needed < $limit * 0.85;
    }

    private static function memoryLimitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
