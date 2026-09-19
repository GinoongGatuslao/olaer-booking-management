<?php

namespace App\Services;

use App\Models\EntranceSlip;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EntranceSlipWorkflowService
{
    public function __construct(
        private readonly EntranceSlipCalculator $calculator,
    ) {}

    /** @param array<string, mixed> $data */
    public function issue(array $data): EntranceSlip
    {
        $securityUserId = (int) ($data['user_id'] ?? 0);
        $this->guardSecurityGuard($securityUserId);

        $counts = [
            'adult' => max(0, (int) ($data['adult_count'] ?? 0)),
            'children' => max(
                0,
                (int) ($data['children_count'] ?? 0),
            ),
            'pwd_sc' => max(
                0,
                (int) ($data['pwd_sc_count'] ?? 0),
            ),
        ];

        $maleCount = max(
            0,
            (int) ($data['male_count'] ?? 0),
        );

        $femaleCount = max(
            0,
            (int) ($data['female_count'] ?? 0),
        );

        $touristCount = max(
            0,
            (int) ($data['tourist_count'] ?? 0),
        );

        $totalGuests = array_sum($counts);
        $genderTotal = $maleCount + $femaleCount;

        if ($totalGuests < 1) {
            throw new InvalidArgumentException(
                'At least one guest is required to create an entrance slip.',
            );
        }

        if ($genderTotal !== $totalGuests) {
            throw new InvalidArgumentException(
                'Male and female counts must equal the total entrance guest count.',
            );
        }

        if ($touristCount > $totalGuests) {
            throw new InvalidArgumentException(
                'Tourist count cannot exceed the total entrance guest count.',
            );
        }

        $discounts = [
            'adult_discount_id' => $this->nullableId($data['adult_discount_id'] ?? null),
            'children_discount_id' => $this->nullableId(
                $data['children_discount_id'] ?? null,
            ),
            'pwd_sc_discount_id' => $this->nullableId(
                $data['pwd_sc_discount_id'] ?? null,
            ),
            'adult_discounted_quantity' => max(
                0,
                (int) (
                    $data['adult_discounted_quantity'] ?? 0
                ),
            ),
            'children_discounted_quantity' => max(
                0,
                (int) (
                    $data['children_discounted_quantity'] ?? 0
                ),
            ),
            'pwd_sc_discounted_quantity' => max(
                0,
                (int) (
                    $data['pwd_sc_discounted_quantity'] ?? 0
                ),
            ),
        ];

        $calculation = $this->calculator->calculate(
            $counts,
            $discounts,
        );

        if (
            round((float) $calculation['amount_due'], 2)
            <= 0
        ) {
            throw new InvalidArgumentException(
                'The entrance slip total must be greater than zero.',
            );
        }

        return DB::transaction(function () use (
            $securityUserId,
            $counts,
            $maleCount,
            $femaleCount,
            $touristCount,
            $calculation,
        ): EntranceSlip {
            $slip = EntranceSlip::query()->create([
                'no_of_adult' => $counts['adult'],
                'no_of_children' => $counts['children'],
                'no_of_PWD_SC' => $counts['pwd_sc'],
                'no_of_Male' => $maleCount,
                'no_of_Female' => $femaleCount,
                'no_of_Tourist' => $touristCount,
                'created_by_user_id' => $securityUserId,
                'guest_id' => null,
                'date_created' => Carbon::today()->toDateString(),
                'time_created' => Carbon::now()->format('H:i:s'),
                'total_price' => round(
                    (float) $calculation['total_price'],
                    2,
                ),
                'amount_due' => round(
                    (float) $calculation['amount_due'],
                    2,
                ),
                'handled_by_user_id' => null,
                'status' => 'Unpaid',
            ]);

            foreach ($calculation['lines'] as $line) {
                $slip->details()->create([
                    'entrance_fee_id' => (int) $line['entrance_fee_id'],
                    'unit_rate_snapshot' => number_format((float) $line['unit_price'], 2, '.', ''),
                    'guest_quantity' => (int) $line['quantity'],
                    'discount_id' => $line['discount_id']
                            ? (int) $line['discount_id']
                            : null,
                    'discount_rate_snapshot' => number_format((float) $line['discount_percent'] / 100, 6, '.', ''),
                    'discounted_quantity' => (int) $line['discounted_quantity'],
                    'line_total_snapshot' => number_format((float) $line['line_total'], 2, '.', ''),
                ]);
            }

            return $slip->fresh([
                'details.entranceFee',
                'details.discount',
                'createdBy',
                'handledBy',
                'payments',
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateBeforeAdmission(int $entranceSlipId, array $data): EntranceSlip
    {
        $securityUserId = (int) ($data['user_id'] ?? 0);
        $this->guardSecurityGuard($securityUserId);
        $payload = $this->calculationPayload($data);

        return DB::transaction(function () use ($entranceSlipId, $securityUserId, $payload): EntranceSlip {
            $slip = EntranceSlip::query()
                ->with('details')
                ->whereKey($entranceSlipId)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $slip->created_by_user_id !== $securityUserId) {
                throw new InvalidArgumentException('Only the security guard who created this entrance slip may edit it.');
            }

            if ($slip->admitted_at !== null || $slip->status !== 'Unpaid' || $slip->payments()->exists()) {
                throw new InvalidArgumentException('Paid or admitted entrance slips can no longer be edited.');
            }

            $slip->update([
                'no_of_adult' => $payload['counts']['adult'],
                'no_of_children' => $payload['counts']['children'],
                'no_of_PWD_SC' => $payload['counts']['pwd_sc'],
                'no_of_Male' => $payload['male_count'],
                'no_of_Female' => $payload['female_count'],
                'no_of_Tourist' => $payload['tourist_count'],
                'total_price' => number_format((float) $payload['calculation']['total_price'], 2, '.', ''),
                'amount_due' => number_format((float) $payload['calculation']['amount_due'], 2, '.', ''),
            ]);
            $slip->details()->delete();

            foreach ($payload['calculation']['lines'] as $line) {
                $slip->details()->create([
                    'entrance_fee_id' => (int) $line['entrance_fee_id'],
                    'unit_rate_snapshot' => number_format((float) $line['unit_price'], 2, '.', ''),
                    'guest_quantity' => (int) $line['quantity'],
                    'discount_id' => $line['discount_id'] ? (int) $line['discount_id'] : null,
                    'discount_rate_snapshot' => number_format((float) $line['discount_percent'] / 100, 6, '.', ''),
                    'discounted_quantity' => (int) $line['discounted_quantity'],
                    'line_total_snapshot' => number_format((float) $line['line_total'], 2, '.', ''),
                ]);
            }

            return $slip->fresh(['details.entranceFee', 'details.discount', 'createdBy', 'payments']);
        }, attempts: 3);
    }

    public function admit(int $entranceSlipId, int $cashierUserId): EntranceSlip
    {
        $this->guardCashier($cashierUserId);

        return DB::transaction(function () use ($entranceSlipId, $cashierUserId): EntranceSlip {
            $slip = EntranceSlip::query()->whereKey($entranceSlipId)->lockForUpdate()->firstOrFail();

            if ($slip->admitted_at !== null) {
                throw new InvalidArgumentException('This entrance slip has already been admitted.');
            }

            if ($slip->status !== 'Paid' || bccomp((string) $slip->amount_due, '0.00', 2) !== 0) {
                throw new InvalidArgumentException('Only a fully paid entrance slip may be admitted.');
            }

            $slip->update([
                'admitted_by_user_id' => $cashierUserId,
                'admitted_at' => now(),
            ]);

            return $slip->fresh(['createdBy', 'handledBy', 'admittedBy', 'details.entranceFee', 'payments']);
        }, attempts: 3);
    }

    private function guardSecurityGuard(int $userId): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException(
                'A logged-in security guard is required to issue an entrance slip.',
            );
        }

        $isSecurityGuard = User::query()
            ->whereKey($userId)
            ->where('status', 'Active')
            ->whereHas('role', fn ($query) => $query->where('role_name', 'Security Guard'))
            ->exists();

        if (! $isSecurityGuard) {
            throw new InvalidArgumentException(
                'Only a Security Guard may issue entrance slips.',
            );
        }
    }

    private function guardCashier(int $userId): void
    {
        $isCashier = User::query()
            ->whereKey($userId)
            ->where('status', 'Active')
            ->whereHas('role', fn ($query) => $query->where('role_name', 'Cashier'))
            ->exists();

        if (! $isCashier) {
            throw new InvalidArgumentException('Only an active Cashier may admit entrance slips.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{counts: array{adult: int, children: int, pwd_sc: int}, male_count: int, female_count: int, tourist_count: int, calculation: array<string, mixed>}
     */
    private function calculationPayload(array $data): array
    {
        $counts = [
            'adult' => max(0, (int) ($data['adult_count'] ?? 0)),
            'children' => max(0, (int) ($data['children_count'] ?? 0)),
            'pwd_sc' => max(0, (int) ($data['pwd_sc_count'] ?? 0)),
        ];
        $maleCount = max(0, (int) ($data['male_count'] ?? 0));
        $femaleCount = max(0, (int) ($data['female_count'] ?? 0));
        $touristCount = max(0, (int) ($data['tourist_count'] ?? 0));
        $totalGuests = array_sum($counts);

        if ($totalGuests < 1) {
            throw new InvalidArgumentException('At least one guest is required to create an entrance slip.');
        }

        if ($maleCount + $femaleCount !== $totalGuests) {
            throw new InvalidArgumentException('Male and female counts must equal the total entrance guest count.');
        }

        if ($touristCount > $totalGuests) {
            throw new InvalidArgumentException('Tourist count cannot exceed the total entrance guest count.');
        }

        $calculation = $this->calculator->calculate($counts, [
            'adult_discount_id' => $this->nullableId($data['adult_discount_id'] ?? null),
            'children_discount_id' => $this->nullableId($data['children_discount_id'] ?? null),
            'pwd_sc_discount_id' => $this->nullableId($data['pwd_sc_discount_id'] ?? null),
            'adult_discounted_quantity' => max(0, (int) ($data['adult_discounted_quantity'] ?? 0)),
            'children_discounted_quantity' => max(0, (int) ($data['children_discounted_quantity'] ?? 0)),
            'pwd_sc_discounted_quantity' => max(0, (int) ($data['pwd_sc_discounted_quantity'] ?? 0)),
        ]);

        if ((float) $calculation['amount_due'] <= 0) {
            throw new InvalidArgumentException('The entrance slip total must be greater than zero.');
        }

        return [
            'counts' => $counts,
            'male_count' => $maleCount,
            'female_count' => $femaleCount,
            'tourist_count' => $touristCount,
            'calculation' => $calculation,
        ];
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
