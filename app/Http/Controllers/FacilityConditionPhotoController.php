<?php

namespace App\Http\Controllers;

use App\Models\FacilityConditionPhoto;
use App\Services\FacilityConditionPhotoService;
use App\Support\SecureFileResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FacilityConditionPhotoController extends Controller
{
    /**
     * Streams a photo. Pass ?size=thumb for the small grid preview; it falls
     * back to the original when no thumbnail exists (older photos).
     */
    public function __invoke(
        Request $request,
        FacilityConditionPhoto $photo,
        FacilityConditionPhotoService $photos,
    ): Response {
        $role = strtolower(trim((string) $request->user()?->loadMissing('role')->role?->role_name));

        // "Maintenance Staff" is the role name used by the staff navigation.
        abort_unless(
            str_contains($role, 'maintenance') || in_array($role, ['cashier', 'admin'], true),
            403,
        );

        $path = $photos->normalize((string) $photo->path);

        abort_if($path === null, 404);

        $name = 'facility-photo-'.$photo->facility_condition_photo_id;

        if ($request->query('size') === 'thumb') {
            $thumbPath = $photos->normalize((string) $photo->thumb_path);

            if ($thumbPath !== null) {
                return SecureFileResponse::inline(FacilityConditionPhotoService::DISK, $thumbPath, $name.'-thumb.jpg');
            }
        }

        return SecureFileResponse::inline(
            FacilityConditionPhotoService::DISK,
            $path,
            $name.'.'.strtolower((string) pathinfo($path, PATHINFO_EXTENSION)),
        );
    }
}
