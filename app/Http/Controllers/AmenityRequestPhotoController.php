<?php

namespace App\Http\Controllers;

use App\Models\AmenityRequestPhoto;
use App\Services\AmenityDeliveryPhotoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AmenityRequestPhotoController extends Controller
{
    public function __invoke(
        Request $request,
        AmenityRequestPhoto $photo,
        AmenityDeliveryPhotoService $photos,
    ): StreamedResponse {
        $role = strtolower(trim((string) $request->user()?->loadMissing('role')->role?->role_name));

        abort_unless(
            str_contains($role, 'maintenance') || in_array($role, ['cashier', 'admin'], true),
            403,
        );

        $path = $photos->normalize((string) $photo->path);

        abort_if($path === null, 404);
        abort_unless(Storage::disk(AmenityDeliveryPhotoService::DISK)->exists($path), 404);

        return Storage::disk(AmenityDeliveryPhotoService::DISK)->response(
            $path,
            'delivery-proof-'.$photo->amenity_request_photo_id.'.'.strtolower((string) pathinfo($path, PATHINFO_EXTENSION)),
            [
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
            ],
            'inline',
        );
    }
}
