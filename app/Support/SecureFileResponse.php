<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Sends a private stored file to the browser as fast as the disk allows.
 *
 * On an S3-compatible disk (the Laravel Cloud bucket) the app only does the
 * authorization it already did, then redirects the browser to a short-lived
 * signed bucket URL, so the image bytes never pass through PHP. On any other
 * disk (local, tests) it streams the file exactly as before.
 *
 * Callers MUST authorize the request before calling this.
 */
final class SecureFileResponse
{
    /** Signed URL lifetime for ordinary photos. */
    private const PHOTO_URL_MINUTES = 60;

    /** Signed URL lifetime for payment/refund proofs (never reused or cached). */
    private const PROOF_URL_MINUTES = 5;

    /**
     * @param  bool  $cacheable  true for photos: the signed URL is reused and the
     *                           browser may keep the image. false for payment
     *                           proofs: a fresh short-lived URL, nothing cached.
     */
    public static function inline(
        string $disk,
        string $path,
        string $filename,
        bool $cacheable = true,
    ): Response {
        $filesystem = Storage::disk($disk);

        if (config("filesystems.disks.{$disk}.driver") === 's3') {
            try {
                return self::redirectToBucket($disk, $path, $filename, $cacheable);
            } catch (Throwable $exception) {
                // Fall through to streaming so the image still loads.
                report($exception);
            }
        }

        abort_unless($filesystem->exists($path), 404);

        return $filesystem->response(
            $path,
            $filename,
            [
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
            ],
            'inline',
        );
    }

    private static function redirectToBucket(
        string $disk,
        string $path,
        string $filename,
        bool $cacheable,
    ): Response {
        $filesystem = Storage::disk($disk);
        $safeName = str_replace(['"', '\\', "\r", "\n"], '', $filename);

        $options = [
            'ResponseContentDisposition' => 'inline; filename="'.$safeName.'"',
            'ResponseCacheControl' => $cacheable
                ? 'private, max-age=86400'
                : 'private, no-store, max-age=0',
        ];

        if ($cacheable) {
            // Keep the same URL for most of its lifetime so the browser can
            // reuse the image it already downloaded.
            $url = Cache::remember(
                'secure-file-url:'.$disk.':'.sha1($path),
                now()->addMinutes(self::PHOTO_URL_MINUTES - 10),
                fn (): string => $filesystem->temporaryUrl(
                    $path,
                    now()->addMinutes(self::PHOTO_URL_MINUTES),
                    $options,
                ),
            );

            return redirect()->away($url)->withHeaders([
                'Cache-Control' => 'private, max-age=600',
            ]);
        }

        $url = $filesystem->temporaryUrl(
            $path,
            now()->addMinutes(self::PROOF_URL_MINUTES),
            $options,
        );

        return redirect()->away($url)->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
