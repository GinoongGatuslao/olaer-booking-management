@php
    $guest = $reservation->guest;
    $detail = $reservation->details->first();
    $facility = $detail?->facility;
    $discount = $detail?->discount;
@endphp

<div style="font-family: Arial, sans-serif; color: #111827; line-height: 1.5; max-width: 720px; margin: 0 auto;">
    <h1 style="font-size: 22px; margin-bottom: 4px;">Olaer Spring Resort</h1>

    @if ($action === 'cancelled')
        <h2 style="font-size: 18px; color: #991b1b; margin-top: 0;">Reservation Cancelled</h2>
    @elseif ($action === 'updated')
        <h2 style="font-size: 18px; color: #1d4ed8; margin-top: 0;">Updated Reservation Confirmation</h2>
    @else
        <h2 style="font-size: 18px; color: #047857; margin-top: 0;">Reservation Confirmation</h2>
    @endif

    <p>Hello {{ trim($guest->first_name . ' ' . $guest->last_name) }},</p>

    @if ($action === 'cancelled')
        <p>Your reservation has been cancelled. Keep this email for your records.</p>
    @else
        <p>Your reservation details are below. Please present your reference number to the cashier for verification.</p>
    @endif

    <table style="width: 100%; border-collapse: collapse; margin-top: 16px;">
        <tbody>
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold; width: 35%;">Reference No.</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">{{ $reservation->r_ref_no }}</td>
            </tr>
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold;">Guest</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">{{ trim($guest->first_name . ' ' . ($guest->middle_name ? $guest->middle_name . ' ' : '') . $guest->last_name) }}</td>
            </tr>
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold;">Contact No.</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">{{ $guest->contact_no }}</td>
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
            @if ($discount)
                <tr>
                    <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold;">Discount</td>
                    <td style="border: 1px solid #e5e7eb; padding: 8px;">{{ $discount->discount_name }} ({{ number_format(((float) $discount->discount_percentage) * 100, 2) }}%)</td>
                </tr>
            @endif
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold;">Extra Guests</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">{{ $reservation->no_of_extra_guests }}</td>
            </tr>
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold;">Total Price</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">₱{{ number_format((float) $reservation->total_price, 2) }}</td>
            </tr>
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold;">Amount Due</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">₱{{ number_format((float) $reservation->amount_due, 2) }}</td>
            </tr>
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold;">Status</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">{{ $reservation->status }}</td>
            </tr>
        </tbody>
    </table>

    @if ($reservation->extraGuests->isNotEmpty())
        <h3 style="font-size: 16px; margin-top: 20px;">Extra Guest Names</h3>
        <ul>
            @foreach ($reservation->extraGuests as $extraGuest)
                <li>{{ trim($extraGuest->first_name . ' ' . ($extraGuest->middle_name ? $extraGuest->middle_name . ' ' : '') . $extraGuest->last_name) }}</li>
            @endforeach
        </ul>
    @endif

    <p style="margin-top: 20px; font-size: 13px; color: #4b5563;">
        This is an automated confirmation email. Please contact the resort directly for urgent changes.
    </p>
</div>
