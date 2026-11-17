<x-print.layout title="Payment Receipt" size="thermal">
    @php
        $payer = $payment->booking?->guest ?? $payment->reservation?->guest ?? $payment->entranceSlip?->guest;
        $source = $payment->booking ? 'Booking '.$payment->booking->b_ref_no : ($payment->reservation ? 'Reservation '.$payment->reservation->r_ref_no : ($payment->entranceSlip ? 'Entrance Slip #'.str_pad((string) $payment->entranceSlip->entrance_slip_id, 6, '0', STR_PAD_LEFT) : 'Payment'));
        $peso = static fn (mixed $amount): string => '₱'.number_format((float) $amount, 2);
    @endphp

    <p class="t-center t-big">PAYMENT RECEIPT</p>
    <p class="t-center t-small">Ref: {{ $payment->p_ref_no }}</p>
    <div class="t-sep"></div>

    <div class="t-row"><span>Status</span><span>{{ $payment->payment_status }}</span></div>
    <div class="t-row"><span>Date Paid</span><span>{{ optional($payment->date_paid)->format('M d, Y') }}</span></div>
    <div class="t-row"><span>Source</span><span>{{ $source }}</span></div>
    <div class="t-row"><span>Mode</span><span>{{ $payment->modeOfPayment->mode_of_payment ?? 'N/A' }}</span></div>
    @if ($payment->reference_number)
        <div class="t-row"><span>GCash Ref</span><span>{{ $payment->reference_number }}</span></div>
    @endif
    <div class="t-row"><span>Received By</span><span>{{ $payment->user->full_name ?? $payment->user->username ?? 'Guest Upload' }}</span></div>

    <div class="t-sep"></div>
    <p class="t-strong">{{ $payer->full_name ?? 'Walk-in Guest' }}</p>
    @if (! empty($payer->contact_no))<p class="t-small">{{ $payer->contact_no }}</p>@endif
    @if (! empty($payer->email))<p class="t-small">{{ $payer->email }}</p>@endif

    @if (! empty($breakdown) && count($breakdown['lines']) > 0)
        <div class="t-sep"></div>
        <p class="t-strong">WHAT THIS PAYMENT COVERED</p>

        @foreach ($breakdown['lines'] as $line)
            <div style="margin-top: 5px;">
                <p class="t-strong">{{ $line['label'] }}</p>
                <p class="t-small">{{ $line['group'] }}@if ($line['detail']) · {{ $line['detail'] }}@endif</p>
                <div class="t-row t-small"><span>Charge</span><span>{{ $peso($line['charge']) }}</span></div>
                <div class="t-row"><span>Amount Paid</span><span class="t-strong">{{ $peso($line['applied']) }}</span></div>
                @if ((float) $line['remaining'] > 0)
                    <div class="t-row t-small"><span>Still owed</span><span>{{ $peso($line['remaining']) }}</span></div>
                @endif
            </div>
        @endforeach

        @if ((float) $breakdown['unapplied'] > 0)
            <p class="t-small" style="margin-top: 4px;">{{ $peso($breakdown['unapplied']) }} was not matched to an itemised charge.</p>
        @endif

        <div class="t-sep"></div>
        <div class="t-row"><span>Balance after payment</span><span class="t-strong">{{ $peso($breakdown['balance_after']) }}</span></div>
    @endif

    <div class="t-sep"></div>
    <div class="t-row t-big"><span>AMOUNT PAID</span><span>{{ $peso($payment->amount_paid) }}</span></div>

    {{-- Only cash payments store the cash received and the change returned. --}}
    @if (! is_null($payment->cash_paid))
        <div class="t-row"><span>Cash Paid</span><span>{{ $peso($payment->cash_paid) }}</span></div>
        <div class="t-row"><span>Change</span><span>{{ $peso($payment->change_amount) }}</span></div>
    @endif

    <div class="t-sign">Received By</div>
    <div class="t-sign">Guest Signature</div>

    <p class="t-center t-small" style="margin-top: 10px;">Thank you!</p>
</x-print.layout>
