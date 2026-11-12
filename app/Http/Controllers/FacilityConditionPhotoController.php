<?php

namespace App\Http\Controllers;

use App\Models\FacilityConditionPhoto;
use App\Services\FacilityConditionPhotoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FacilityConditionPhotoController extends Controller
{
    public function __invoke(
        Request $request,
        FacilityConditionPhoto $photo,
        FacilityConditionPhotoService $photos,
    ): StreamedResponse {
        $role = strtolower(trim((string) $request->user()?->loadMissing('role')->role?->role_name));

        // "Maintenance Staff" is the role name used by the staff navigation.
        abort_unless(
            str_contains($role, 'maintenance') || in_array($role, ['cashier', 'admin'], true),
            403,
        );

        $path = $photos->normalize((string) $photo->path);

        abort_if($path === null, 404);
        abort_unless(Storage::disk(FacilityConditionPhotoService::DISK)->exists($path), 404);

        return Storage::disk(FacilityConditionPhotoService::DISK)->response(
            $path,
            'facility-photo-'.$photo->facility_condition_photo_id.'.'.strtolower((string) pathinfo($path, PATHINFO_EXTENSION)),
            [
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
            ],
            'inline',
        );
    }
}
