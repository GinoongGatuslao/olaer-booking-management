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
            'adult_discount_id' =>
                $this->nullableId($data['adult_discount_id'] ?? null),
            'children_discount_id' =>
                $this->nullableId(
                    $data['children_discount_id'] ?? null,
                ),
            'pwd_sc_discount_id' =>
                $this->nullableId(
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
                'date_created' =>
                    Carbon::today()->toDateString(),
                'time_created' =>
                    Carbon::now()->format('H:i:s'),
                'total_price' =>
                    round(
                        (float) $calculation['total_price'],
                        2,
                    ),
                'amount_due' =>
                    round(
                        (float) $calculation['amount_due'],
                        2,
                    ),
                'handled_by_user_id' => null,
                'status' => 'Unpaid',
            ]);

            foreach ($calculation['lines'] as $line) {
                $slip->details()->create([
                    'entrance_fee_id' =>
                        (int) $line['entrance_fee_id'],
                    'guest_quantity' =>
                        (int) $line['quantity'],
                    'discount_id' =>
                        $line['discount_id']
                            ? (int) $line['discount_id']
                            : null,
                    'discounted_quantity' =>
                        (int) $line['discounted_quantity'],
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

    private function guardSecurityGuard(int $userId): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException(
                'A logged-in security guard is required to issue an entrance slip.',
            );
        }

        $user = User::query()
            ->with('role')
            ->findOrFail($userId);

        if ($user->role?->role_name !== 'Security Guard') {
            throw new InvalidArgumentException(
                'Only a Security Guard may issue entrance slips.',
            );
        }
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
