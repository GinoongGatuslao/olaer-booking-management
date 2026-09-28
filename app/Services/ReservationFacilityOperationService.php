<?php

namespace App\Services;

use App\Models\FacilityProduct;
use App\Models\Reservation;
use App\Models\ReservationDetail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReservationFacilityOperationService
{
    public function __construct(
        private readonly DecimalMoneyService $money,
        private readonly FacilityScheduleLockService $scheduleLock,
        private readonly FacilityScheduleBlockService $scheduleBlocks,
        private readonly FacilityAvailabilityService $availability,
        private readonly ReservationQuoteService $quotes,
        private readonly FacilityProductConfigurationService $products,
        private readonly TransactionCreditService $credits,
        private readonly TransactionLedgerService $ledger,
    ) {}

    public function rescheduleDetail(
        int $reservationDetailId,
        string $newCheckInDate,
        ?string $newCheckOutDate,
        int $userId,
    ): ReservationDetail {
        return DB::transaction(function () use (
            $reservationDetailId,
            $newCheckInDate,
            $newCheckOutDate,
            $userId,
        ): ReservationDetail {
            $detail = ReservationDetail::query()
                ->with(['reservation.booking', 'facility.facilityProduct'])
                ->lockForUpdate()
                ->findOrFail($reservationDetailId);

            $this->guardEditable($detail);
            $this->guardStaff($userId);

            $this->scheduleLock->lockOne((int) $detail->facility_id);

            $oldStart = Carbon::parse($detail->check_in_date);
            $oldEnd = Carbon::parse($detail->check_out_date);
            $newStart = Carbon::parse($newCheckInDate);

            if ($newStart->lt(today())) {
                throw new InvalidArgumentException('A facility cannot be rescheduled into the past.');
            }

            $newEnd = $newCheckOutDate !== null && trim($newCheckOutDate) !== ''
                ? Carbon::parse($newCheckOutDate)
                : $newStart->copy()->addDays(max(0, $oldStart->diffInDays($oldEnd)));

            if ($newEnd->lt($newStart)) {
                throw new InvalidArgumentException('Check-out cannot be before check-in.');
            }

            $this->scheduleBlocks->synchronizeReservationDetail($detail, [
                'check_in_date' => $newStart->toDateString(),
                'check_out_date' => $newEnd->toDateString(),
            ]);

            $detail->update([
                'check_in_date' => $newStart->toDateString(),
                'check_out_date' => $newEnd->toDateString(),
                'status' => 'Rescheduled',
            ]);

            return $detail->fresh(['facility', 'reservation']);
        }, attempts: 3);
    }

    public function transferDetailToProduct(
        int $reservationDetailId,
        int $facilityProductId,
        int $userId,
    ): ReservationDetail {
        return DB::transaction(function () use (
            $reservationDetailId,
            $facilityProductId,
            $userId,
        ): ReservationDetail {
            $detail = ReservationDetail::query()
                ->with(['reservation.booking', 'facility.facilityProduct', 'discount'])
                ->lockForUpdate()
                ->findOrFail($reservationDetailId);

            $this->guardEditable($detail);
            $this->guardStaff($userId);

            $destinationProduct = FacilityProduct::query()
                ->with('productRates')
                ->findOrFail($facilityProductId);

            if (! $destinationProduct->is_active) {
                throw new InvalidArgumentException('The destination facility product is inactive.');
            }

            if ((int) $destinationProduct->facility_type_id !== (int) $detail->facility?->facility_type_id) {
                throw new InvalidArgumentException(
                    'Facility transfer must stay within the same facility type.',
                );
            }

            $rateCode = $detail->getRawOriginal('rate_code');

            if (! is_string($rateCode) || $rateCode === '') {
                throw new InvalidArgumentException(
                    'The historical reservation rate code is incomplete and must be reviewed before transfer.',
                );
            }

            $candidateIds = $this->availability->availableFacilityIds(
                $facilityProductId,
                $rateCode,
                $detail->check_in_date->toDateString(),
                $detail->check_out_date->toDateString(),
            );

            $newFacilityId = $candidateIds->first();

            if ($newFacilityId === null) {
                throw new InvalidArgumentException(
                    'No physical facility is available for that product and schedule.',
                );
            }

            $facilities = $this->scheduleLock->lockMany([
                (int) $detail->facility_id,
                (int) $newFacilityId,
            ])->load(['facilityType', 'facilityProduct.productRates'])->keyBy('facility_id');

            $oldFacility = $facilities->get((int) $detail->facility_id);
            $newFacility = $facilities->get((int) $newFacilityId);

            if ($newFacility === null || $newFacility->facility_status !== 'Available') {
                throw new InvalidArgumentException('The automatically selected facility is no longer available.');
            }

            $destinationQuote = $this->quotes->quote(
                facilityId: (int) $newFacilityId,
                rateType: (string) $detail->rate_type,
                checkInDate: $detail->check_in_date->toDateString(),
                checkOutDate: $detail->check_out_date->toDateString(),
                discountId: null,
                totalGuestCount: (int) $detail->guest_count,
            );

            if (
                $detail->unit_rate === null
                || $detail->base_price === null
                || $detail->discount_amount === null
                || $detail->extra_guest_fee === null
                || $detail->line_total === null
            ) {
                throw new InvalidArgumentException(
                    'The historical reservation pricing snapshot is incomplete and must be reviewed before transfer.',
                );
            }

            $oldUnitRate = $this->money->normalize((string) $detail->unit_rate);
            $newUnitRate = $this->money->normalize(
                (string) $destinationQuote['detail_snapshot']['unit_rate'],
            );

            if ($this->money->compare($newUnitRate, $oldUnitRate) === -1) {
                throw new InvalidArgumentException(
                    'Downgrade or cheaper facility transfers are prohibited.',
                );
            }

            $surcharge = $this->money->subtract($newUnitRate, $oldUnitRate);
            $newBasePrice = $this->money->add((string) $detail->base_price, $surcharge);
            $newLineTotal = $this->money->add((string) $detail->line_total, $surcharge);

            $snapshot = $this->products->detailSnapshot(
                facility: $newFacility,
                rate: $this->products->rateFor($newFacility, $rateCode),
                guestCount: (int) $detail->guest_count,
                basePrice: $newBasePrice,
                discountRate: (string) ($detail->discount_rate ?? '0.000000'),
                discountAmount: (string) $detail->discount_amount,
                extraGuestFee: (string) $detail->extra_guest_fee,
                lineTotal: $newLineTotal,
            );

            $this->scheduleBlocks->synchronizeReservationDetail($detail, [
                'facility_id' => (int) $newFacilityId,
                ...$snapshot,
            ]);

            $detail->update([
                'facility_id' => (int) $newFacilityId,
                'facility_product_id' => $facilityProductId,
                'rate_type' => $this->products->canonicalRateType(
                    $this->products->rateFor($newFacility, $rateCode),
                ),
                'status' => 'Transferred',
                ...$snapshot,
                'discount_id' => $detail->discount_id,
                'discount_rate' => $detail->discount_rate ?? '0.000000',
                'discount_amount' => $detail->discount_amount,
                'extra_guest_fee' => $detail->extra_guest_fee,
                'base_price' => $newBasePrice,
                'line_total' => $newLineTotal,
            ]);

            $reservation = Reservation::query()
                ->with(['details', 'payments'])
                ->lockForUpdate()
                ->findOrFail((int) $detail->reservation_id);
            $summary = $this->ledger->summaryForReservation($reservation);
            $reservation->update([
                'total_price' => $summary['total'],
                'amount_due' => $summary['balance'],
            ]);

            return $detail->fresh(['facility.facilityProduct', 'reservation']);
        }, attempts: 3);
    }

    public function cancelDetail(
        int $reservationDetailId,
        string $reason,
        int $userId,
    ): ReservationDetail {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A cancellation reason is required.');
        }

        return DB::transaction(function () use (
            $reservationDetailId,
            $reason,
            $userId,
        ): ReservationDetail {
            $detail = ReservationDetail::query()
                ->with(['reservation.booking'])
                ->lockForUpdate()
                ->findOrFail($reservationDetailId);

            $this->guardEditable($detail);
            $this->guardStaff($userId);

            $reservation = Reservation::query()
                ->with(['details', 'payments'])
                ->lockForUpdate()
                ->findOrFail((int) $detail->reservation_id);

            $activeDetails = $reservation->details
                ->reject(fn (ReservationDetail $candidate): bool => in_array(
                    (string) $candidate->status,
                    ['Cancelled', 'Converted', 'No-show'],
                    true,
                ));

            if ($activeDetails->count() <= 1) {
                throw new InvalidArgumentException(
                    'The last active facility must use the parent reservation cancellation workflow.',
                );
            }

            if ($detail->line_total === null) {
                throw new InvalidArgumentException(
                    'The facility has no immutable line total and cannot be cancelled safely.',
                );
            }

            $before = $this->ledger->summaryForReservation($reservation);
            $paidShare = '0.00';

            if (
                $this->money->compare($before['verified_payments'], '0.00') === 1
                && $this->money->compare($before['total'], '0.00') === 1
            ) {
                $ratio = bcdiv(
                    $before['verified_payments'],
                    $before['total'],
                    8,
                );
                $paidShare = $this->money->percentage(
                    (string) $detail->line_total,
                    $ratio,
                );

                if ($this->money->compare($paidShare, (string) $detail->line_total) === 1) {
                    $paidShare = $this->money->normalize((string) $detail->line_total);
                }
            }

            $this->scheduleBlocks->releaseReservationDetails([$detail]);
            $detail->update([
                'status' => 'Cancelled',
                'cancellation_reason' => $reason,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $userId,
            ]);

            if ($this->money->compare($paidShare, '0.00') === 1) {
                $this->credits->createForReservation(
                    $reservation,
                    'reservation_detail_cancellation',
                    (int) $detail->reservation_details_id,
                    $paidShare,
                    $reason,
                    $userId,
                );
            }

            $summary = $this->ledger->summaryForReservation(
                $reservation->fresh(['details', 'payments']),
            );
            $reservation->update([
                'total_price' => $summary['total'],
                'amount_due' => $summary['balance'],
            ]);

            return $detail->fresh(['reservation', 'facility', 'cancelledBy']);
        }, attempts: 3);
    }

    private function guardEditable(ReservationDetail $detail): void
    {
        $reservation = $detail->reservation;

        if ($reservation === null) {
            throw new InvalidArgumentException('Reservation detail has no parent transaction.');
        }

        if ($reservation->booking !== null || $reservation->status === 'Converted') {
            throw new InvalidArgumentException(
                'Converted reservation facilities must be managed through the booking.',
            );
        }

        if (in_array((string) $reservation->status, ['Cancelled', 'No-show'], true)) {
            throw new InvalidArgumentException('This reservation is no longer editable.');
        }

        if (in_array((string) $detail->status, ['Cancelled', 'Converted', 'No-show'], true)) {
            throw new InvalidArgumentException('This reservation facility is no longer editable.');
        }
    }

    private function guardStaff(int $userId): void
    {
        $user = \App\Models\User::query()->with('role')->findOrFail($userId);

        if ($user->status !== 'Active' || ! in_array($user->role?->role_name, ['Admin', 'Cashier'], true)) {
            throw new InvalidArgumentException(
                'Only authorized administrative or cashier staff may modify reservation facilities.',
            );
        }
    }
}
