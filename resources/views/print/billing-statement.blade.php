<x-print.layout title="Billing Statement">
    @php
        $money = static fn (mixed $amount): string =>
            '₱'.number_format((float) $amount, 2);
        $booking = $statement['booking'];
    @endphp

    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold">
                Billing Statement
            </h2>
            <p class="text-sm text-zinc-600">
                Booking Ref:
                <strong>{{ $booking->b_ref_no }}</strong>
            </p>
            <p class="text-xs text-zinc-500">
                Generated: {{ $statement['generated_at'] }}
            </p>
        </div>

        <div class="text-right text-sm">
            <p>
                <strong>Booking Status:</strong>
                {{ $booking->status }}
            </p>
            <p>
                <strong>Payment Status:</strong>
                {{ $statement['payment_status'] }}
            </p>
            <p>
                <strong>Guest:</strong>
                {{ $statement['guest_name'] }}
            </p>
        </div>
    </div>

    <div class="mb-6 grid grid-cols-2 gap-4 text-sm">
        <div class="rounded-xl border border-zinc-200 p-4">
            <h3 class="mb-2 font-semibold">
                Guest Information
            </h3>
            <p>{{ $statement['guest_name'] }}</p>
            <p>{{ $statement['guest_contact'] }}</p>
            <p>{{ $statement['guest_email'] }}</p>
        </div>

        <div class="rounded-xl border border-zinc-200 p-4">
            <h3 class="mb-2 font-semibold">
                Statement Summary
            </h3>
            <p>
                <strong>Total Charges:</strong>
                {{ $money($statement['total_price']) }}
            </p>
            <p>
                <strong>Verified Paid:</strong>
                {{ $money($statement['total_paid']) }}
            </p>
            <p>
                <strong>Amount Due:</strong>
                {{ $money($statement['amount_due']) }}
            </p>
        </div>
    </div>

    <h3 class="mb-2 font-semibold">
        Facility Charges
    </h3>

    <table class="mb-6 w-full border-collapse text-sm">
        <thead>
            <tr class="bg-zinc-100 text-left">
                <th class="border border-zinc-300 p-2">Facility</th>
                <th class="border border-zinc-300 p-2">Rate</th>
                <th class="border border-zinc-300 p-2">Dates</th>
                <th class="border border-zinc-300 p-2 text-right">Base</th>
                <th class="border border-zinc-300 p-2 text-right">Discount</th>
                <th class="border border-zinc-300 p-2 text-right">Extra</th>
                <th class="border border-zinc-300 p-2 text-right">Total</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($statement['facility_lines'] as $line)
                <tr>
                    <td class="border border-zinc-300 p-2">
                        <div>{{ $line['facility'] }}</div>
                        <div class="text-xs text-zinc-500">
                            {{ $line['facility_type'] }}
                            / {{ $line['status'] }}
                        </div>
                    </td>
                    <td class="border border-zinc-300 p-2">
                        {{ $line['rate_type'] }}
                    </td>
                    <td class="border border-zinc-300 p-2">
                        {{ $line['check_in_date'] }}
                        to
                        {{ $line['check_out_date'] }}
                    </td>
                    <td class="border border-zinc-300 p-2 text-right">
                        {{ $money($line['base_price']) }}
                    </td>
                    <td class="border border-zinc-300 p-2 text-right">
                        {{ $money($line['discount_amount']) }}
                    </td>
                    <td class="border border-zinc-300 p-2 text-right">
                        {{ $money($line['extra_guest_fee']) }}
                    </td>
                    <td class="border border-zinc-300 p-2 text-right">
                        @if ($line['has_snapshot'])
                            {{ $money($line['line_total']) }}
                        @else
                            <span class="text-zinc-500">
                                Included in recorded booking total
                            </span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td
                        colspan="7"
                        class="border border-zinc-300 p-2 text-center text-zinc-500"
                    >
                        No facility charges found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h3 class="mb-2 font-semibold">
        Amenity Requests
    </h3>

    <table class="mb-6 w-full border-collapse text-sm">
        <thead>
            <tr class="bg-zinc-100 text-left">
                <th class="border border-zinc-300 p-2">Amenity</th>
                <th class="border border-zinc-300 p-2">Facility</th>
                <th class="border border-zinc-300 p-2">Status</th>
                <th class="border border-zinc-300 p-2 text-right">Qty</th>
                <th class="border border-zinc-300 p-2 text-right">Unit</th>
                <th class="border border-zinc-300 p-2 text-right">Total</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($statement['amenity_lines'] as $line)
                <tr>
                    <td class="border border-zinc-300 p-2">
                        {{ $line['amenity'] }}
                    </td>
                    <td class="border border-zinc-300 p-2">
                        {{ $line['facility'] }}
                    </td>
                    <td class="border border-zinc-300 p-2">
                        {{ $line['request_status'] }}
                    </td>
                    <td class="border border-zinc-300 p-2 text-right">
                        {{ $line['quantity'] }}
                    </td>
                    <td class="border border-zinc-300 p-2 text-right">
                        {{ $money($line['unit_price']) }}
                    </td>
                    <td class="border border-zinc-300 p-2 text-right">
                        {{ $money($line['line_total']) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td
                        colspan="6"
                        class="border border-zinc-300 p-2 text-center text-zinc-500"
                    >
                        No billable amenity requests.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h3 class="mb-2 font-semibold">
        Fines
    </h3>

    <table class="mb-6 w-full border-collapse text-sm">
        <thead>
            <tr class="bg-zinc-100 text-left">
                <th class="border border-zinc-300 p-2">Fine</th>
                <th class="border border-zinc-300 p-2">Facility</th>
                <th class="border border-zinc-300 p-2">Checked</th>
                <th class="border border-zinc-300 p-2">Reported By</th>
                <th class="border border-zinc-300 p-2 text-right">Qty</th>
                <th class="border border-zinc-300 p-2 text-right">Charge</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($statement['fine_lines'] as $line)
                <tr>
                    <td class="border border-zinc-300 p-2">
                        {{ $line['description'] }}
                    </td>
                    <td class="border border-zinc-300 p-2">
                        {{ $line['facility'] }}
                    </td>
                    <td class="border border-zinc-300 p-2">
                        {{ $line['date_checked'] }}
                    </td>
                    <td class="border border-zinc-300 p-2">
                        {{ $line['reported_by'] }}
                    </td>
                    <td class="border border-zinc-300 p-2 text-right">
                        {{ $line['quantity'] }}
                    </td>
                    <td class="border border-zinc-300 p-2 text-right">
                        {{ $money($line['total_charge']) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td
                        colspan="6"
                        class="border border-zinc-300 p-2 text-center text-zinc-500"
                    >
                        No fines.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h3 class="mb-2 font-semibold">
        Verified Payments
    </h3>

    <table class="mb-6 w-full border-collapse text-sm">
        <thead>
            <tr class="bg-zinc-100 text-left">
                <th class="border border-zinc-300 p-2">Payment Ref</th>
                <th class="border border-zinc-300 p-2">Mode</th>
                <th class="border border-zinc-300 p-2">Date</th>
                <th class="border border-zinc-300 p-2">Received By</th>
                <th class="border border-zinc-300 p-2 text-right">Amount</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($statement['payment_lines'] as $line)
                <tr>
                    <td class="border border-zinc-300 p-2">
                        {{ $line['payment_ref_no'] }}
                        @if ($line['reference_number'])
                            <div class="text-xs text-zinc-500">
                                Ref: {{ $line['reference_number'] }}
                            </div>
                        @endif
                    </td>
                    <td class="border border-zinc-300 p-2">
                        {{ $line['mode'] }}
                    </td>
                    <td class="border border-zinc-300 p-2">
                        {{ $line['date_paid'] }}
                    </td>
                    <td class="border border-zinc-300 p-2">
                        {{ $line['received_by'] }}
                    </td>
                    <td class="border border-zinc-300 p-2 text-right">
                        {{ $money($line['amount_paid']) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td
                        colspan="5"
                        class="border border-zinc-300 p-2 text-center text-zinc-500"
                    >
                        No verified payments.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="ml-auto w-full max-w-sm rounded-xl border border-zinc-200 p-4 text-sm">
        <div class="flex justify-between">
            <span>Total Charges</span>
            <strong>{{ $money($statement['total_price']) }}</strong>
        </div>
        <div class="flex justify-between">
            <span>Verified Paid</span>
            <strong>{{ $money($statement['total_paid']) }}</strong>
        </div>
        <div class="flex justify-between text-lg">
            <span>Amount Due</span>
            <strong>{{ $money($statement['amount_due']) }}</strong>
        </div>
    </div>
</x-print.layout>
