<?php

namespace App\Support;

use App\Models\FacilityProduct;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Display-only price preview for one "facility requirement" row, so the form can
 * show the number of days (rooms only) and a subtotal before the plan is saved.
 *
 * It mirrors the server quote: rate x quantity x billable days, plus the room
 * extra-guest fee. It does NOT apply discounts (those depend on the facility
 * that gets assigned), so the saved plan total can be lower than the sum of
 * these subtotals.
 */
class GroupRowPricing
{
    /** Same fee the quote services charge per extra guest, per day. */
    public const ROOM_EXTRA_GUEST_FEE = 100.00;

    /**
     * @param  array<string, mixed>  $group
     * @return array{is_room: bool, days: int, subtotal: float}|null
     */
    public static function summarize(?FacilityProduct $product, array $group): ?array
    {
        if ($product === null) {
            return null;
        }

        $rateCode = (string) ($group['rate_code'] ?? '');

        $rate = $product->productRates->first(
            fn ($candidate): bool => $candidate->rate_code->value === $rateCode,
        );

        if ($rate === null) {
            return null;
        }

        $quantity = (int) ($group['quantity'] ?? 0);
        $guests = (int) ($group['estimated_users'] ?? 0);

        if ($quantity < 1 || $quantity > 50) {
            return null;
        }

        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($group['check_in_date'] ?? ''));
            $end = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($group['check_out_date'] ?? ''));
        } catch (Throwable) {
            return null;
        }

        if ($start === false || $end === false || $end->lessThan($start)) {
            return null;
        }

        $days = max((int) $start->diffInDays($end), 1);
        $isRoom = $product->isRoom();

        $subtotal = (float) $rate->amount * $quantity * $days;

        if ($isRoom && $product->included_guest_count !== null && $guests >= 1) {
            // Guests are spread across the units; each guest above the
            // included count costs the extra-guest fee for every day.
            $base = intdiv($guests, $quantity);
            $remainder = $guests % $quantity;
            $extraGuests = 0;

            for ($unit = 0; $unit < $quantity; $unit++) {
                $unitGuests = $base + ($unit < $remainder ? 1 : 0);
                $extraGuests += max(0, $unitGuests - (int) $product->included_guest_count);
            }

            $subtotal += self::ROOM_EXTRA_GUEST_FEE * $extraGuests * $days;
        }

        return [
            'is_room' => $isRoom,
            'days' => $days,
            'subtotal' => round($subtotal, 2),
        ];
    }
}
