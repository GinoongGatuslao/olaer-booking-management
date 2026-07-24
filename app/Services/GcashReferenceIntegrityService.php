<?php

namespace App\Services;

use App\Models\Payment;
use InvalidArgumentException;

class GcashReferenceIntegrityService
{
    public function normalize(string $referenceNumber): string
    {
        $normalized = strtoupper(
            preg_replace(
                '/\s+/',
                '',
                trim($referenceNumber),
            ) ?? '',
        );

        if ($normalized === '') {
            throw new InvalidArgumentException(
                'GCash reference number is required.',
            );
        }

        if (mb_strlen($normalized) > 50) {
            throw new InvalidArgumentException(
                'GCash reference number must not exceed 50 characters.',
            );
        }

        return $normalized;
    }

    public function assertAvailable(
        string $referenceNumber,
        ?int $ignorePaymentId = null,
    ): string {
        $normalized = $this->normalize($referenceNumber);

        $query = Payment::query()
            ->whereNotNull('reference_number')
            ->whereRaw(
                'UPPER(reference_number) = ?',
                [$normalized],
            );

        if ($ignorePaymentId !== null) {
            $query->where(
                'payment_id',
                '!=',
                $ignorePaymentId,
            );
        }

        if ($query->exists()) {
            throw new InvalidArgumentException(
                'This GCash reference number has already been used.',
            );
        }

        return $normalized;
    }
}
