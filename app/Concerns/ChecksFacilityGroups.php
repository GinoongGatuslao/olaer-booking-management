<?php

namespace App\Concerns;

use App\FacilitySchedulePolicy;
use App\Models\FacilityProduct;
use App\Services\FacilityAvailabilityService;
use App\Support\GroupCapacityCheck;

/**
 * Live checks for the "facility groups" form (cashier reservation / booking create).
 *
 * The component must have public $groups and $roomOccupants, and productFor(int $index).
 * Fields only turn red; every error text is returned by bannerErrors() for the toast.
 */
trait ChecksFacilityGroups
{
    /** @var array<string, int|null> */
    private array $availabilityMemo = [];

    /** @return array<int, string> */
    public function bannerErrors(): array
    {
        $issues = $this->groupIssues();
        $messages = [];

        foreach ($this->getErrorBag()->messages() as $key => $list) {
            if ($issues !== [] && str_starts_with($key, 'roomOccupants.')) {
                continue; // the occupants card is hidden while a facility row has an error
            }

            $prefix = '';

            if (preg_match('/^groups\.(\d+)\./', $key, $match)) {
                $prefix = 'Facility group '.($match[1] + 1).': ';
            } elseif (preg_match('/^roomOccupants\.(\d+)\.(\d+)\./', $key, $match)) {
                $prefix = 'Room '.($match[1] + 1).', occupant '.($match[2] + 1).': ';
            }

            foreach ($list as $message) {
                // One general line instead of one per missing occupant name.
                $messages[] = $message === 'Room Occupants must be filled out.' ? $message : $prefix.$message;
            }
        }

        foreach ($issues as $index => $fields) {
            foreach ($fields as $message) {
                $messages[] = 'Facility group '.($index + 1).': '.$message;
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * Live problems per facility row: [rowIndex => ['quantity' => msg, 'estimated_users' => msg]].
     *
     * @return array<int, array<string, string>>
     */
    public function groupIssues(): array
    {
        $issues = [];

        foreach ($this->groups as $index => $group) {
            $product = $this->productFor($index);

            if ($product === null) {
                continue;
            }

            $capacity = GroupCapacityCheck::message($product, (int) ($group['quantity'] ?? 0), (int) ($group['estimated_users'] ?? 0));

            if ($capacity !== null) {
                $issues[$index]['estimated_users'] = $capacity;
            }

            if (($group['rate_code'] ?? '') === '') {
                continue;
            }

            $availability = $this->availabilityFor($index);

            if ($availability !== null && $availability['available'] !== null && $availability['requested'] > $availability['available']) {
                $issues[$index]['quantity'] = $availability['available'] === 0
                    ? "No {$product->display_name} is available for the selected schedule and date, but {$availability['requested']} requested."
                    : "Only {$availability['available']} {$product->display_name} available for the selected schedule and date, but {$availability['requested']} requested.";
            }
        }

        return $issues;
    }

    public function showRoomOccupants(): bool
    {
        if ($this->roomOccupants === [] || $this->groupIssues() !== []) {
            return false;
        }

        foreach (array_keys($this->getErrorBag()->messages()) as $key) {
            if (str_starts_with($key, 'groups') || $key === 'plan' || $key === 'partyCount') {
                return false;
            }
        }

        return true;
    }

    public function capacityLabel(?FacilityProduct $product): ?string
    {
        if ($product === null) {
            return null;
        }

        if ($product->isRoom() && $product->strict_maximum !== null) {
            $label = 'Up to '.$product->strict_maximum.' guests per room';
            $included = $product->included_guest_count;

            if ($included !== null && $included >= 0 && $included < $product->strict_maximum) {
                $fee = \App\Support\GroupRowPricing::ROOM_EXTRA_GUEST_FEE;
                $fee = number_format($fee, fmod($fee, 1.0) === 0.0 ? 0 : 2);

                $label .= " · first {$included} guests free, ₱{$fee} per extra guest per night";
            }

            return $label;
        }

        $min = $product->suggested_minimum;
        $max = $product->suggested_maximum;

        return match (true) {
            $min !== null && $max !== null && $min !== $max => "Capacity: {$min}–{$max} guests (recommended)",
            $max !== null => "Capacity: up to {$max} guests (recommended)",
            $min !== null => "Capacity: {$min}+ guests (recommended)",
            default => 'Capacity is a recommendation',
        };
    }

    /**
     * Shown as soon as a facility and a schedule are chosen. `available` is null when it cannot be counted yet.
     *
     * @return array{available: int|null, requested: int}|null
     */
    public function availabilityFor(int $index): ?array
    {
        $group = $this->groups[$index] ?? [];
        $product = $this->productFor($index);
        $rateCode = (string) ($group['rate_code'] ?? '');

        if ($product === null || $rateCode === '') {
            return null;
        }

        $checkIn = (string) ($group['check_in_date'] ?? '');
        $checkOut = (string) ($group['check_out_date'] ?? '');
        $key = md5(json_encode([$product->facility_product_id, $rateCode, $checkIn, $checkOut]));

        if (! array_key_exists($key, $this->availabilityMemo)) {
            $this->availabilityMemo[$key] = $this->countAvailable($product, $rateCode, $checkIn, $checkOut);
        }

        return ['available' => $this->availabilityMemo[$key], 'requested' => max(1, (int) ($group['quantity'] ?? 1))];
    }

    private function countAvailable(FacilityProduct $product, string $rateCode, string $checkIn, string $checkOut): ?int
    {
        try {
            if (! validator(['d' => $checkIn], ['d' => 'date_format:Y-m-d'])->passes()) {
                return null;
            }

            $start = \Carbon\CarbonImmutable::parse($checkIn);
            $end = $product->schedule_policy === FacilitySchedulePolicy::Overnight
                ? (validator(['d' => $checkOut], ['d' => 'date_format:Y-m-d'])->passes() && $checkOut > $checkIn
                    ? \Carbon\CarbonImmutable::parse($checkOut)
                    : $start->addDay())
                : $start;

            return app(FacilityAvailabilityService::class)->availabilityCount(
                (int) $product->facility_product_id,
                $rateCode,
                $start->toDateString(),
                $end->toDateString(),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** Quantity and estimated guests: whole numbers of at least 1 (a blank stays blank while typing). */
    private function sanitizeWholeNumbers(bool $fillBlanks = false): void
    {
        foreach ($this->groups as $index => $group) {
            foreach (['quantity', 'estimated_users'] as $field) {
                $digits = substr(preg_replace('/\D/', '', (string) ($group[$field] ?? '')), 0, 9);

                $this->groups[$index][$field] = $digits === ''
                    ? ($fillBlanks ? 1 : '')
                    : max(1, (int) $digits);
            }
        }
    }

    /** @return array<string, string> */
    protected function facilityGroupMessages(): array
    {
        return [
            'groups.*.facility_product_id.required' => 'Choose a facility.',
            'groups.*.facility_product_id.integer' => 'Choose a facility.',
            'groups.*.facility_product_id.exists' => 'The chosen facility is not available. Choose another.',
            'groups.*.rate_code.required' => 'Choose a schedule.',
            'groups.*.quantity.required' => 'Enter a quantity (at least 1).',
            'groups.*.quantity.integer' => 'Quantity must be a whole number.',
            'groups.*.quantity.min' => 'Quantity must be at least 1.',
            'groups.*.quantity.max' => 'Quantity can be at most 50.',
            'groups.*.estimated_users.required' => 'Enter the estimated number of guests.',
            'groups.*.estimated_users.integer' => 'Estimated guests must be a whole number.',
            'groups.*.estimated_users.min' => 'There must be at least 1 estimated guest.',
            'groups.*.check_in_date.required' => 'Choose a check-in date.',
            'groups.*.check_in_date.date_format' => 'Choose a valid check-in date.',
            'groups.*.check_in_date.after_or_equal' => 'The check-in date cannot be in the past.',
            'groups.*.check_out_date.required' => 'Choose a check-out date.',
            'groups.*.check_out_date.date_format' => 'Choose a valid check-out date.',
            'firstName.required' => 'Enter the first name.',
            'lastName.required' => 'Enter the last name.',
            'email.required' => 'Enter the email address.',
            'email.email' => 'Enter a valid email address.',
            'email.regex' => 'Enter a valid email address.',
            'contactNo.required' => 'Enter the contact number.',
            'province.required' => 'Choose a province.',
            'city.required' => 'Choose a city or municipality.',
            'roomOccupants.*.*.first_name.required' => 'Room Occupants must be filled out.',
            'roomOccupants.*.*.last_name.required' => 'Room Occupants must be filled out.',
        ];
    }
}
