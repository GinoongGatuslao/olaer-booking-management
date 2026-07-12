<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Reservation;
use Illuminate\Support\Str;

class GuestConfirmationLookupService
{
    /**
     * Look up a reservation confirmation by reference number and guest email.
     */
    public function reservation(string $referenceNo, string $email): ?Reservation
    {
        return Reservation::query()
            ->with([
                'guest.address',
                'details.facility.facilityType',
                'details.discount',
                'extraGuests',
                'payments.modeOfPayment',
            ])
            ->where('r_ref_no', trim($referenceNo))
            ->whereHas('guest', function ($query) use ($email): void {
                $query->whereRaw('LOWER(email) = ?', [Str::lower(trim($email))]);
            })
            ->first();
    }

    /**
     * Look up a booking confirmation by reference number and guest email.
     */
    public function booking(string $referenceNo, string $email): ?Booking
    {
        return Booking::query()
            ->with([
                'guest.address',
                'details.facility.facilityType',
                'details.discount',
                'extraGuests',
                'payments.modeOfPayment',
                'amenityRequests.details.amenity.amenityName',
                'guestFines.fine.damageType',
                'guestFines.fine.amenity.amenityName',
            ])
            ->where('b_ref_no', trim($referenceNo))
            ->whereHas('guest', function ($query) use ($email): void {
                $query->whereRaw('LOWER(email) = ?', [Str::lower(trim($email))]);
            })
            ->first();
    }
}
