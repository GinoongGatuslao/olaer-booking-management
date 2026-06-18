<?php

namespace App\Services;

use App\Models\Discount;
use App\Models\EntranceFee;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class EntranceSlipCalculator
{
    /**
     * Canonical entrance categories used by the resort workflow.
     *
     * @return array<string, array{label:string, fee_name:string, discount_column:string}>
     */
    public static function categories(): array
    {
        return [
            'adult' => [
                'label' => 'Adult',
                'fee_name' => 'Adult',
                'discount_column' => 'app_to_adult',
            ],
            'children' => [
                'label' => 'Children',
                'fee_name' => 'Children',
                'discount_column' => 'app_to_children',
            ],
            'pwd_sc' => [
                'label' => 'Senior Citizen / PWD',
                'fee_name' => 'Senior Citizen / PWD',
                'discount_column' => 'app_to_SC_PWD',
            ],
        ];
    }

    /**
     * Get active discounts that can apply to one entrance category.
     */
    public function activeDiscountsFor(string $categoryKey): EloquentCollection
    {
        $category = $this->category($categoryKey);
        $column = $category['discount_column'];

        return Discount::query()
            ->where('status', 'Active')
            ->where($column, true)
            ->where(function ($query) {
                $query->whereNull('discount_start')
                    ->orWhere('discount_start', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('discount_end')
                    ->orWhere('discount_end', '>=', now());
            })
            ->orderBy('discount_name')
            ->get();
    }

    /**
     * Calculate entrance slip totals from guest counts and optional category discounts.
     *
     * @param  array<string, int|string|null>  $counts
     * @param  array<string, int|string|null>  $discounts
     * @return array{lines: array<int, array<string, mixed>>, total_price: float, amount_due: float, total_guests: int}
     */
    public function calculate(array $counts, array $discounts = []): array
    {
        $lines = [];
        $total = 0.0;
        $totalGuests = 0;

        foreach (self::categories() as $key => $category) {
            $quantity = max(0, (int) ($counts[$key] ?? 0));
            $discountId = $discounts[$key.'_discount_id'] ?? null;
            $discountedQuantity = max(0, (int) ($discounts[$key.'_discounted_quantity'] ?? 0));

            if ($quantity === 0) {
                continue;
            }

            if ($discountedQuantity > $quantity) {
                throw new InvalidArgumentException("Discounted quantity for {$category['label']} cannot exceed its guest count.");
            }

            $fee = EntranceFee::query()
                ->where('entrance_fee_name', $category['fee_name'])
                ->firstOrFail();

            $unitPrice = (float) $fee->entrance_fee_price;
            $discount = null;
            $discountAmount = 0.0;
            $discountedUnitPrice = $unitPrice;

            if ($discountId) {
                $discount = $this->activeDiscountsFor($key)
                    ->firstWhere('discount_id', (int) $discountId);

                if (! $discount) {
                    throw new InvalidArgumentException("Selected discount is not active or not applicable to {$category['label']}.");
                }

                $discountAmount = max(0.0, min(1.0, (float) $discount->discount_amount));
                $discountedUnitPrice = round($unitPrice * (1 - $discountAmount), 2);
            } else {
                $discountedQuantity = 0;
            }

            $regularQuantity = $quantity - $discountedQuantity;
            $lineTotal = round(($regularQuantity * $unitPrice) + ($discountedQuantity * $discountedUnitPrice), 2);

            $lines[] = [
                'category_key' => $key,
                'label' => $category['label'],
                'entrance_fee_id' => $fee->entrance_fee_id,
                'entrance_fee_name' => $fee->entrance_fee_name,
                'quantity' => $quantity,
                'regular_quantity' => $regularQuantity,
                'discount_id' => $discount?->discount_id,
                'discount_name' => $discount?->discount_name,
                'discount_percent' => $discountAmount * 100,
                'discounted_quantity' => $discountedQuantity,
                'unit_price' => $unitPrice,
                'discounted_unit_price' => $discountedUnitPrice,
                'line_total' => $lineTotal,
            ];

            $total += $lineTotal;
            $totalGuests += $quantity;
        }

        return [
            'lines' => $lines,
            'total_price' => round($total, 2),
            'amount_due' => round($total, 2),
            'total_guests' => $totalGuests,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function preview(array $counts, array $discounts = []): Collection
    {
        return collect($this->calculate($counts, $discounts)['lines']);
    }

    /**
     * @return array{label:string, fee_name:string, discount_column:string}
     */
    private function category(string $categoryKey): array
    {
        $categories = self::categories();

        if (! array_key_exists($categoryKey, $categories)) {
            throw new InvalidArgumentException("Unknown entrance category: {$categoryKey}");
        }

        return $categories[$categoryKey];
    }
}
