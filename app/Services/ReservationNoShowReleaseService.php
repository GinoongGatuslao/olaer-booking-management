<?php

namespace App\Services;

use App\Models\Reservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReservationNoShowReleaseService
{
    public function expirePastUnpaidReservations(
        ?string $asOfDate = null,
        int $limit = 100,
    ): int {
        $date = $this->normalizeDate($asOfDate);

        if ($limit < 1) {
            throw new InvalidArgumentException(
                'Limit must be at least 1.',
            );
        }

        return DB::transaction(function () use ($date, $limit): int {
            $reservations = Reservation::query()
                ->where('status', 'Active')
                ->whereDoesntHave('payments', function ($query): void {
                    $query->whereRaw(
                        'LOWER(payment_status) = ?',
                        ['verified'],
                    );
                })
                ->whereHas('details', function ($query) use ($date): void {
                    $query->where(
                        'check_in_date',
                        '<',
                        $date,
                    );
                })
                ->with('details')
                ->orderBy('reservation_id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $reservation->update([
                    'status' => 'No-show',
                ]);
            }

            return $reservations->count();
        });
    }

    public function eligibleCount(?string $asOfDate = null): int
    {
        $date = $this->normalizeDate($asOfDate);

        return Reservation::query()
            ->where('status', 'Active')
            ->whereDoesntHave('payments', function ($query): void {
                $query->whereRaw(
                    'LOWER(payment_status) = ?',
                    ['verified'],
                );
            })
            ->whereHas('details', function ($query) use ($date): void {
                $query->where(
                    'check_in_date',
                    '<',
                    $date,
                );
            })
            ->count();
    }

    private function normalizeDate(?string $asOfDate): string
    {
        if ($asOfDate === null || trim($asOfDate) === '') {
            return Carbon::today()->toDateString();
        }

        try {
            return Carbon::parse($asOfDate)->toDateString();
        } catch (\Throwable) {
            throw new InvalidArgumentException(
                'Use a valid date for reservation no-show release.',
            );
        }
    }
}
