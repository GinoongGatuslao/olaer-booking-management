<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class GcashProofStorageService
{
    private const DIRECTORY = 'gcash-proofs';

    public function store(UploadedFile $file): string
    {
        $path = $file->store(self::DIRECTORY, 'local');

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

        if ($path !== null) {
            Storage::disk('local')->delete($path);
        }
    }

    public function diskContaining(string $path): ?string
    {
        $path = $this->normalize($path);

        if ($path === null) {
            return null;
        }

        if (Storage::disk('local')->exists($path)) {
            return 'local';
        }

        // Temporary backward compatibility for proofs created before this
        // hardening package. Run the migration command to remove this legacy
        // public copy after moving it to private storage.
        if (Storage::disk('public')->exists($path)) {
            return 'public';
        }

        return null;
    }

    public function moveLegacyPublicFile(string $path): bool
    {
        $path = $this->normalize($path);

        if ($path === null) {
            return false;
        }

        if (Storage::disk('local')->exists($path)) {
            Storage::disk('public')->delete($path);

            return true;
        }

        if (! Storage::disk('public')->exists($path)) {
            return false;
        }

        $stream = Storage::disk('public')->readStream($path);

        if ($stream === false) {
            return false;
        }

        try {
            $written = Storage::disk('local')->writeStream(
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

        Storage::disk('public')->delete($path);

        return true;
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
