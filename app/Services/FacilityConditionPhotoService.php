<?php

namespace App\Services;

use App\Models\BookingDetail;
use App\Models\Facility;
use App\Models\FacilityConditionPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class FacilityConditionPhotoService
{
    /** Photos a facility can hold at once (Current + In Use). */
    public const MAX_PHOTOS = 10;

    /** Largest single photo, in kilobytes (64 MB). */
    public const MAX_KILOBYTES = 65536;

    public const DISK = GcashProofStorageService::PRIVATE_DISK;

    public const DIRECTORY = 'condition-photos';

    /**
     * @param  list<UploadedFile>  $files
     * @return list<FacilityConditionPhoto>
     */
    public function store(int $facilityId, array $files, int $userId): array
    {
        if ($files === []) {
            return [];
        }

        $storedPaths = [];

        try {
            return DB::transaction(function () use ($facilityId, $files, $userId, &$storedPaths): array {
                $facility = Facility::query()->lockForUpdate()->findOrFail($facilityId);

                if ($this->isOccupied($facilityId)) {
                    throw new InvalidArgumentException(
                        'A guest is checked in to this facility. New photos can be added after check-out.',
                    );
                }

                $existing = FacilityConditionPhoto::query()
                    ->where('facility_id', $facility->facility_id)
                    ->whereIn('status', [FacilityConditionPhoto::CURRENT, FacilityConditionPhoto::IN_USE])
                    ->count();

                $remaining = max(self::MAX_PHOTOS - $existing, 0);

                if (count($files) > $remaining) {
                    throw new InvalidArgumentException(
                        $remaining === 0
                            ? 'This facility already has the maximum of '.self::MAX_PHOTOS.' photos. Remove one to add another.'
                            : 'You can add '.$remaining.' more photo'.($remaining === 1 ? '' : 's').' (maximum '.self::MAX_PHOTOS.' per facility).',
                    );
                }

                $photos = [];

                foreach ($files as $file) {
                    $path = $file->store(self::DIRECTORY, self::DISK);

                    if (! is_string($path) || ! $this->isAllowedPath($path)) {
                        throw new InvalidArgumentException('A photo could not be stored securely.');
                    }

                    $storedPaths[] = $path;

                    $photos[] = FacilityConditionPhoto::query()->create([
                        'facility_id' => $facility->facility_id,
                        'uploaded_by_user_id' => $userId,
                        'path' => $path,
                        'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
                        'mime_type' => $file->getMimeType(),
                        'file_size' => (int) $file->getSize(),
                        'status' => FacilityConditionPhoto::CURRENT,
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

    /** Only photos still waiting for the next guest can be removed. */
    public function delete(int $photoId): void
    {
        DB::transaction(function () use ($photoId): void {
            $photo = FacilityConditionPhoto::query()->lockForUpdate()->findOrFail($photoId);

            if ($photo->status !== FacilityConditionPhoto::CURRENT) {
                throw new InvalidArgumentException('Photos tied to a guest stay are kept as a record and cannot be removed.');
            }

            $path = $this->normalize((string) $photo->path);
            $photo->delete();

            if ($path !== null) {
                Storage::disk(self::DISK)->delete($path);
            }
        });
    }

    /** Check-in: the facility's Current photos become the baseline of this stay. */
    public function attachToStay(BookingDetail $detail): void
    {
        if ($detail->facility_id === null) {
            return;
        }

        FacilityConditionPhoto::query()
            ->where('facility_id', $detail->facility_id)
            ->where('status', FacilityConditionPhoto::CURRENT)
            ->update([
                'status' => FacilityConditionPhoto::IN_USE,
                'booking_details_id' => $detail->booking_details_id,
            ]);
    }

    /** Check-out: this stay's baseline photos are now outdated; new ones are needed. */
    public function retireForStay(BookingDetail $detail): void
    {
        FacilityConditionPhoto::query()
            ->where('booking_details_id', $detail->booking_details_id)
            ->where('status', FacilityConditionPhoto::IN_USE)
            ->update([
                'status' => FacilityConditionPhoto::OUTDATED,
                'outdated_at' => now(),
            ]);
    }

    public function isOccupied(int $facilityId): bool
    {
        return BookingDetail::query()
            ->where('facility_id', $facilityId)
            ->where('status', 'Checked-in')
            ->exists();
    }

    public function normalize(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));

        return $this->isAllowedPath($path) ? $path : null;
    }

    private function isAllowedPath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '..')) {
            return false;
        }

        return preg_match('#^'.self::DIRECTORY.'/[A-Za-z0-9._-]+$#', $path) === 1;
    }
}
