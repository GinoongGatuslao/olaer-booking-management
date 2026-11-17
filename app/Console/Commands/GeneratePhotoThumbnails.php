<?php

namespace App\Console\Commands;

use App\Models\AmenityRequestPhoto;
use App\Models\FacilityConditionPhoto;
use App\Models\FacilityInspectionPhoto;
use App\Services\AmenityDeliveryPhotoService;
use App\Services\FacilityConditionPhotoService;
use App\Services\FacilityInspectionPhotoService;
use App\Support\PhotoThumbnails;
use Illuminate\Console\Command;

class GeneratePhotoThumbnails extends Command
{
    protected $signature = 'photos:generate-thumbnails
        {--limit=0 : Stop after this many photos (0 = no limit)}';

    protected $description = 'Create small thumbnails for photos uploaded before thumbnails existed';

    public function handle(): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $made = 0;
        $skipped = 0;

        $sets = [
            [FacilityConditionPhoto::class, FacilityConditionPhotoService::class, FacilityConditionPhotoService::DISK],
            [FacilityInspectionPhoto::class, FacilityInspectionPhotoService::class, FacilityInspectionPhotoService::DISK],
            [AmenityRequestPhoto::class, AmenityDeliveryPhotoService::class, AmenityDeliveryPhotoService::DISK],
        ];

        foreach ($sets as [$model, $service, $disk]) {
            $photos = app($service);

            $instance = new $model;

            $stop = false;

            $model::query()
                ->whereNull('thumb_path')
                ->chunkById(25, function ($chunk) use ($photos, $disk, $limit, &$made, &$skipped, $model, &$stop): bool {
                    foreach ($chunk as $photo) {
                        if ($limit > 0 && ($made + $skipped) >= $limit) {
                            $stop = true;

                            return false;
                        }

                        $path = $photos->normalize((string) $photo->path);
                        $thumbPath = $path === null
                            ? null
                            : PhotoThumbnails::storeForStoredFile($disk, $path);

                        if ($thumbPath === null) {
                            $skipped++;
                            $this->warn(class_basename($model).' #'.$photo->getKey().': no thumbnail made (kept using the original).');

                            continue;
                        }

                        $photo->forceFill(['thumb_path' => $thumbPath])->save();
                        $made++;
                    }

                    return true;
                }, $instance->getKeyName());

            if ($stop) {
                break;
            }
        }

        $this->info("Thumbnails created: {$made}. Skipped: {$skipped}.");

        return self::SUCCESS;
    }
}
