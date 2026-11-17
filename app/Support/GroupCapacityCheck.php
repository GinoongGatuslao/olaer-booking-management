<?php

namespace App\Support;

use App\Models\FacilityProduct;

/**
 * One wording for the strict room-capacity rule, shared by the planner form
 * (live check) and FacilityRequirementService (server check).
 *
 * Only rooms are restricted (a strict-capacity, overnight product). Cottages and
 * function halls are never restricted: any number of estimated guests is accepted
 * for any quantity (e.g. 1 estimated guest can reserve 3 cottages), even if a
 * strict_maximum value happens to be stored on the product.
 */
class GroupCapacityCheck
{
    public static function message(?FacilityProduct $product, int $quantity, int $guests): ?string
    {
        if ($product === null || ! $product->isRoom() || $product->strict_maximum === null || $quantity < 1 || $guests < 1) {
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
