<x-print.layout title="Billing Statement">
    @php
        $verifiedPaid = $booking->payments->where('payment_status', 'Verified')->sum('amount_paid');
    @endphp

    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold">Billing Statement</h2>
            <p class="text-sm text-zinc-600">Booking Ref: <strong>{{ $booking->b_ref_no }}</strong></p>
        </div>
        <div class="text-right text-sm">
            <p><strong>Status:</strong> {{ $booking->status }}</p>
            <p><strong>Guest:</strong> {{ $booking->guest->full_name ?? 'N/A' }}</p>
        </div>
    </div>

    <h3 class="mb-2 font-semibold">Facility Charges</h3>
    <table class="mb-6 w-full border-collapse text-sm">
        <thead><tr class="bg-zinc-100 text-left"><th class="border border-zinc-300 p-2">Facility</th><th class="border border-zinc-300 p-2">Rate</th><th class="border border-zinc-300 p-2 text-right">Base</th><th class="border border-zinc-300 p-2 text-right">Discount</th><th class="border border-zinc-300 p-2 text-right">Extra Guest</th><th class="border border-zinc-300 p-2 text-right">Total</th></tr></thead>
        <tbody>
            @forelse ($booking->details as $detail)
                <tr>
                    <td class="border border-zinc-300 p-2">{{ $detail->facility->facility_name ?? 'N/A' }}</td>
                    <td class="border border-zinc-300 p-2">{{ $detail->rate_type }}</td>
                    <td class="border border-zinc-300 p-2 text-right">₱{{ number_format((float) ($detail->base_price ?? 0), 2) }}</td>
                    <td class="border border-zinc-300 p-2 text-right">₱{{ number_format((float) ($detail->discount_amount ?? 0), 2) }}</td>
                    <td class="border border-zinc-300 p-2 text-right">₱{{ number_format((float) ($detail->extra_guest_fee ?? 0), 2) }}</td>
                    <td class="border border-zinc-300 p-2 text-right">₱{{ number_format((float) ($detail->line_total ?? 0), 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="border border-zinc-300 p-2 text-center text-zinc-500">No facility charges found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3 class="mb-2 font-semibold">Amenity Requests</h3>
    <table class="mb-6 w-full border-collapse text-sm">
        <thead><tr class="bg-zinc-100 text-left"><th class="border border-zinc-300 p-2">Amenity</th><th class="border border-zinc-300 p-2">Facility</th><th class="border border-zinc-300 p-2 text-right">Qty</th><th class="border border-zinc-300 p-2 text-right">Unit</th><th class="border border-zinc-300 p-2 text-right">Total</th></tr></thead>
        <tbody>
            @forelse ($booking->amenityRequests as $request)
                @foreach ($request->details as $detail)
                    <tr>
                        <td class="border border-zinc-300 p-2">{{ $detail->amenity->amenityName->amenity_name ?? 'Amenity' }}</td>
                        <td class="border border-zinc-300 p-2">{{ $detail->facility->facility_name ?? 'N/A' }}</td>
                        <td class="border border-zinc-300 p-2 text-right">{{ $detail->amenity_quantity }}</td>
                        <td class="border border-zinc-300 p-2 text-right">₱{{ number_format((float) ($detail->unit_price ?? 0), 2) }}</td>
                        <td class="border border-zinc-300 p-2 text-right">₱{{ number_format((float) ($detail->line_total ?? 0), 2) }}</td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="5" class="border border-zinc-300 p-2 text-center text-zinc-500">No amenity requests.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3 class="mb-2 font-semibold">Fines</h3>
    <table class="mb-6 w-full border-collapse text-sm">
        <thead><tr class="bg-zinc-100 text-left"><th class="border border-zinc-300 p-2">Fine</th><th class="border border-zinc-300 p-2">Facility</th><th class="border border-zinc-300 p-2 text-right">Qty</th><th class="border border-zinc-300 p-2 text-right">Charge</th></tr></thead>
        <tbody>
            @forelse ($booking->guestFines as $guestFine)
                <tr>
                    <td class="border border-zinc-300 p-2">{{ $guestFine->fine->situational_fine ?: ($guestFine->fine->amenity->amenityName->amenity_name ?? 'Fine') }}</td>
                    <td class="border border-zinc-300 p-2">{{ $guestFine->facility->facility_name ?? 'N/A' }}</td>
                    <td class="border border-zinc-300 p-2 text-right">{{ $guestFine->quantity }}</td>
                    <td class="border border-zinc-300 p-2 text-right">₱{{ number_format((float) $guestFine->total_charge, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="border border-zinc-300 p-2 text-center text-zinc-500">No fines.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3 class="mb-2 font-semibold">Verified Payments</h3>
    <table class="mb-6 w-full border-collapse text-sm">
        <thead><tr class="bg-zinc-100 text-left"><th class="border border-zinc-300 p-2">Ref</th><th class="border border-zinc-300 p-2">Date</th><th class="border border-zinc-300 p-2">Mode</th><th class="border border-zinc-300 p-2 text-right">Amount</th></tr></thead>
        <tbody>
            @forelse ($booking->payments->where('payment_status', 'Verified') as $payment)
                <tr>
                    <td class="border border-zinc-300 p-2">{{ $payment->p_ref_no }}</td>
                    <td class="border border-zinc-300 p-2">{{ optional($payment->date_paid)->format('M d, Y') }}</td>
                    <td class="border border-zinc-300 p-2">{{ $payment->modeOfPayment->mode_of_payment ?? 'N/A' }}</td>
                    <td class="border border-zinc-300 p-2 text-right">₱{{ number_format((float) $payment->amount_paid, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="border border-zinc-300 p-2 text-center text-zinc-500">No verified payments.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="ml-auto w-full max-w-sm rounded-xl border border-zinc-200 p-4 text-sm">
        <div class="flex justify-between"><span>Total Charges</span><strong>₱{{ number_format((float) $booking->total_price, 2) }}</strong></div>
        <div class="flex justify-between"><span>Verified Paid</span><strong>₱{{ number_format((float) $verifiedPaid, 2) }}</strong></div>
        <div class="flex justify-between text-lg"><span>Amount Due</span><strong>₱{{ number_format((float) $booking->amount_due, 2) }}</strong></div>
    </div>
</x-print.layout>
