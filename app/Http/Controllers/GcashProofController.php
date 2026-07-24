<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\GcashProofStorageService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GcashProofController extends Controller
{
    public function __invoke(
        Payment $payment,
        GcashProofStorageService $proofStorage,
    ): StreamedResponse {
        $payment->loadMissing('modeOfPayment');

        abort_unless(
            strtolower(trim((string) $payment->modeOfPayment?->mode_of_payment))
                === 'gcash',
            404,
        );

        $path = $proofStorage->normalize(
            (string) $payment->proof_of_payment_path,
        );

        abort_if($path === null, 404);

        $disk = $proofStorage->diskContaining($path);

        abort_if($disk === null, 404);

        $extension = strtolower((string) pathinfo(
            $path,
            PATHINFO_EXTENSION,
        ));

        $safeReference = preg_replace(
            '/[^A-Za-z0-9_-]/',
            '',
            (string) $payment->p_ref_no,
        ) ?: (string) $payment->payment_id;

        $filename = 'gcash-proof-'.$safeReference
            .($extension !== '' ? '.'.$extension : '');

        return Storage::disk($disk)->response(
            $path,
            $filename,
            [
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
            ],
            'inline',
        );
    }
}
