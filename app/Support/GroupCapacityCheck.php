<?php

namespace App\Support;

use App\Models\FacilityProduct;

/**
 * One wording for the strict room-capacity rule, shared by the planner form
 * (live check) and FacilityRequirementService (server check).
 *
 * Only products with a strict_maximum (rooms) are restricted. Cottages and
 * function halls accept any number of estimated guests and any quantity.
 */
class GroupCapacityCheck
{
    public static function message(?FacilityProduct $product, int $quantity, int $guests): ?string
    {
        if ($product === null || $product->strict_maximum === null || $quantity < 1 || $guests < 1) {
            return null;
        }

        $perRoom = (int) $product->strict_maximum;

        if ($guests > $quantity * $perRoom) {
            $total = $quantity > 1 ? " ({$quantity} rooms allow up to ".($quantity * $perRoom).')' : '';

            return "Room capacity exceeded: {$product->display_name} allows at most {$perRoom} guests per room{$total}, but {$guests} were entered.";
        }

        if ($guests < $quantity) {
            return "Every room needs at least 1 guest: {$quantity} rooms need at least {$quantity} guests, but only {$guests} were entered.";
        }

        return null;
    }
}
