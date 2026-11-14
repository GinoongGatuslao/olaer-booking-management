@php
    $label = 'border: 1px solid #e5e7eb; padding: 8px; font-weight: bold; width: 35%;';
    $value = 'border: 1px solid #e5e7eb; padding: 8px;';
    $kind = $type === 'booking' ? 'booking' : 'reservation';
@endphp

<div style="font-family: Arial, sans-serif; color: #111827; line-height: 1.5; max-width: 720px; margin: 0 auto;">
    <h1 style="font-size: 22px; margin-bottom: 4px;">Olaer Spring Resort</h1>
    <h2 style="font-size: 18px; color: #047857; margin-top: 0;">Confirm your {{ $kind }}</h2>

    <p>Hello {{ $guestName }},</p>

    <p>
        We received your {{ $kind }} request. <strong>It is not submitted yet.</strong>
        Click the button below to confirm it. Your {{ $kind }} and your GCash payment proof are only sent to the resort after you click.
    </p>

    <p style="margin: 24px 0;">
        <a href="{{ $link }}" style="background: #047857; color: #ffffff; padding: 12px 22px; border-radius: 6px; text-decoration: none; font-weight: bold; display: inline-block;">
            Confirm my {{ $kind }}
        </a>
    </p>

    <p style="font-size: 13px; color: #6b7280;">
        This link works for {{ $minutes }} minutes. If the button does not work, copy this address into your browser:<br>
        <span style="word-break: break-all;">{{ $link }}</span>
    </p>

    <h3 style="font-size: 16px; margin-bottom: 8px;">What you asked for</h3>

    <table style="width: 100%; border-collapse: collapse;">
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td style="{{ $label }}">{{ $line['name'] }}@if ($line['quantity'] > 1) × {{ $line['quantity'] }}@endif</td>
                    <td style="{{ $value }}">{{ $line['check_in'] }} to {{ $line['check_out'] }}</td>
                </tr>
            @endforeach
            <tr>
                <td style="{{ $label }}">Total</td>
                <td style="{{ $value }}">₱{{ number_format((float) $planTotal, 2) }}</td>
            </tr>
            <tr>
                <td style="{{ $label }}">GCash payment</td>
                <td style="{{ $value }}">₱{{ number_format((float) $paymentAmount, 2) }} (ref. {{ $referenceNumber }})</td>
            </tr>
        </tbody>
    </table>

    <p style="margin-top: 16px; font-size: 13px; color: #6b7280;">
        Facilities are assigned when you confirm, and are not held before then. If you did not make this request, you can ignore this email.
    </p>
</div>
