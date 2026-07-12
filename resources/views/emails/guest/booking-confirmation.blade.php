@php
    $guest = $booking->guest;
    $detail = $booking->details->first();
    $facility = $detail?->facility;
    $payment = $booking->payments->first();
@endphp

<div style="font-family: Arial, sans-serif; color: #111827; line-height: 1.5; max-width: 720px; margin: 0 auto;">
    <h1 style="font-size: 22px; margin-bottom: 4px;">Olaer Spring Resort</h1>
    <h2 style="font-size: 18px; color: #1d4ed8; margin-top: 0;">Booking Submitted for GCash Verification</h2>

    <p>Hello {{ trim($guest->first_name . ' ' . $guest->last_name) }},</p>
    <p>Your booking has been submitted. The cashier still needs to verify your uploaded GCash proof before the booking becomes fully confirmed.</p>

    <table style="width: 100%; border-collapse: collapse; margin-top: 16px;">
        <tbody>
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold; width: 35%;">Booking Reference No.</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">{{ $booking->b_ref_no }}</td>
            </tr>
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold;">Guest</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">{{ trim($guest->first_name . ' ' . ($guest->middle_name ? $guest->middle_name . ' ' : '') . $guest->last_name) }}</td>
            </tr>
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold;">Facility</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">{{ $facility?->facility_name ?? 'N/A' }} @if($facility?->facilityType) ({{ $facility->facilityType->facility_type }}) @endif</td>
            </tr>
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold;">Rate Type</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">{{ $detail?->rate_type ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold;">Check-in Date</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">{{ $detail?->check_in_date ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold;">Check-out Date</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">{{ $detail?->check_out_date ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold;">Total Price</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">₱{{ number_format((float) $booking->total_price, 2) }}</td>
            </tr>
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold;">Amount Due Until Verification</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">₱{{ number_format((float) $booking->amount_due, 2) }}</td>
            </tr>
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold;">Booking Status</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">{{ $booking->status }}</td>
            </tr>
            @if ($payment)
                <tr>
                    <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold;">GCash Reference</td>
                    <td style="border: 1px solid #e5e7eb; padding: 8px;">{{ $payment->reference_number }}</td>
                </tr>
                <tr>
                    <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold;">Payment Status</td>
                    <td style="border: 1px solid #e5e7eb; padding: 8px;">{{ $payment->payment_status }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    @if ($booking->extraGuests->isNotEmpty())
        <h3 style="font-size: 16px; margin-top: 20px;">Extra Guest Names</h3>
        <ul>
            @foreach ($booking->extraGuests as $extraGuest)
                <li>{{ trim($extraGuest->first_name . ' ' . ($extraGuest->middle_name ? $extraGuest->middle_name . ' ' : '') . $extraGuest->last_name) }}</li>
            @endforeach
        </ul>
    @endif

    <p style="margin-top: 20px; font-size: 13px; color: #4b5563;">
        Please present your booking reference number during check-in. This booking remains pending until staff verifies your GCash proof.
    </p>
</div>
