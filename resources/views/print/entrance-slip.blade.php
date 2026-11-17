<x-print.layout title="Entrance Slip" size="thermal">
    @php
        $peso = static fn (mixed $amount): string => '₱'.number_format((float) $amount, 2);
    @endphp

    <p class="t-center t-big">ENTRANCE SLIP</p>
    <p class="t-center t-small">Slip #{{ str_pad((string) $entranceSlip->entrance_slip_id, 6, '0', STR_PAD_LEFT) }}</p>
    <div class="t-sep"></div>

    <div class="t-row"><span>Status</span><span>{{ $entranceSlip->status }}</span></div>
    <div class="t-row"><span>Date</span><span>{{ optional($entranceSlip->date_created)->format('M d, Y') }}</span></div>
    <div class="t-row"><span>Time</span><span>{{ $entranceSlip->time_created }}</span></div>

    <div class="t-sep"></div>
    <p class="t-strong">GUEST COUNT</p>
    <div class="t-row"><span>Adults</span><span>{{ $entranceSlip->no_of_adult }}</span></div>
    <div class="t-row"><span>Children</span><span>{{ $entranceSlip->no_of_children }}</span></div>
    <div class="t-row"><span>Senior / PWD</span><span>{{ $entranceSlip->no_of_PWD_SC }}</span></div>
    <div class="t-row"><span>Male</span><span>{{ $entranceSlip->no_of_Male }}</span></div>
    <div class="t-row"><span>Female</span><span>{{ $entranceSlip->no_of_Female }}</span></div>

    <div class="t-sep"></div>
    <p class="t-strong">CHARGES</p>

    @forelse ($entranceSlip->details as $detail)
        @php
            $rate = (float) ($detail->unit_rate_snapshot ?? $detail->entranceFee?->entrance_fee_price);
            $subtotal = (float) ($detail->line_total_snapshot ?? ((float) ($detail->entranceFee?->entrance_fee_price ?? 0) * $detail->guest_quantity));
        @endphp
        <div style="margin-top: 4px;">
            <p class="t-strong">{{ $detail->entranceFee?->entrance_fee_name ?? 'Entrance Fee' }}</p>
            <div class="t-row t-small"><span>{{ $detail->guest_quantity }} × {{ $peso($rate) }}</span><span class="t-strong">{{ $peso($subtotal) }}</span></div>
            @if (($detail->discounted_quantity ?? 0) > 0)
                <p class="t-small">{{ $detail->discounted_quantity }} discounted · {{ $detail->discount?->discount_name ?? 'Discount' }}</p>
            @endif
        </div>
    @empty
        <p class="t-small">No entrance slip details found.</p>
    @endforelse

    <div class="t-sep"></div>
    <div class="t-row t-big"><span>TOTAL</span><span>{{ $peso($entranceSlip->total_price) }}</span></div>
    <div class="t-row"><span>Amount Due</span><span class="t-strong">{{ $peso($entranceSlip->amount_due) }}</span></div>

    @if ($entranceSlip->admitted_at)
        <div class="t-sep"></div>
        <p class="t-small">Admitted {{ $entranceSlip->admitted_at->format('M d, Y h:i A') }} by {{ $entranceSlip->admittedBy?->full_name ?? $entranceSlip->admittedBy?->username }}</p>
    @endif

    <div class="t-sign">
        Security Guard / Created By<br>{{ $entranceSlip->createdBy->full_name ?? $entranceSlip->createdBy->username ?? '' }}
    </div>
    <div class="t-sign">
        Cashier / Handled By<br>{{ $entranceSlip->handledBy->full_name ?? $entranceSlip->handledBy->username ?? '' }}
    </div>

    <p class="t-center t-small" style="margin-top: 10px;">Thank you!</p>
</x-print.layout>
