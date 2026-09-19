<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class GcashProofStorageService
{
    public const PRIVATE_DISK = 'storage-4-private';

    public const PUBLIC_DISK = 'storage-4-public';

    private const LEGACY_PRIVATE_DISK = 'local';

    private const LEGACY_PUBLIC_DISK = 'public';

    private const DIRECTORY = 'gcash-proofs';

    public function store(UploadedFile $file): string
    {
        $path = $file->store(self::DIRECTORY, self::PRIVATE_DISK);

        if (! is_string($path) || ! $this->isAllowedPath($path)) {
            throw new InvalidArgumentException(
                'The GCash proof could not be stored securely.',
            );
        }

        return $path;
    }

    public function deletePrivate(string $path): void
    {
        $path = $this->normalize($path);

        if ($path === null) {
            return;
        }

        Storage::disk(self::PRIVATE_DISK)->delete($path);

        // Clean up a same-path legacy copy if this request was created while
        // the application still used the server-local private disk.
        Storage::disk(self::LEGACY_PRIVATE_DISK)->delete($path);
    }

    public function diskContaining(string $path): ?string
    {
        $path = $this->normalize($path);

        if ($path === null) {
            return null;
        }

        if (Storage::disk(self::PRIVATE_DISK)->exists($path)) {
            return self::PRIVATE_DISK;
        }

        // Backward compatibility for proofs created before Laravel Cloud
        // object storage was enabled. These should be migrated to the private
        // bucket using the existing migration command.
        if (Storage::disk(self::LEGACY_PRIVATE_DISK)->exists($path)) {
            return self::LEGACY_PRIVATE_DISK;
        }

        if (Storage::disk(self::LEGACY_PUBLIC_DISK)->exists($path)) {
            return self::LEGACY_PUBLIC_DISK;
        }

        return null;
    }

    public function moveLegacyPublicFile(string $path): bool
    {
        $path = $this->normalize($path);

        if ($path === null) {
            return false;
        }

        if (Storage::disk(self::PRIVATE_DISK)->exists($path)) {
            Storage::disk(self::LEGACY_PRIVATE_DISK)->delete($path);
            Storage::disk(self::LEGACY_PUBLIC_DISK)->delete($path);

            return true;
        }

        foreach (
            [self::LEGACY_PRIVATE_DISK, self::LEGACY_PUBLIC_DISK]
            as $legacyDisk
        ) {
            if (! Storage::disk($legacyDisk)->exists($path)) {
                continue;
            }

            $stream = Storage::disk($legacyDisk)->readStream($path);

            if ($stream === false) {
                return false;
            }

            try {
                $written = Storage::disk(self::PRIVATE_DISK)->writeStream(
                    $path,
                    $stream,
                );
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (! $written) {
                return false;
            }

            Storage::disk($legacyDisk)->delete($path);

            return true;
        }

        return false;
    }

    public function normalize(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));

        if (! $this->isAllowedPath($path)) {
            return null;
        }

        return $path;
    }

    private function isAllowedPath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        if (str_contains($path, '..')) {
            return false;
        }

        return preg_match(
            '#^'.self::DIRECTORY.'/[A-Za-z0-9._-]+$#',
            $path,
        ) === 1;
    }
}
