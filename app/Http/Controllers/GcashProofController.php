<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\GcashProofStorageService;
use Illuminate\Support\Facades\Log;
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
            'Proof unavailable: this payment is not a GCash payment.',
        );

        $path = $proofStorage->normalize(
            (string) $payment->proof_of_payment_path,
        );

        if ($path === null) {
            Log::warning('GCash proof 404: invalid stored path.', [
                'payment_id' => $payment->payment_id,
                'stored_path' => $payment->proof_of_payment_path,
            ]);

            abort(404, 'Proof unavailable: the stored proof path is empty or invalid.');
        }

        try {
            $disk = $proofStorage->diskContaining($path);
        } catch (\Throwable $exception) {
            report($exception);

            abort(404, 'Proof unavailable: the proof storage disk is not configured on this server.');
        }

        if ($disk === null) {
            Log::warning('GCash proof 404: file not found on any disk.', [
                'payment_id' => $payment->payment_id,
                'path' => $path,
            ]);

            abort(404, 'Proof unavailable: the proof file was not found in storage.');
        }

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
