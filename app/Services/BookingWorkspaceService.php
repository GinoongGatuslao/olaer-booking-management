<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\FacilityInspectionRequest;
use App\Models\GuestFine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class BookingWorkspaceService
{
    public function findBooking(int $bookingId): Booking
    {
        return Booking::query()
            ->with([
                'guest.address',
                'user',
                'reservation',
                'entranceSlip',
                'details.facility.facilityType',
                'details.discount',
                'extraGuests',
                'amenityRequests.details.amenity.amenityName',
                'amenityRequests.details.facility',
                'amenityRequests.assignedTo',
                'guestFines.fine.amenity.amenityName',
                'guestFines.fine.damageType',
                'guestFines.facility',
                'guestFines.reportedBy',
                'payments.modeOfPayment',
                'payments.user',
                'payments.verifier',
            ])
            ->findOrFail($bookingId);
    }

    public function inspectionRequests(int $bookingId): Collection
    {
        return FacilityInspectionRequest::query()
            ->with([
                'bookingDetail',
                'facility',
                'requestedBy',
                'assignedTo',
                'inspection.inspectedBy',
            ])
            ->where('booking_id', $bookingId)
            ->latest('facility_inspection_request_id')
            ->get();
    }

    public function summary(Booking $booking): array
    {
        $verifiedPayments = $booking->payments->filter(
            fn ($payment): bool => strcasecmp((string) $payment->payment_status, 'Verified') === 0
        );

        return [
            'total_paid' => round((float) $verifiedPayments->sum('amount_paid'), 2),
            'amount_due' => round((float) $booking->amount_due, 2),
            'total_price' => round((float) $booking->total_price, 2),
            'facility_count' => $booking->details->count(),
            'extra_guest_count' => $booking->extraGuests->count(),
            'amenity_request_count' => $booking->amenityRequests
                ->where('amenity_request_status', '!=', 'Cancelled')
                ->count(),
            'fine_count' => $booking->guestFines->count(),
        ];
    }

    public function actions(Booking $booking): array
    {
        $detailStatuses = $booking->details
            ->pluck('status')
            ->map(fn ($status): string => strtolower((string) $status));

        $bookingStatus = strtolower((string) $booking->status);
        $inactiveStatuses = [
            'cancelled',
            'payment rejected',
            'checked-out',
        ];

        $isInactive = in_array($bookingStatus, $inactiveStatuses, true);

        return [
            'can_record_payment' => ! $isInactive && (float) $booking->amount_due > 0,
            'can_check_in' => ! $isInactive
                && (float) $booking->amount_due <= 0
                && $detailStatuses->contains('booked'),
            'can_request_amenity' => ! $isInactive
                && $detailStatuses->contains('checked-in'),
            'can_check_out' => ! $isInactive
                && $detailStatuses->contains('checked-in'),
        ];
    }

    public function bookingStatusColor(string $status): string
    {
        return match (strtolower($status)) {
            'booked', 'paid', 'checked-in' => 'green',
            'pending verification', 'partially checked-out' => 'amber',
            'checked-out' => 'blue',
            'cancelled', 'payment rejected' => 'red',
            default => 'zinc',
        };
    }

    public function detailStatusColor(string $status): string
    {
        return match (strtolower($status)) {
            'booked' => 'blue',
            'checked-in' => 'green',
            'checked-out' => 'zinc',
            'cancelled', 'payment rejected' => 'red',
            'transferred', 'extended' => 'amber',
            default => 'zinc',
        };
    }

    public function paymentStatusColor(string $status): string
    {
        return match (strtolower($status)) {
            'verified' => 'green',
            'pending' => 'amber',
            'rejected' => 'red',
            default => 'zinc',
        };
    }

    public function requestStatusColor(string $status): string
    {
        return match (strtolower($status)) {
            'delivered', 'completed' => 'green',
            'pending', 'awaiting payment' => 'amber',
            'accepted', 'delivering', 'in progress' => 'blue',
            'cancelled', 'rejected' => 'red',
            default => 'zinc',
        };
    }

    public function guestName(Booking $booking): string
    {
        return $booking->guest?->full_name
            ?? trim(implode(' ', array_filter([
                $booking->guest?->first_name,
                $booking->guest?->middle_name,
                $booking->guest?->last_name,
            ])))
            ?: 'Unknown guest';
    }

    public function guestAddress(Booking $booking): string
    {
        $address = $booking->guest?->address;

        if ($address === null) {
            return 'No address recorded';
        }

        $parts = array_filter([
            $address->barangay ?? null,
            $address->city ?? null,
            $address->province ?? null,
        ]);

        return $parts === [] ? 'No address recorded' : implode(', ', $parts);
    }

    public function amenitySummary($request): string
    {
        return $request->details
            ->map(function ($detail): string {
                $name = $detail->amenity?->amenityName?->amenity_name ?? 'Amenity';
                $quantity = (int) $detail->amenity_quantity;
                $facility = $detail->facility?->facility_name ?? 'Unassigned';

                return "{$name} × {$quantity} ({$facility})";
            })
            ->implode(', ');
    }

    public function fineDescription(GuestFine $guestFine): string
    {
        $fine = $guestFine->fine;

        if ($fine === null) {
            return 'Fine record';
        }

        if (str_contains(strtolower((string) $fine->fine_type), 'amenity')) {
            $amenity = $fine->amenity?->amenityName?->amenity_name ?? 'Amenity';
            $damage = $fine->damageType?->damage_type ?? 'Issue';

            return "{$amenity} — {$damage}";
        }

        return $fine->situational_fine
            ?: ($fine->situational_fine_description ?: 'Situational fine');
    }

    public function paymentTargetLabel($payment): string
    {
        return filled($payment->p_ref_no)
            ? (string) $payment->p_ref_no
            : 'Payment #'.$payment->payment_id;
    }

    public function formatReference(?string $reference, string $fallback): string
    {
        return filled($reference) ? (string) $reference : $fallback;
    }
}
