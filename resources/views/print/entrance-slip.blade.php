<x-print.layout title="Entrance Slip">
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold">Entrance Slip</h2>
            <p class="text-sm text-zinc-600">Slip #{{ str_pad((string) $entranceSlip->entrance_slip_id, 6, '0', STR_PAD_LEFT) }}</p>
        </div>
        <div class="text-right text-sm">
            <p><strong>Status:</strong> {{ $entranceSlip->status }}</p>
            <p><strong>Date:</strong> {{ optional($entranceSlip->date_created)->format('M d, Y') }}</p>
            <p><strong>Time:</strong> {{ $entranceSlip->time_created }}</p>
        </div>
    </div>

    <div class="mb-6 grid grid-cols-2 gap-4 text-sm">
        <div class="rounded-xl border border-zinc-200 p-4">
            <h3 class="mb-2 font-semibold">Guest Count</h3>
            <p>Adults: {{ $entranceSlip->no_of_adult }}</p>
            <p>Children: {{ $entranceSlip->no_of_children }}</p>
            <p>Senior / PWD: {{ $entranceSlip->no_of_PWD_SC }}</p>
        </div>
        <div class="rounded-xl border border-zinc-200 p-4">
            <h3 class="mb-2 font-semibold">Tourism Count</h3>
            <p>Male: {{ $entranceSlip->no_of_Male }}</p>
            <p>Female: {{ $entranceSlip->no_of_Female }}</p>
            <p>Tourist: {{ $entranceSlip->no_of_Tourist }}</p>
        </div>
    </div>

    <table class="mb-6 w-full border-collapse text-sm">
        <thead>
            <tr class="bg-zinc-100 text-left">
                <th class="border border-zinc-300 p-2">Category</th>
                <th class="border border-zinc-300 p-2 text-right">Qty</th>
                <th class="border border-zinc-300 p-2 text-right">Discounted Qty</th>
                <th class="border border-zinc-300 p-2">Discount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($entranceSlip->details as $detail)
                <tr>
                    <td class="border border-zinc-300 p-2">{{ $detail->entranceFee->category ?? 'Entrance Fee' }}</td>
                    <td class="border border-zinc-300 p-2 text-right">{{ $detail->guest_quantity }}</td>
                    <td class="border border-zinc-300 p-2 text-right">{{ $detail->discounted_quantity ?? 0 }}</td>
                    <td class="border border-zinc-300 p-2">{{ $detail->discount->discount_name ?? 'None' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="border border-zinc-300 p-2 text-center text-zinc-500">No entrance slip details found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="ml-auto w-full max-w-sm rounded-xl border border-zinc-200 p-4 text-sm">
        <div class="flex justify-between"><span>Total Price</span><strong>₱{{ number_format((float) $entranceSlip->total_price, 2) }}</strong></div>
        <div class="flex justify-between"><span>Amount Due</span><strong>₱{{ number_format((float) $entranceSlip->amount_due, 2) }}</strong></div>
    </div>

    <div class="mt-10 grid grid-cols-2 gap-8 text-sm">
        <div class="border-t border-zinc-400 pt-2 text-center">Security Guard / Created By<br>{{ $entranceSlip->createdBy->full_name ?? $entranceSlip->createdBy->username ?? '' }}</div>
        <div class="border-t border-zinc-400 pt-2 text-center">Cashier / Handled By<br>{{ $entranceSlip->handledBy->full_name ?? $entranceSlip->handledBy->username ?? '' }}</div>
    </div>
</x-print.layout>
