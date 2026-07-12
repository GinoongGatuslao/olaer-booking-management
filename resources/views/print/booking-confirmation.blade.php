<x-print.layout title="Booking Confirmation Slip">
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold">Booking Confirmation Slip</h2>
            <p class="text-sm text-zinc-600">Reference: <strong>{{ $booking->b_ref_no }}</strong></p>
        </div>
        <div class="text-right text-sm">
            <p><strong>Status:</strong> {{ $booking->status }}</p>
            <p><strong>Booking Date:</strong> {{ optional($booking->booking_date)->format('M d, Y') }}</p>
        </div>
    </div>

    <div class="mb-6 rounded-xl border border-zinc-200 p-4 text-sm">
        <h3 class="mb-2 font-semibold">Guest Representative</h3>
        <p>{{ $booking->guest->full_name ?? 'N/A' }}</p>
        <p>{{ $booking->guest->email ?? '' }}</p>
        <p>{{ $booking->guest->contact_no ?? '' }}</p>
    </div>

    <table class="mb-6 w-full border-collapse text-sm">
        <thead>
            <tr class="bg-zinc-100 text-left">
                <th class="border border-zinc-300 p-2">Facility</th>
                <th class="border border-zinc-300 p-2">Rate</th>
                <th class="border border-zinc-300 p-2">Check-in</th>
                <th class="border border-zinc-300 p-2">Check-out</th>
                <th class="border border-zinc-300 p-2">Status</th>
                <th class="border border-zinc-300 p-2 text-right">Line Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($booking->details as $detail)
                <tr>
                    <td class="border border-zinc-300 p-2">{{ $detail->facility->facility_name ?? 'N/A' }}</td>
                    <td class="border border-zinc-300 p-2">{{ $detail->rate_type }}</td>
                    <td class="border border-zinc-300 p-2">{{ optional($detail->check_in_date)->format('M d, Y') }}</td>
                    <td class="border border-zinc-300 p-2">{{ optional($detail->check_out_date)->format('M d, Y') }}</td>
                    <td class="border border-zinc-300 p-2">{{ $detail->status }}</td>
                    <td class="border border-zinc-300 p-2 text-right">₱{{ number_format((float) ($detail->line_total ?? 0), 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="ml-auto w-full max-w-sm rounded-xl border border-zinc-200 p-4 text-sm">
        <div class="flex justify-between"><span>Total Price</span><strong>₱{{ number_format((float) $booking->total_price, 2) }}</strong></div>
        <div class="flex justify-between"><span>Amount Due</span><strong>₱{{ number_format((float) $booking->amount_due, 2) }}</strong></div>
        <div class="flex justify-between"><span>Extra Guests</span><strong>{{ $booking->no_of_extra_guests ?? 0 }}</strong></div>
    </div>
</x-print.layout>
