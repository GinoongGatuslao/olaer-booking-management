@php
    use Carbon\CarbonImmutable;
@endphp
<div
    class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 print:border-0 print:p-0 print:shadow-none">
    <div class="mb-5 border-b border-zinc-200 pb-4 dark:border-zinc-700">
        <h2 class="text-xl font-semibold text-zinc-900 dark:text-zinc-100">
            {{ $reportTitle }}
        </h2>

        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            @if ($this->usesMonthYear())
                Period:
                {{ CarbonImmutable::create((int) $year, (int) $month, 1)->format('F Y') }}
            @else
                Period:
                {{ CarbonImmutable::parse($startDate)->format('M d, Y') }}
                to
                {{ CarbonImmutable::parse($endDate)->format('M d, Y') }}
            @endif

            @if (trim($reportSearch) !== '')
                · Search: “{{ $reportSearch }}”
            @endif
        </p>

        @if ($printAll)
            <p class="mt-1 text-xs font-medium text-zinc-500">
                Full printable report · {{ $report['count'] }} matching row(s)
            </p>
        @endif
    </div>

    @if ($reportType === 'revenue')
        @php
            $metrics = $report['financial_metrics'] ?? [];
            $showOutstandingMetrics = (bool) ($report['show_outstanding_metrics'] ?? false);
        @endphp

        <div class="mb-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Verified Revenue</p>
                <p class="text-xl font-bold">
                    {{ $this->money($metrics['verified_revenue'] ?? $report['total']) }}
                </p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Verified Payments</p>
                <p class="text-xl font-bold">
                    {{ $metrics['verified_payment_count'] ?? $report['count'] }}
                </p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Booking Revenue</p>
                <p class="text-xl font-bold">
                    {{ $this->money($metrics['booking_revenue'] ?? 0) }}
                </p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Reservation Revenue</p>
                <p class="text-xl font-bold">
                    {{ $this->money($metrics['reservation_revenue'] ?? 0) }}
                </p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Entrance Revenue</p>
                <p class="text-xl font-bold">
                    {{ $this->money($metrics['entrance_revenue'] ?? 0) }}
                </p>
            </div>
        </div>

        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Cash</p>
                <p class="text-lg font-semibold">
                    {{ $this->money($metrics['cash_revenue'] ?? 0) }}
                </p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">GCash</p>
                <p class="text-lg font-semibold">
                    {{ $this->money($metrics['gcash_revenue'] ?? 0) }}
                </p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Other Modes</p>
                <p class="text-lg font-semibold">
                    {{ $this->money($metrics['other_mode_revenue'] ?? 0) }}
                </p>
            </div>
        </div>

        @if ($showOutstandingMetrics)
            <div class="mb-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div
                    class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/60 dark:bg-amber-950/30">
                    <p class="text-xs text-amber-700 dark:text-amber-300">
                        Booking Balance
                    </p>
                    <p class="text-lg font-semibold">
                        {{ $this->money($metrics['outstanding_booking_balance'] ?? 0) }}
                    </p>
                </div>

                <div
                    class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/60 dark:bg-amber-950/30">
                    <p class="text-xs text-amber-700 dark:text-amber-300">
                        Reservation Balance
                    </p>
                    <p class="text-lg font-semibold">
                        {{ $this->money($metrics['outstanding_reservation_balance'] ?? 0) }}
                    </p>
                </div>

                <div
                    class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/60 dark:bg-amber-950/30">
                    <p class="text-xs text-amber-700 dark:text-amber-300">
                        Entrance Balance
                    </p>
                    <p class="text-lg font-semibold">
                        {{ $this->money($metrics['outstanding_entrance_balance'] ?? 0) }}
                    </p>
                </div>

                <div
                    class="rounded-lg border border-amber-300 bg-amber-100 p-4 dark:border-amber-800 dark:bg-amber-950/50">
                    <p class="text-xs font-medium text-amber-800 dark:text-amber-200">
                        Total Outstanding
                    </p>
                    <p class="text-lg font-bold">
                        {{ $this->money($metrics['total_outstanding_balance'] ?? 0) }}
                    </p>
                </div>
            </div>
        @endif

        @if (trim($reportSearch) !== '')
            <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
                The summary cards cover all Verified payments in the selected date range.
                The table search currently matches {{ $report['count'] }} payment(s)
                totaling {{ $this->money($report['total']) }}.
            </p>
        @endif

        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b text-xs uppercase text-zinc-500">
                    <tr>
                        <th class="p-2">Date</th>
                        <th class="p-2">Receipt</th>
                        <th class="p-2">Target</th>
                        <th class="p-2">Guest</th>
                        <th class="p-2">Mode</th>
                        <th class="p-2">Handled By</th>
                        <th class="p-2 text-right">Amount</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($report['rows'] as $payment)
                        <tr>
                            <td class="p-2">{{ $payment->date_paid?->format('M d, Y') }}</td>
                            <td class="p-2">{{ $payment->p_ref_no }}</td>
                            <td class="p-2">{{ $this->paymentTargetLabel($payment) }}</td>
                            <td class="p-2">
                                {{ $this->guestName($payment->booking?->guest ?? ($payment->reservation?->guest ?? $payment->entranceSlip?->guest)) }}
                            </td>
                            <td class="p-2">{{ $payment->modeOfPayment?->mode_of_payment ?? 'Unknown' }}</td>
                            <td class="p-2">
                                {{ $this->staffName($payment->verifier ?? $payment->user, 'Guest submission') }}</td>
                            <td class="p-2 text-right">{{ $this->money($payment->amount_paid) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-4 text-center text-zinc-500">
                                No verified payments found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($reportType === 'booking_summary')
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Bookings</p>
                <p class="text-xl font-bold">{{ $report['count'] }}</p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Total Price</p>
                <p class="text-xl font-bold">{{ $this->money($report['total_price']) }}</p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Outstanding Due</p>
                <p class="text-xl font-bold">{{ $this->money($report['total_due']) }}</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b text-xs uppercase text-zinc-500">
                    <tr>
                        <th class="p-2">Date</th>
                        <th class="p-2">Booking Ref</th>
                        <th class="p-2">Guest</th>
                        <th class="p-2">Facilities</th>
                        <th class="p-2">Extra Guests</th>
                        <th class="p-2">Status</th>
                        <th class="p-2 text-right">Total</th>
                        <th class="p-2 text-right">Due</th>
                        <th class="p-2 print:hidden">Action</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($report['rows'] as $booking)
                        <tr>
                            <td class="p-2">{{ $booking->booking_date?->format('M d, Y') }}</td>
                            <td class="p-2">{{ $booking->b_ref_no }}</td>
                            <td class="p-2">{{ $this->guestName($booking->guest) }}</td>
                            <td class="p-2">
                                @foreach ($booking->details as $detail)
                                    {{ $detail->facility?->facility_name }}
                                    ({{ $detail->rate_type }})
                                    @if (!$loop->last)
                                        ,
                                    @endif
                                @endforeach
                            </td>
                            <td class="p-2">{{ $booking->no_of_extra_guests }}</td>
                            <td class="p-2">{{ $booking->status }}</td>
                            <td class="p-2 text-right">{{ $this->money($booking->total_price) }}</td>
                            <td class="p-2 text-right">{{ $this->money($booking->amount_due) }}</td>
                            <td class="p-2 print:hidden">
                                @if (Route::has('cashier.bookings.show'))
                                    <a href="{{ route('cashier.bookings.show', $booking->booking_id) }}" wire:navigate
                                        class="font-medium underline">
                                        View
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="p-4 text-center text-zinc-500">
                                No bookings found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($reportType === 'cancellation')
        <div class="mb-4 rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
            <p class="text-xs text-zinc-500">Cancelled Reservations</p>
            <p class="text-xl font-bold">{{ $report['count'] }}</p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b text-xs uppercase text-zinc-500">
                    <tr>
                        <th class="p-2">Cancelled</th>
                        <th class="p-2">Reservation Ref</th>
                        <th class="p-2">Guest</th>
                        <th class="p-2">Facilities</th>
                        <th class="p-2">Reason</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($report['rows'] as $reservation)
                        <tr>
                            <td class="p-2">{{ $reservation->cancelled_at?->format('M d, Y h:i A') }}</td>
                            <td class="p-2">{{ $reservation->r_ref_no }}</td>
                            <td class="p-2">{{ $this->guestName($reservation->guest) }}</td>
                            <td class="p-2">
                                @foreach ($reservation->details as $detail)
                                    {{ $detail->facility?->facility_name }}@if (!$loop->last)
                                        ,
                                    @endif
                                @endforeach
                            </td>
                            <td class="p-2">{{ $reservation->cancellation_reason }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-4 text-center text-zinc-500">
                                No cancelled reservations found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($reportType === 'damaged_amenities')
        <div class="mb-4 grid gap-3 md:grid-cols-2">
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Damage/Fine Records</p>
                <p class="text-xl font-bold">{{ $report['count'] }}</p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Total Charges</p>
                <p class="text-xl font-bold">{{ $this->money($report['total_charge']) }}</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b text-xs uppercase text-zinc-500">
                    <tr>
                        <th class="p-2">Date</th>
                        <th class="p-2">Booking</th>
                        <th class="p-2">Guest</th>
                        <th class="p-2">Facility</th>
                        <th class="p-2">Amenity / Fine</th>
                        <th class="p-2">Damage Type</th>
                        <th class="p-2 text-right">Quantity</th>
                        <th class="p-2">Reported By</th>
                        <th class="p-2 text-right">Charge</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($report['rows'] as $fine)
                        <tr>
                            <td class="p-2">{{ $fine->date_checked?->format('M d, Y') }}</td>
                            <td class="p-2">{{ $fine->booking?->b_ref_no }}</td>
                            <td class="p-2">{{ $this->guestName($fine->booking?->guest) }}</td>
                            <td class="p-2">{{ $fine->facility?->facility_name }}</td>
                            <td class="p-2">
                                {{ $fine->fine?->amenity?->amenityName?->amenity_name ?? $fine->fine?->situational_fine }}
                            </td>
                            <td class="p-2">{{ $fine->fine?->damageType?->damage_type ?? 'Situational' }}</td>
                            <td class="p-2 text-right">{{ $fine->quantity }}</td>
                            <td class="p-2">{{ $this->staffName($fine->reportedBy, 'N/A') }}</td>
                            <td class="p-2 text-right">{{ $this->money($fine->total_charge) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="p-4 text-center text-zinc-500">
                                No damaged amenities or fines found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($reportType === 'available_facilities')
        <div class="mb-4 grid gap-3 md:grid-cols-2">
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Available Facilities</p>
                <p class="text-xl font-bold">{{ $report['count'] }}</p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">By Type</p>
                <p class="text-sm">
                    @forelse ($report['by_type'] as $type => $count)
                        {{ $type }}: {{ $count }}@if (!$loop->last)
                            ,
                        @endif
                    @empty
                        No available facility
                    @endforelse
                </p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b text-xs uppercase text-zinc-500">
                    <tr>
                        <th class="p-2">Facility</th>
                        <th class="p-2">Type</th>
                        <th class="p-2">Size</th>
                        <th class="p-2">Capacity</th>
                        <th class="p-2">Rates</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($report['rows'] as $facility)
                        <tr>
                            <td class="p-2">{{ $facility->facility_name }}</td>
                            <td class="p-2">{{ $facility->facilityType?->facility_type }}</td>
                            <td class="p-2">{{ $facility->facility_size }}</td>
                            <td class="p-2">{{ $facility->capacity }}</td>
                            <td class="p-2">
                                @foreach ($facility->prices as $price)
                                    {{ $price->rate_type }}:
                                    {{ $this->money($price->facility_price) }}@if (!$loop->last)
                                        ,
                                    @endif
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-4 text-center text-zinc-500">
                                No available facilities found for the selected period.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($reportType === 'facility_utilization')
        <div class="mb-4 grid gap-3 md:grid-cols-2">
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Facilities With Booking Use</p>
                <p class="text-xl font-bold">{{ $report['count'] }}</p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Booked Facility Uses</p>
                <p class="text-xl font-bold">{{ $report['total_bookings'] }}</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b text-xs uppercase text-zinc-500">
                    <tr>
                        <th class="p-2">Facility</th>
                        <th class="p-2">Type</th>
                        <th class="p-2">Size</th>
                        <th class="p-2">Capacity</th>
                        <th class="p-2 text-right">Bookings</th>
                        <th class="p-2 text-right">Share of Uses</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($report['rows'] as $row)
                        <tr>
                            <td class="p-2">{{ $row->facility_name }}</td>
                            <td class="p-2">{{ $row->facility_type }}</td>
                            <td class="p-2">{{ $row->facility_size }}</td>
                            <td class="p-2">{{ $row->capacity }}</td>
                            <td class="p-2 text-right">{{ $row->booking_count }}</td>
                            <td class="p-2 text-right">{{ number_format($row->utilization_percentage, 2) }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-4 text-center text-zinc-500">
                                No utilization data found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($reportType === 'tourism')
        <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Paid Entrance Slips</p>
                <p class="text-xl font-bold">{{ $report['count'] }}</p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Total Guests</p>
                <p class="text-xl font-bold">{{ $report['total_guests'] }}</p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Male</p>
                <p class="text-xl font-bold">{{ $report['male'] }}</p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Female</p>
                <p class="text-xl font-bold">{{ $report['female'] }}</p>
            </div>

            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs text-zinc-500">Tourists</p>
                <p class="text-xl font-bold">{{ $report['tourist'] }}</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b text-xs uppercase text-zinc-500">
                    <tr>
                        <th class="p-2">Date</th>
                        <th class="p-2">Slip #</th>
                        <th class="p-2 text-right">Adult</th>
                        <th class="p-2 text-right">Children</th>
                        <th class="p-2 text-right">SC/PWD</th>
                        <th class="p-2 text-right">Male</th>
                        <th class="p-2 text-right">Female</th>
                        <th class="p-2 text-right">Tourist</th>
                        <th class="p-2">Created By</th>
                        <th class="p-2">Handled By</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($report['rows'] as $slip)
                        <tr>
                            <td class="p-2">{{ $slip->date_created?->format('M d, Y') }}</td>
                            <td class="p-2">#{{ $slip->entrance_slip_id }}</td>
                            <td class="p-2 text-right">{{ $slip->no_of_adult }}</td>
                            <td class="p-2 text-right">{{ $slip->no_of_children }}</td>
                            <td class="p-2 text-right">{{ $slip->no_of_PWD_SC }}</td>
                            <td class="p-2 text-right">{{ $slip->no_of_Male }}</td>
                            <td class="p-2 text-right">{{ $slip->no_of_Female }}</td>
                            <td class="p-2 text-right">{{ $slip->no_of_Tourist }}</td>
                            <td class="p-2">{{ $this->staffName($slip->createdBy, 'Unknown') }}</td>
                            <td class="p-2">{{ $this->staffName($slip->handledBy, 'Unknown') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="p-4 text-center text-zinc-500">
                                No paid entrance slips found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($report['rows'] instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
        <div
            class="mt-5 flex flex-col gap-3 border-t border-zinc-200 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800 print:hidden">
            <p class="text-sm text-zinc-500">
                Showing
                {{ $report['rows']->firstItem() ?? 0 }}
                to
                {{ $report['rows']->lastItem() ?? 0 }}
                of
                {{ $report['rows']->total() }}
                matching rows
            </p>

            {{ $report['rows']->links() }}
        </div>
    @endif
</div>
