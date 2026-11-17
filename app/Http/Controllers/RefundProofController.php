<?php

namespace App\Http\Controllers;

use App\Models\RefundRequest;
use App\Services\GcashProofStorageService;
use App\Support\SecureFileResponse;
use Symfony\Component\HttpFoundation\Response;

class RefundProofController extends Controller
{
    public function __invoke(
        RefundRequest $refundRequest,
        GcashProofStorageService $proofStorage,
    ): Response {
        $path = $proofStorage->normalize((string) $refundRequest->refund_proof_path);

        abort_if($path === null, 404, 'Proof unavailable: this refund has no proof image.');

        try {
            $disk = $proofStorage->diskContaining($path);
        } catch (\Throwable $exception) {
            report($exception);

            abort(404, 'Proof unavailable: the proof storage disk is not configured on this server.');
        }

        abort_if($disk === null, 404, 'Proof unavailable: the proof file was not found in storage.');

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        // Payment proofs are sensitive: fresh short-lived URL, nothing cached.
        return SecureFileResponse::inline(
            $disk,
            $path,
            'refund-proof-'.$refundRequest->refund_request_id.($extension !== '' ? '.'.$extension : ''),
            cacheable: false,
        );
    }
}
