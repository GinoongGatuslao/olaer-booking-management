<?php

namespace App\Services;

use App\Models\AmenityRequest;
use App\Models\AmenityRequestPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class AmenityDeliveryPhotoService
{
    /** Most proof photos one amenity request can hold. */
    public const MAX_PHOTOS = 10;

    /** Largest single photo, in kilobytes (64 MB). */
    public const MAX_KILOBYTES = 65536;

    public const DISK = GcashProofStorageService::PRIVATE_DISK;

    public const DIRECTORY = 'amenity-delivery-photos';

    /**
     * @param  list<UploadedFile>  $files
     * @return list<AmenityRequestPhoto>
     */
    public function store(int $amenityRequestId, array $files, int $userId): array
    {
        if ($files === []) {
            return [];
        }

        $storedPaths = [];

        try {
            return DB::transaction(function () use ($amenityRequestId, $files, $userId, &$storedPaths): array {
                $request = $this->lockedEditableRequest($amenityRequestId, $userId);

                $existing = AmenityRequestPhoto::query()
                    ->where('amenity_request_id', $request->amenity_request_id)
                    ->count();

                $remaining = max(self::MAX_PHOTOS - $existing, 0);

                if (count($files) > $remaining) {
                    throw new InvalidArgumentException(
                        $remaining === 0
                            ? 'This request already has the maximum of '.self::MAX_PHOTOS.' photos. Remove one to add another.'
                            : 'You can add '.$remaining.' more photo'.($remaining === 1 ? '' : 's').' (maximum '.self::MAX_PHOTOS.' per request).',
                    );
                }

                $photos = [];

                foreach ($files as $file) {
                    $path = $file->store(self::DIRECTORY, self::DISK);

                    if (! is_string($path) || ! $this->isAllowedPath($path)) {
                        throw new InvalidArgumentException('A photo could not be stored securely.');
                    }

                    $storedPaths[] = $path;

                    $photos[] = AmenityRequestPhoto::query()->create([
                        'amenity_request_id' => $request->amenity_request_id,
                        'uploaded_by_user_id' => $userId,
                        'path' => $path,
                        'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
                        'mime_type' => $file->getMimeType(),
                        'file_size' => (int) $file->getSize(),
                    ]);
                }

                return $photos;
            });
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk(self::DISK)->delete($path);
            }

            throw $exception;
        }
    }

    public function delete(int $photoId, int $userId): void
    {
        DB::transaction(function () use ($photoId, $userId): void {
            $photo = AmenityRequestPhoto::query()->lockForUpdate()->findOrFail($photoId);

            $this->lockedEditableRequest((int) $photo->amenity_request_id, $userId);

            $path = $this->normalize((string) $photo->path);
            $photo->delete();

            if ($path !== null) {
                Storage::disk(self::DISK)->delete($path);
            }
        });
    }

    public function normalize(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));

        return $this->isAllowedPath($path) ? $path : null;
    }

    /** Photos change only while the assigned staff member is still delivering. */
    private function lockedEditableRequest(int $amenityRequestId, int $userId): AmenityRequest
    {
        $request = AmenityRequest::query()->lockForUpdate()->findOrFail($amenityRequestId);

        if ($request->amenity_request_status !== 'Delivering') {
            throw new InvalidArgumentException('Proof photos can only be changed while the request is being delivered.');
        }

        if ((int) $request->assigned_to_user_id !== $userId) {
            throw new InvalidArgumentException('Only the assigned maintenance staff can change this request\'s photos.');
        }

        return $request;
    }

    private function isAllowedPath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '..')) {
            return false;
        }

        return preg_match('#^'.self::DIRECTORY.'/[A-Za-z0-9._-]+$#', $path) === 1;
    }
}
