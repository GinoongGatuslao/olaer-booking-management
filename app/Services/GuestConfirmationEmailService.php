<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Reservation;
use Illuminate\Support\Facades\Mail;
use Throwable;

class GuestConfirmationEmailService
{
    public function sendReservationCreated(Reservation $reservation): bool
    {
        return $this->sendReservationEmail(
            $reservation,
            'Olaer Spring Resort Reservation Confirmation',
            'created'
        );
    }

    public function sendReservationUpdated(Reservation $reservation): bool
    {
        return $this->sendReservationEmail(
            $reservation,
            'Olaer Spring Resort Updated Reservation Confirmation',
            'updated'
        );
    }

    public function sendReservationCancelled(Reservation $reservation): bool
    {
        return $this->sendReservationEmail(
            $reservation,
            'Olaer Spring Resort Reservation Cancelled',
            'cancelled'
        );
    }

    public function sendBookingSubmitted(Booking $booking): bool
    {
        try {
            $booking->loadMissing([
                'guest.address',
                'details.facility.facilityType',
                'extraGuests',
                'payments.modeOfPayment',
            ]);

            $email = trim((string) ($booking->guest->email ?? ''));

            if ($email === '') {
                return false;
            }

            Mail::send('emails.guest.booking-confirmation', [
                'booking' => $booking,
            ], function ($message) use ($email, $booking): void {
                $message
                    ->to($email)
                    ->subject('Olaer Spring Resort Booking Submitted - ' . $booking->b_ref_no);
            });

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function sendReservationEmail(Reservation $reservation, string $subject, string $action): bool
    {
        try {
            $reservation->loadMissing([
                'guest.address',
                'details.facility.facilityType',
                'details.discount',
                'extraGuests',
            ]);

            $email = trim((string) ($reservation->guest->email ?? ''));

            if ($email === '') {
                return false;
            }

            Mail::send('emails.guest.reservation-confirmation', [
                'reservation' => $reservation,
                'action' => $action,
            ], function ($message) use ($email, $subject, $reservation): void {
                $message
                    ->to($email)
                    ->subject($subject . ' - ' . $reservation->r_ref_no);
            });

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
