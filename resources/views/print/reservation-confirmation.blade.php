<x-print.layout title="Reservation Confirmation Slip">
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold">Reservation Confirmation Slip</h2>
            <p class="text-sm text-zinc-600">Reference: <strong>{{ $reservation->r_ref_no }}</strong></p>
        </div>
        <div class="text-right text-sm">
            <p><strong>Status:</strong> {{ $reservation->status }}</p>
            <p><strong>Reservation Date:</strong> {{ optional($reservation->reservation_date)->format('M d, Y') }}</p>
        </div>
    </div>

    <div class="mb-6 rounded-xl border border-zinc-200 p-4 text-sm">
        <h3 class="mb-2 font-semibold">Guest Representative</h3>
        <p>{{ $reservation->guest->full_name ?? 'N/A' }}</p>
        <p>{{ $reservation->guest->email ?? '' }}</p>
        <p>{{ $reservation->guest->contact_no ?? '' }}</p>
    </div>

    <table class="mb-6 w-full border-collapse text-sm">
        <thead>
            <tr class="bg-zinc-100 text-left">
                <th class="border border-zinc-300 p-2">Facility</th>
                <th class="border border-zinc-300 p-2">Type</th>
                <th class="border border-zinc-300 p-2">Rate</th>
                <th class="border border-zinc-300 p-2">Check-in</th>
                <th class="border border-zinc-300 p-2">Check-out</th>
                <th class="border border-zinc-300 p-2">Discount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($reservation->details as $detail)
                <tr>
                    <td class="border border-zinc-300 p-2">{{ $detail->facility->facility_name ?? 'N/A' }}</td>
                    <td class="border border-zinc-300 p-2">{{ $detail->facility->facilityType->facility_type ?? 'N/A' }}</td>
                    <td class="border border-zinc-300 p-2">{{ $detail->rate_type }}</td>
                    <td class="border border-zinc-300 p-2">{{ optional($detail->check_in_date)->format('M d, Y') }}</td>
                    <td class="border border-zinc-300 p-2">{{ optional($detail->check_out_date)->format('M d, Y') }}</td>
                    <td class="border border-zinc-300 p-2">{{ $detail->discount->discount_name ?? 'None' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="ml-auto w-full max-w-sm rounded-xl border border-zinc-200 p-4 text-sm">
        <div class="flex justify-between"><span>Total Price</span><strong>₱{{ number_format((float) $reservation->total_price, 2) }}</strong></div>
        <div class="flex justify-between"><span>Amount Due</span><strong>₱{{ number_format((float) $reservation->amount_due, 2) }}</strong></div>
        <div class="flex justify-between"><span>Extra Guests</span><strong>{{ $reservation->no_of_extra_guests ?? 0 }}</strong></div>
    </div>

    <p class="mt-6 text-xs text-zinc-600">Present this reference number to the cashier for verification. Reservation rules still apply according to resort policy.</p>
</x-print.layout>
