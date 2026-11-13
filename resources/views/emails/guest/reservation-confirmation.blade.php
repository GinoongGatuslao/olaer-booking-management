@php
    $guest = $reservation->guest;

    // Every active facility is listed. Closed ones stay hidden except on the cancellation email.
    $details = $reservation->details;

    if ($action !== 'cancelled') {
        $details = $details->reject(
            fn ($d) => in_array((string) $d->status, ['Cancelled', 'Converted', 'No-show'], true)
        );
    }

    if ($details->isEmpty()) {
        $details = $reservation->details;
    }

    $details = $details->values();
    $multiple = $details->count() > 1;

    $facilityType = strtolower(
        (string) $details->first()?->facility?->facilityType?->facility_type
    );
    $legacyTotalGuests = $facilityType === 'room'
        ? 4 + (int) $reservation->no_of_extra_guests
        : 1 + (int) $reservation->no_of_extra_guests;

    $fmt = fn ($date) => $date
        ? \Illuminate\Support\Carbon::parse($date)->format('M d, Y')
        : 'N/A';

    $label = 'border: 1px solid #e5e7eb; padding: 8px; font-weight: bold; width: 35%;';
    $value = 'border: 1px solid #e5e7eb; padding: 8px;';
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

    <p>Hello {{ trim(\App\Support\PersonInput::name((string) $guest->first_name) . ' ' . \App\Support\PersonInput::name((string) $guest->last_name)) }},</p>

    @if ($action === 'cancelled')
        <p>Your reservation has been cancelled. Keep this email for your records.</p>
    @else
        <p>Your reservation details are below. Please present your reference number to the cashier for verification.</p>
    @endif

    <table style="width: 100%; border-collapse: collapse; margin-top: 16px;">
        <tbody>
            <tr>
                <td style="{{ $label }}">Reference No.</td>
                <td style="{{ $value }}">{{ $reservation->r_ref_no }}</td>
            </tr>
            <tr>
                <td style="{{ $label }}">Guest</td>
                <td style="{{ $value }}">{{ trim(\App\Support\PersonInput::name((string) $guest->first_name) . ' ' . ($guest->middle_name ? $guest->middle_name . ' ' : '') . \App\Support\PersonInput::name((string) $guest->last_name)) }}</td>
            </tr>
            <tr>
                <td style="{{ $label }}">Contact No.</td>
                <td style="{{ $value }}">{{ $guest->contact_no }}</td>
            </tr>
        </tbody>
    </table>

    @foreach ($details as $detail)
        @php($facility = $detail->facility)
        @php
            // Show the discount as a whole percent (10%, not 10.00%). The rate saved with the
            // booking is used first; the discount's current value is only a fallback.
            $discount = $detail->discount;
            $discountRate = (float) $detail->discount_rate;

            if ($discountRate <= 0 && $discount) {
                $rawDiscount = (float) $discount->discount_amount;
                $discountRate = $rawDiscount > 1 ? min($rawDiscount / 100, 1) : max($rawDiscount, 0);
            }

            $discountPercent = rtrim(rtrim(number_format($discountRate * 100, 2, '.', ''), '0'), '.');
            $discountSaved = (float) $detail->discount_amount;
        @endphp

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
                @if ($discount || $discountSaved > 0)
                    <tr>
                        <td style="{{ $label }}">Discount</td>
                        <td style="{{ $value }}">
                            {{ $discount?->discount_name ?? 'Discount' }}@if ($discountRate > 0) ({{ $discountPercent }}%)@endif
                            @if ($discountSaved > 0)
                                &ndash; saves ₱{{ number_format($discountSaved, 2) }}
                            @endif
                        </td>
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
                <td style="{{ $value }}">{{ $reservation->total_guest_count ?? max(1, $legacyTotalGuests) }}</td>
            </tr>
            <tr>
                <td style="{{ $label }}">Paid Room Extra Guests</td>
                <td style="{{ $value }}">{{ $reservation->no_of_extra_guests }}</td>
            </tr>
            <tr>
                <td style="{{ $label }}">Total Price</td>
                <td style="{{ $value }}">₱{{ number_format((float) $reservation->total_price, 2) }}</td>
            </tr>
            <tr>
                <td style="{{ $label }}">Amount Due</td>
                <td style="{{ $value }}">₱{{ number_format((float) $reservation->amount_due, 2) }}</td>
            </tr>
            <tr>
                <td style="{{ $label }}">Status</td>
                <td style="{{ $value }}">{{ $reservation->status }}</td>
            </tr>
        </tbody>
    </table>

    @if ($reservation->extraGuests->isNotEmpty())
        <h3 style="font-size: 16px; margin-top: 20px;">Paid Room Extra Guest Names</h3>
        <ul>
            @foreach ($reservation->extraGuests as $extraGuest)
                <li>{{ trim(\App\Support\PersonInput::name((string) $extraGuest->first_name) . ' ' . ($extraGuest->middle_name ? $extraGuest->middle_name . ' ' : '') . \App\Support\PersonInput::name((string) $extraGuest->last_name)) }}</li>
            @endforeach
        </ul>
    @endif

    <p style="margin-top: 20px; font-size: 13px; color: #4b5563;">
        This is an automated confirmation email. Please contact the resort directly for urgent changes.
    </p>
</div>