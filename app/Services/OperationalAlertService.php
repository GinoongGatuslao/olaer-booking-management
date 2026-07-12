<?php

namespace App\Services;

use App\Models\AmenityRequest;
use App\Models\BookingDetail;
use App\Models\FacilityInspection;
use App\Models\Payment;
use Illuminate\Support\Carbon;

class OperationalAlertService
{
    /**
     * Alerts for cashier operations.
     *
     * These are live operational alerts, not historical messages. They are calculated from
     * current booking/payment/check-in records so they do not become stale.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cashierAlerts(): array
    {
        $alerts = [];

        foreach ($this->pendingGcashProofs() as $alert) {
            $alerts[] = $alert;
        }

        foreach ($this->upcomingBookings() as $alert) {
            $alerts[] = $alert;
        }

        foreach ($this->cottageRentalEndingSoon() as $alert) {
            $alerts[] = $alert;
        }

        foreach ($this->checkedInBookingsWithUnpaidBalance() as $alert) {
            $alerts[] = $alert;
        }

        return $this->sortAlerts($alerts);
    }

    /**
     * Alerts for maintenance operations.
     *
     * @return array<int, array<string, mixed>>
     */
    public function maintenanceAlerts(): array
    {
        $alerts = [];

        foreach ($this->pendingAmenityRequests() as $alert) {
            $alerts[] = $alert;
        }

        foreach ($this->checkedInFacilitiesNeedingInspection() as $alert) {
            $alerts[] = $alert;
        }

        return $this->sortAlerts($alerts);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pendingGcashProofs(): array
    {
        $payments = Payment::query()
            ->with(['booking.guest', 'modeOfPayment'])
            ->where('payment_status', 'Pending')
            ->whereNotNull('booking_id')
            ->whereNotNull('proof_of_payment_path')
            ->latest('payment_id')
            ->limit(10)
            ->get();

        $alerts = [];

        foreach ($payments as $payment) {
            $booking = $payment->booking;

            $alerts[] = [
                'type' => 'gcash_verification',
                'severity' => 'warning',
                'title' => 'Pending GCash verification',
                'message' => sprintf(
                    '%s uploaded proof for booking %s. Verify before the booking becomes valid.',
                    $this->guestName($booking?->guest),
                    $booking?->b_ref_no ?? 'N/A'
                ),
                'time_label' => optional($payment->created_at)->format('M d, Y h:i A') ?? 'Pending',
                'route_name' => 'cashier.gcash-verifications',
                'action_label' => 'Review proof',
                'sort_at' => optional($payment->created_at)->timestamp ?? now()->timestamp,
            ];
        }

        return $alerts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function upcomingBookings(): array
    {
        $today = Carbon::today();
        $tomorrow = Carbon::today()->addDay();

        $details = BookingDetail::query()
            ->with(['booking.guest', 'facility.facilityType'])
            ->whereIn('status', ['Booked', 'Active'])
            ->whereDate('check_in_date', '>=', $today->toDateString())
            ->whereDate('check_in_date', '<=', $tomorrow->toDateString())
            ->orderBy('check_in_date')
            ->orderBy('check_in_time')
            ->limit(15)
            ->get();

        $alerts = [];

        foreach ($details as $detail) {
            $date = $this->dateLabel($detail->check_in_date);
            $time = $this->timeLabel($detail->check_in_time);

            $alerts[] = [
                'type' => 'upcoming_booking',
                'severity' => 'info',
                'title' => 'Upcoming booking',
                'message' => sprintf(
                    '%s is scheduled for %s at %s. Facility: %s.',
                    $this->guestName($detail->booking?->guest),
                    $date,
                    $time,
                    $detail->facility?->facility_name ?? 'N/A'
                ),
                'time_label' => trim($date.' '.$time),
                'route_name' => 'cashier.check-ins',
                'action_label' => 'Go to check-in',
                'sort_at' => $this->sortTimestamp($detail->check_in_date, $detail->check_in_time),
            ];
        }

        return $alerts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cottageRentalEndingSoon(): array
    {
        $now = Carbon::now();
        $windowEnd = Carbon::now()->addHours(2);

        $details = BookingDetail::query()
            ->with(['booking.guest', 'facility.facilityType'])
            ->whereIn('status', ['Checked-in', 'Checked In'])
            ->whereDate('check_out_date', $now->toDateString())
            ->whereHas('facility.facilityType', function ($query): void {
                $query->where('facility_type', 'Cottage');
            })
            ->orderBy('check_out_date')
            ->orderBy('check_in_time')
            ->limit(20)
            ->get();

        $alerts = [];

        foreach ($details as $detail) {
            $endAt = $this->expectedEndAt($detail);

            if ($endAt === null) {
                continue;
            }

            if ($endAt->lt($now) || $endAt->gt($windowEnd)) {
                continue;
            }

            $alerts[] = [
                'type' => 'rental_period_ending',
                'severity' => 'warning',
                'title' => 'Cottage rental ending soon',
                'message' => sprintf(
                    '%s in %s is approaching end time at %s. Prepare extension/check-out handling.',
                    $this->guestName($detail->booking?->guest),
                    $detail->facility?->facility_name ?? 'N/A',
                    $endAt->format('h:i A')
                ),
                'time_label' => $endAt->format('M d, Y h:i A'),
                'route_name' => 'cashier.bookings',
                'action_label' => 'View bookings',
                'sort_at' => $endAt->timestamp,
            ];
        }

        return $alerts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function checkedInBookingsWithUnpaidBalance(): array
    {
        $details = BookingDetail::query()
            ->with(['booking.guest', 'facility'])
            ->whereIn('status', ['Checked-in', 'Checked In'])
            ->whereHas('booking', function ($query): void {
                $query->where('amount_due', '>', 0);
            })
            ->orderByDesc('booking_details_id')
            ->limit(15)
            ->get();

        $alerts = [];

        foreach ($details as $detail) {
            $booking = $detail->booking;

            $alerts[] = [
                'type' => 'unpaid_checkout_balance',
                'severity' => 'danger',
                'title' => 'Unpaid balance before check-out',
                'message' => sprintf(
                    '%s has ₱%s unpaid on booking %s. Check-out is blocked until paid.',
                    $this->guestName($booking?->guest),
                    number_format((float) ($booking?->amount_due ?? 0), 2),
                    $booking?->b_ref_no ?? 'N/A'
                ),
                'time_label' => 'Balance due',
                'route_name' => 'cashier.payments',
                'action_label' => 'Record payment',
                'sort_at' => now()->timestamp,
            ];
        }

        return $alerts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pendingAmenityRequests(): array
    {
        $requests = AmenityRequest::query()
            ->with(['booking.guest', 'details.facility', 'details.amenity.amenityName'])
            ->whereIn('amenity_request_status', ['Pending'])
            ->latest('amenity_request_id')
            ->limit(15)
            ->get();

        $alerts = [];

        foreach ($requests as $request) {
            $firstDetail = $request->details->first();
            $itemCount = $request->details->sum('amenity_quantity');

            $alerts[] = [
                'type' => 'pending_amenity_request',
                'severity' => 'warning',
                'title' => 'Pending amenity request',
                'message' => sprintf(
                    '%s requested %d item(s) for %s. Accept before delivery.',
                    $this->guestName($request->booking?->guest),
                    (int) $itemCount,
                    $firstDetail?->facility?->facility_name ?? 'assigned facility'
                ),
                'time_label' => optional($request->created_at)->format('M d, Y h:i A') ?? $this->dateLabel($request->date_created),
                'route_name' => 'maintenance.amenity-requests',
                'action_label' => 'Handle request',
                'sort_at' => optional($request->created_at)->timestamp ?? now()->timestamp,
            ];
        }

        return $alerts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function checkedInFacilitiesNeedingInspection(): array
    {
        $inspectedDetailIds = FacilityInspection::query()
            ->pluck('booking_details_id')
            ->filter()
            ->values()
            ->all();

        $details = BookingDetail::query()
            ->with(['booking.guest', 'facility'])
            ->whereIn('status', ['Checked-in', 'Checked In'])
            ->when(count($inspectedDetailIds) > 0, function ($query) use ($inspectedDetailIds): void {
                $query->whereNotIn('booking_details_id', $inspectedDetailIds);
            })
            ->orderByDesc('booking_details_id')
            ->limit(20)
            ->get();

        $alerts = [];

        foreach ($details as $detail) {
            $alerts[] = [
                'type' => 'inspection_needed',
                'severity' => 'info',
                'title' => 'Facility needs inspection',
                'message' => sprintf(
                    '%s is checked in at %s. Inspect before cashier can check out.',
                    $this->guestName($detail->booking?->guest),
                    $detail->facility?->facility_name ?? 'N/A'
                ),
                'time_label' => 'Checked-in',
                'route_name' => 'maintenance.facility-inspections',
                'action_label' => 'Inspect facility',
                'sort_at' => now()->timestamp,
            ];
        }

        return $alerts;
    }

    /**
     * @param array<int, array<string, mixed>> $alerts
     * @return array<int, array<string, mixed>>
     */
    private function sortAlerts(array $alerts): array
    {
        usort($alerts, function (array $left, array $right): int {
            $severityOrder = [
                'danger' => 1,
                'warning' => 2,
                'info' => 3,
                'success' => 4,
            ];

            $leftSeverity = $severityOrder[$left['severity'] ?? 'info'] ?? 9;
            $rightSeverity = $severityOrder[$right['severity'] ?? 'info'] ?? 9;

            if ($leftSeverity === $rightSeverity) {
                return ($left['sort_at'] ?? 0) <=> ($right['sort_at'] ?? 0);
            }

            return $leftSeverity <=> $rightSeverity;
        });

        return array_values($alerts);
    }

    private function guestName(mixed $guest): string
    {
        if (! $guest) {
            return 'Guest';
        }

        $name = trim((string) ($guest->full_name ?? ''));

        return $name !== '' ? $name : 'Guest #'.$guest->guest_id;
    }

    private function dateLabel(mixed $value): string
    {
        if (blank($value)) {
            return 'No date';
        }

        try {
            return Carbon::parse($value)->format('M d, Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function timeLabel(mixed $value): string
    {
        if (blank($value)) {
            return 'time not set';
        }

        try {
            return Carbon::parse((string) $value)->format('h:i A');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function sortTimestamp(mixed $date, mixed $time = null): int
    {
        try {
            $datePart = Carbon::parse($date)->format('Y-m-d');
            $timePart = blank($time) ? '00:00:00' : Carbon::parse((string) $time)->format('H:i:s');

            return Carbon::parse($datePart.' '.$timePart)->timestamp;
        } catch (\Throwable) {
            return now()->timestamp;
        }
    }

    private function expectedEndAt(BookingDetail $detail): ?Carbon
    {
        if (blank($detail->check_out_date)) {
            return null;
        }

        $rateType = strtolower((string) $detail->rate_type);
        $defaultTime = str_contains($rateType, 'day') ? '18:00:00' : '23:59:00';

        try {
            return Carbon::parse(Carbon::parse($detail->check_out_date)->format('Y-m-d').' '.$defaultTime);
        } catch (\Throwable) {
            return null;
        }
    }
}
