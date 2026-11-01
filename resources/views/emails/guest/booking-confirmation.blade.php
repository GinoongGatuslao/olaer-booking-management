@php
    $guest = $booking->guest;

    // Every active facility is listed.
    $details = $booking->details
        ->reject(fn ($d) => (string) $d->status === 'Cancelled')
        ->values();

    if ($details->isEmpty()) {
        $details = $booking->details->values();
    }

    $multiple = $details->count() > 1;

    $facilityType = strtolower(
        (string) $details->first()?->facility?->facilityType?->facility_type
    );
    $legacyTotalGuests = $facilityType === 'room'
        ? 4 + (int) $booking->no_of_extra_guests
        : 1 + (int) $booking->no_of_extra_guests;
    $payment = $booking->payments->first();

    $fmt = fn ($date) => $date
        ? \Illuminate\Support\Carbon::parse($date)->format('M d, Y')
        : 'N/A';

    $label = 'border: 1px solid #e5e7eb; padding: 8px; font-weight: bold; width: 35%;';
    $value = 'border: 1px solid #e5e7eb; padding: 8px;';
@endphp

<div style="font-family: Arial, sans-serif; color: #111827; line-height: 1.5; max-width: 720px; margin: 0 auto;">
    <h1 style="font-size: 22px; margin-bottom: 4px;">Olaer Spring Resort</h1>
    <h2 style="font-size: 18px; color: #1d4ed8; margin-top: 0;">Booking Submitted for GCash Verification</h2>

    <p>Hello {{ trim($guest->first_name . ' ' . $guest->last_name) }},</p>
    <p>Your booking has been submitted. The cashier still needs to verify your uploaded GCash proof before the booking becomes fully confirmed.</p>

    <table style="width: 100%; border-collapse: collapse; margin-top: 16px;">
        <tbody>
            <tr>
                <td style="{{ $label }}">Booking Reference No.</td>
                <td style="{{ $value }}">{{ $booking->b_ref_no }}</td>
            </tr>
            <tr>
                <td style="{{ $label }}">Guest</td>
                <td style="{{ $value }}">{{ trim($guest->first_name . ' ' . ($guest->middle_name ? $guest->middle_name . ' ' : '') . $guest->last_name) }}</td>
            </tr>
        </tbody>
    </table>

    @foreach ($details as $detail)
        @php($facility = $detail->facility)

        @if ($multiple)
            <h3 style="font-size: 16px; margin: 20px 0 0;">Facility {{ $loop->iteration }} of {{ $loop->count }}</h3>
        @endif

        <table style="width: 100%; border-collapse: collapse; margin-top: {{ $multiple ? '8px' : '16px' }};">
            <tbody>
                <tr>
                    <td style="{{ $label }}">Facility</td>
                    <td style="{{ $value }}">{{ $facility?->facility_name ?? 'N/A' }} @if($facility?->facilityType) ({{ $facility->facilityType->facility_type }}) @endif</td>
                </tr>
                <tr>
                    <td style="{{ $label }}">Rate Type</td>
                    <td style="{{ $value }}">{{ $detail->rate_type ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td style="{{ $label }}">Check-in Date</td>
                    <td style="{{ $value }}">{{ $fmt($detail->check_in_date) }}</td>
                </tr>
                <tr>
                    <td style="{{ $label }}">Check-out Date</td>
                    <td style="{{ $value }}">{{ $fmt($detail->check_out_date) }}</td>
                </tr>
                @if ($multiple && $detail->guest_count)
                    <tr>
                        <td style="{{ $label }}">Guests</td>
                        <td style="{{ $value }}">{{ $detail->guest_count }}</td>
                    </tr>
                @endif
                @if ((float) $detail->discount_amount > 0)
                    <tr>
                        <td style="{{ $label }}">Discount</td>
                        <td style="{{ $value }}">-₱{{ number_format((float) $detail->discount_amount, 2) }}</td>
                    </tr>
                @endif
                @if ($multiple && $detail->line_total !== null)
                    <tr>
                        <td style="{{ $label }}">Facility Amount</td>
                        <td style="{{ $value }}">₱{{ number_format((float) $detail->line_total, 2) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    @endforeach

    <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
        <tbody>
            <tr>
                <td style="{{ $label }}">Total Guests</td>
                <td style="{{ $value }}">{{ $booking->total_guest_count ?? max(1, $legacyTotalGuests) }}</td>
            </tr>
            <tr>
                <td style="{{ $label }}">Paid Room Extra Guests</td>
                <td style="{{ $value }}">{{ $booking->no_of_extra_guests }}</td>
            </tr>
            <tr>
                <td style="{{ $label }}">Total Price</td>
                <td style="{{ $value }}">₱{{ number_format((float) $booking->total_price, 2) }}</td>
            </tr>
            <tr>
                <td style="{{ $label }}">Amount Due Until Verification</td>
                <td style="{{ $value }}">₱{{ number_format((float) $booking->amount_due, 2) }}</td>
            </tr>
            <tr>
                <td style="{{ $label }}">Booking Status</td>
                <td style="{{ $value }}">{{ $booking->status }}</td>
            </tr>
            @if ($payment)
                <tr>
                    <td style="{{ $label }}">GCash Reference</td>
                    <td style="{{ $value }}">{{ $payment->reference_number }}</td>
                </tr>
                <tr>
                    <td style="{{ $label }}">Payment Status</td>
                    <td style="{{ $value }}">{{ $payment->payment_status }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    @if ($booking->extraGuests->isNotEmpty())
        <h3 style="font-size: 16px; margin-top: 20px;">Paid Room Extra Guest Names</h3>
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