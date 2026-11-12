<?php

namespace App\Services;

use App\Models\FacilityInspectionPhoto;
use App\Models\FacilityInspectionRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class FacilityInspectionPhotoService
{
    /** Most photos one inspection can hold. */
    public const MAX_PHOTOS = 10;

    /** Largest single photo, in kilobytes (64 MB). */
    public const MAX_KILOBYTES = 65536;

    public const DISK = GcashProofStorageService::PRIVATE_DISK;

    public const DIRECTORY = 'inspection-photos';

    /**
     * @param  list<UploadedFile>  $files
     * @return list<FacilityInspectionPhoto>
     */
    public function store(int $requestId, array $files, int $userId): array
    {
        if ($files === []) {
            return [];
        }

        $storedPaths = [];

        try {
            return DB::transaction(function () use ($requestId, $files, $userId, &$storedPaths): array {
                $request = $this->lockedEditableRequest($requestId, $userId);

                $existing = FacilityInspectionPhoto::query()
                    ->where('facility_inspection_request_id', $request->facility_inspection_request_id)
                    ->count();

                $remaining = max(self::MAX_PHOTOS - $existing, 0);

                if (count($files) > $remaining) {
                    throw new InvalidArgumentException(
                        $remaining === 0
                            ? 'This inspection already has the maximum of '.self::MAX_PHOTOS.' photos. Remove one to add another.'
                            : 'You can add '.$remaining.' more photo'.($remaining === 1 ? '' : 's').' (maximum '.self::MAX_PHOTOS.' per inspection).',
                    );
                }

                $photos = [];

                foreach ($files as $file) {
                    $path = $file->store(self::DIRECTORY, self::DISK);

                    if (! is_string($path) || ! $this->isAllowedPath($path)) {
                        throw new InvalidArgumentException('A photo could not be stored securely.');
                    }

                    $storedPaths[] = $path;

                    $photos[] = FacilityInspectionPhoto::query()->create([
                        'facility_inspection_request_id' => $request->facility_inspection_request_id,
                        'booking_details_id' => $request->booking_details_id,
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
            // Do not leave files behind when the database write was rolled back.
            foreach ($storedPaths as $path) {
                Storage::disk(self::DISK)->delete($path);
            }

            throw $exception;
        }
    }

    public function delete(int $photoId, int $userId): void
    {
        DB::transaction(function () use ($photoId, $userId): void {
            $photo = FacilityInspectionPhoto::query()->lockForUpdate()->findOrFail($photoId);

            $this->lockedEditableRequest((int) $photo->facility_inspection_request_id, $userId);

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

    /** Photos can only change while the assigned staff member has the inspection open. */
    private function lockedEditableRequest(int $requestId, int $userId): FacilityInspectionRequest
    {
        $request = FacilityInspectionRequest::query()->lockForUpdate()->findOrFail($requestId);

        if ($request->status !== 'In Progress') {
            throw new InvalidArgumentException('Photos can only be changed while the inspection is in progress.');
        }

        if ((int) $request->assigned_to_user_id !== $userId) {
            throw new InvalidArgumentException('Only the assigned maintenance staff can change this inspection\'s photos.');
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
