<x-print.layout title="Payment Receipt">
    @php
        $payer = $payment->booking?->guest ?? $payment->reservation?->guest ?? $payment->entranceSlip?->guest;
        $source = $payment->booking ? 'Booking '.$payment->booking->b_ref_no : ($payment->reservation ? 'Reservation '.$payment->reservation->r_ref_no : ($payment->entranceSlip ? 'Entrance Slip #'.str_pad((string) $payment->entranceSlip->entrance_slip_id, 6, '0', STR_PAD_LEFT) : 'Payment'));
    @endphp

    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold">Payment Receipt</h2>
            <p class="text-sm text-zinc-600">Payment Ref: <strong>{{ $payment->p_ref_no }}</strong></p>
        </div>
        <div class="text-right text-sm">
            <p><strong>Status:</strong> {{ $payment->payment_status }}</p>
            <p><strong>Date Paid:</strong> {{ optional($payment->date_paid)->format('M d, Y') }}</p>
        </div>
    </div>

    <div class="mb-6 grid grid-cols-2 gap-4 text-sm">
        <div class="rounded-xl border border-zinc-200 p-4">
            <h3 class="mb-2 font-semibold">Payer</h3>
            <p>{{ $payer->full_name ?? 'Walk-in Guest' }}</p>
            <p>{{ $payer->email ?? '' }}</p>
            <p>{{ $payer->contact_no ?? '' }}</p>
        </div>
        <div class="rounded-xl border border-zinc-200 p-4">
            <h3 class="mb-2 font-semibold">Payment Details</h3>
            <p><strong>Source:</strong> {{ $source }}</p>
            <p><strong>Mode:</strong> {{ $payment->modeOfPayment->mode_of_payment ?? 'N/A' }}</p>
            <p><strong>GCash Ref:</strong> {{ $payment->reference_number ?: 'N/A' }}</p>
            <p><strong>Recorded By:</strong> {{ $payment->user->full_name ?? $payment->user->username ?? 'Guest Upload' }}</p>
        </div>
    </div>

    @if (! empty($breakdown) && count($breakdown['lines']) > 0)
        <div class="mb-6 rounded-xl border border-zinc-200 p-4 text-sm">
            <h3 class="mb-3 font-semibold">What this payment covered</h3>
            <table class="w-full text-left">
                <thead class="border-b border-zinc-200 text-xs uppercase text-zinc-500">
                    <tr>
                        <th class="py-2 pr-3">Item</th>
                        <th class="py-2 pr-3 text-right">Charge</th>
                        <th class="py-2 pr-3 text-right">Paid now</th>
                        <th class="py-2 text-right">Still owed</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @foreach ($breakdown['lines'] as $line)
                        <tr>
                            <td class="py-2 pr-3">
                                <p class="font-medium">{{ $line['label'] }}</p>
                                <p class="text-xs text-zinc-500">{{ $line['group'] }}@if ($line['detail']) · {{ $line['detail'] }}@endif</p>
                            </td>
                            <td class="py-2 pr-3 text-right">₱{{ number_format((float) $line['charge'], 2) }}</td>
                            <td class="py-2 pr-3 text-right font-semibold">₱{{ number_format((float) $line['applied'], 2) }}</td>
                            <td class="py-2 text-right">₱{{ number_format((float) $line['remaining'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ((float) $breakdown['unapplied'] > 0)
                <p class="mt-2 text-xs text-zinc-500">₱{{ number_format((float) $breakdown['unapplied'], 2) }} of this payment was not matched to an itemised charge.</p>
            @endif

            <div class="mt-3 flex justify-between border-t border-zinc-200 pt-2 font-semibold">
                <span>Balance after this payment</span>
                <span>₱{{ number_format((float) $breakdown['balance_after'], 2) }}</span>
            </div>
        </div>
    @endif

    <div class="ml-auto w-full max-w-sm space-y-2 rounded-xl border border-zinc-200 p-4 text-sm">
        <div class="flex justify-between text-lg"><span>Amount Paid</span><strong>₱{{ number_format((float) $payment->amount_paid, 2) }}</strong></div>

        {{-- Only cash payments store the cash received and the change returned. --}}
        @if (! is_null($payment->cash_paid))
            <div class="flex justify-between"><span>Cash Paid</span><strong>₱{{ number_format((float) $payment->cash_paid, 2) }}</strong></div>
            <div class="flex justify-between"><span>Change</span><strong>₱{{ number_format((float) $payment->change_amount, 2) }}</strong></div>
        @endif
    </div>

    <div class="mt-10 grid grid-cols-2 gap-8 text-sm">
        <div class="border-t border-zinc-400 pt-2 text-center">Received By</div>
        <div class="border-t border-zinc-400 pt-2 text-center">Guest Signature</div>
    </div>
</x-print.layout>
