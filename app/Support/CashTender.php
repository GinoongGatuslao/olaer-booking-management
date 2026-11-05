<?php

namespace App\Support;

use App\Models\ModeOfPayment;
use App\Services\DecimalMoneyService;
use InvalidArgumentException;

/**
 * Cash received from the guest for a cash payment.
 * Only cash payments need it; the change is cash paid minus amount paid and is
 * calculated when needed, not stored. Other payment modes store nothing.
 */
final class CashTender
{
    public static function isCash(?ModeOfPayment $mode): bool
    {
        return $mode !== null
            && strtolower(trim((string) $mode->mode_of_payment)) === 'cash';
    }

    /**
     * The validated cash amount to store, or null when the payment is not cash.
     */
    public static function resolve(?ModeOfPayment $mode, string $amountPaid, mixed $cashPaid): ?string
    {
        if (! self::isCash($mode)) {
            return null;
        }

        if (! is_numeric($cashPaid)) {
            throw new InvalidArgumentException('Enter the cash received from the guest.');
        }

        $money = app(DecimalMoneyService::class);
        $cash = $money->normalize($cashPaid);

        if ($money->compare($cash, $amountPaid) === -1) {
            throw new InvalidArgumentException(sprintf(
                'Cash paid must be at least the amount paid (₱%s).',
                number_format((float) $amountPaid, 2),
            ));
        }

        return $cash;
    }
}
