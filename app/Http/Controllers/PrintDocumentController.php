<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\EntranceSlip;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\View\View;

class PrintDocumentController extends Controller
{
    public function entranceSlip(EntranceSlip $entranceSlip): View
    {
        $entranceSlip->load([
            'createdBy.role',
            'handledBy.role',
            'guest.address',
            'details.entranceFee',
            'details.discount',
            'payments.modeOfPayment',
            'payments.user',
        ]);

        return view('print.entrance-slip', [
            'entranceSlip' => $entranceSlip,
        ]);
    }

    public function reservationConfirmation(Reservation $reservation): View
    {
        $reservation->load([
            'guest.address',
            'user.role',
            'details.facility.facilityType',
            'details.discount',
            'extraGuests',
            'payments.modeOfPayment',
        ]);

        return view('print.reservation-confirmation', [
            'reservation' => $reservation,
        ]);
    }

    public function bookingConfirmation(Booking $booking): View
    {
        $booking->load([
            'guest.address',
            'user.role',
            'reservation',
            'entranceSlip',
            'details.facility.facilityType',
            'details.discount',
            'extraGuests',
            'payments.modeOfPayment',
        ]);

        return view('print.booking-confirmation', [
            'booking' => $booking,
        ]);
    }

    public function paymentReceipt(Payment $payment): View
    {
        $payment->load([
            'modeOfPayment',
            'user.role',
            'verifier.role',
            'booking.guest',
            'reservation.guest',
            'entranceSlip.guest',
        ]);

        return view('print.payment-receipt', [
            'payment' => $payment,
        ]);
    }

    public function billingStatement(Booking $booking): View
    {
        $booking->load([
            'guest.address',
            'details.facility.facilityType',
            'details.discount',
            'extraGuests',
            'amenityRequests.details.amenity.amenityName',
            'amenityRequests.details.facility',
            'guestFines.fine.amenity.amenityName',
            'guestFines.fine.damageType',
            'guestFines.facility',
            'payments.modeOfPayment',
        ]);

        return view('print.billing-statement', [
            'booking' => $booking,
        ]);
    }
}
