<?php

use App\Services\ReportsService;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Admin Reports - Olaer Spring Resort')] class extends Component
{
    public string $reportType = 'revenue';
    public string $startDate = '';
    public string $endDate = '';
    public string $facilityTypeId = '';
    public string $month = '';
    public string $year = '';
    public bool $generated = false;

    public function mount(): void
    {
        $today = now()->toDateString();
        $this->startDate = $today;
        $this->endDate = $today;
        $this->month = (string) now()->month;
        $this->year = (string) now()->year;
    }

    public function with(): array
    {
        $service = app(ReportsService::class);

        return [
            'facilityTypes' => $service->facilityTypes(),
            'report' => $this->generated ? $this->buildReport($service) : null,
            'reportTitle' => $this->reportTitle(),
        ];
    }

    public function generate(): void
    {
        $rules = [
            'reportType' => ['required', 'string'],
        ];

        if ($this->reportType === 'tourism') {
            $rules['month'] = ['required', 'integer', 'between:1,12'];
            $rules['year'] = ['required', 'integer', 'between:2000,2100'];
        } else {
            $rules['startDate'] = ['required', 'date'];
            $rules['endDate'] = ['required', 'date', 'after_or_equal:startDate'];
        }

        if ($this->usesFacilityType()) {
            $rules['facilityTypeId'] = ['nullable', 'integer', 'exists:tbl_facility_type,facility_type_id'];
        }

        $this->validate($rules);
        $this->generated = true;
    }

    public function clearReport(): void
    {
        $this->generated = false;
    }

    public function updatedReportType(): void
    {
        $this->generated = false;
    }

    public function updatedStartDate(): void
    {
        $this->generated = false;
    }

    public function updatedEndDate(): void
    {
        $this->generated = false;
    }

    public function updatedFacilityTypeId(): void
    {
        $this->generated = false;
    }

    public function updatedMonth(): void
    {
        $this->generated = false;
    }

    public function updatedYear(): void
    {
        $this->generated = false;
    }

    public function usesFacilityType(): bool
    {
        return in_array($this->reportType, ['available_facilities', 'facility_utilization'], true);
    }

    public function usesMonthYear(): bool
    {
        return $this->reportType === 'tourism';
    }

    public function money(mixed $amount): string
    {
        return '₱' . number_format((float) $amount, 2);
    }

    public function guestName(mixed $guest): string
    {
        if ($guest === null) {
            return 'Walk-in / Unknown';
        }

        return trim($guest->first_name . ' ' . ($guest->middle_name ? $guest->middle_name . ' ' : '') . $guest->last_name);
    }

    public function paymentTargetLabel(mixed $payment): string
    {
        if ($payment->booking !== null) {
            return 'Booking ' . $payment->booking->b_ref_no;
        }

        if ($payment->reservation !== null) {
            return 'Reservation ' . $payment->reservation->r_ref_no;
        }

        if ($payment->entranceSlip !== null) {
            return 'Entrance Slip #' . $payment->entranceSlip->entrance_slip_id;
        }

        return 'Unknown';
    }

    public function reportTitle(): string
    {
        return match ($this->reportType) {
            'revenue' => 'Revenue Report',
            'booking_summary' => 'Booking Summary Report',
            'cancellation' => 'Cancellation Report',
            'damaged_amenities' => 'Damaged Amenities Report',
            'available_facilities' => 'Available Facilities Report',
            'facility_utilization' => 'Facility Utilization Report',
            'tourism' => 'Tourism Enterprise Monthly Report',
            default => 'Report',
        };
    }

    private function buildReport(ReportsService $service): array
    {
        $facilityTypeId = $this->facilityTypeId !== '' ? (int) $this->facilityTypeId : null;

        return match ($this->reportType) {
            'revenue' => $service->revenueReport($this->startDate, $this->endDate),
            'booking_summary' => $service->bookingSummaryReport($this->startDate, $this->endDate),
            'cancellation' => $service->cancellationReport($this->startDate, $this->endDate),
            'damaged_amenities' => $service->damagedAmenitiesReport($this->startDate, $this->endDate),
            'available_facilities' => $service->availableFacilitiesReport($this->startDate, $this->endDate, $facilityTypeId),
            'facility_utilization' => $service->facilityUtilizationReport($this->startDate, $this->endDate, $facilityTypeId),
            'tourism' => $service->tourismEnterpriseMonthlyReport((int) $this->year, (int) $this->month),
            default => ['rows' => collect(), 'count' => 0],
        };
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">Admin Reports</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">Generate and print operational reports for management review.</p>
        </div>

        <flux:button type="button" onclick="window.print()" variant="primary" :disabled="! $generated">
            Print Report
        </flux:button>
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 print:hidden">
        <div class="grid gap-4 md:grid-cols-4">
            <flux:select wire:model.live="reportType" label="Report Type">
                <option value="revenue">Revenue</option>
                <option value="booking_summary">Booking Summary</option>
                <option value="cancellation">Cancellation</option>
                <option value="damaged_amenities">Damaged Amenities</option>
                <option value="available_facilities">Available Facilities</option>
                <option value="facility_utilization">Facility Utilization</option>
                <option value="tourism">Tourism Enterprise Monthly</option>
            </flux:select>

            @if ($this->usesMonthYear())
                <flux:select wire:model.live="month" label="Month">
                    @foreach (range(1, 12) as $monthNumber)
                        <option value="{{ $monthNumber }}">{{ CarbonImmutable::create(2026, $monthNumber, 1)->format('F') }}</option>
                    @endforeach
                </flux:select>

                <flux:input wire:model.live="year" label="Year" type="number" min="2000" max="2100" />
            @else
                <flux:input wire:model.live="startDate" label="Start Date" type="date" />
                <flux:input wire:model.live="endDate" label="End Date" type="date" />
            @endif

            @if ($this->usesFacilityType())
                <flux:select wire:model.live="facilityTypeId" label="Facility Type">
                    <option value="">All facility types</option>
                    @foreach ($facilityTypes as $facilityType)
                        <option value="{{ $facilityType->facility_type_id }}">{{ $facilityType->facility_type }}</option>
                    @endforeach
                </flux:select>
            @endif
        </div>

        <div class="mt-4 flex gap-2">
            <flux:button wire:click="generate" variant="primary">Generate Report</flux:button>
            <flux:button wire:click="clearReport" variant="ghost">Clear</flux:button>
        </div>

        @error('reportType') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        @error('startDate') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        @error('endDate') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        @error('month') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        @error('year') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        @error('facilityTypeId') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    @if (! $generated)
        <div class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center text-sm text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
            Choose a report type and click <strong>Generate Report</strong>.
        </div>
    @else
        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 print:border-0 print:shadow-none">
            <div class="mb-5 border-b border-zinc-200 pb-4 dark:border-zinc-700">
                <h2 class="text-xl font-semibold text-zinc-900 dark:text-zinc-100">{{ $reportTitle }}</h2>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    @if ($this->usesMonthYear())
                        Period: {{ CarbonImmutable::create((int) $year, (int) $month, 1)->format('F Y') }}
                    @else
                        Period: {{ CarbonImmutable::parse($startDate)->format('M d, Y') }} to {{ CarbonImmutable::parse($endDate)->format('M d, Y') }}
                    @endif
                </p>
            </div>

            @if ($reportType === 'revenue')
                <div class="mb-4 grid gap-3 md:grid-cols-3">
                    <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800"><p class="text-xs text-zinc-500">Total Revenue</p><p class="text-xl font-bold">{{ $this->money($report['total']) }}</p></div>
                    <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800"><p class="text-xs text-zinc-500">Payment Count</p><p class="text-xl font-bold">{{ $report['count'] }}</p></div>
                    <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800"><p class="text-xs text-zinc-500">Modes</p><p class="text-sm">@foreach ($report['by_mode'] as $mode => $amount) {{ $mode }}: {{ $this->money($amount) }}@if (! $loop->last), @endif @endforeach</p></div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="border-b text-xs uppercase text-zinc-500"><tr><th class="p-2">Date</th><th class="p-2">Ref No.</th><th class="p-2">Target</th><th class="p-2">Guest</th><th class="p-2">Mode</th><th class="p-2">Cashier</th><th class="p-2 text-right">Amount</th></tr></thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($report['rows'] as $payment)
                                <tr><td class="p-2">{{ $payment->date_paid?->format('M d, Y') }}</td><td class="p-2">{{ $payment->p_ref_no }}</td><td class="p-2">{{ $this->paymentTargetLabel($payment) }}</td><td class="p-2">{{ $this->guestName($payment->booking?->guest ?? $payment->reservation?->guest ?? $payment->entranceSlip?->guest) }}</td><td class="p-2">{{ $payment->modeOfPayment?->mode_of_payment }}</td><td class="p-2">{{ $payment->user?->username ?? 'Guest upload' }}</td><td class="p-2 text-right">{{ $this->money($payment->amount_paid) }}</td></tr>
                            @empty
                                <tr><td colspan="7" class="p-4 text-center text-zinc-500">No verified payments found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($reportType === 'booking_summary')
                <div class="mb-4 grid gap-3 md:grid-cols-3">
                    <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800"><p class="text-xs text-zinc-500">Bookings</p><p class="text-xl font-bold">{{ $report['count'] }}</p></div>
                    <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800"><p class="text-xs text-zinc-500">Total Price</p><p class="text-xl font-bold">{{ $this->money($report['total_price']) }}</p></div>
                    <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800"><p class="text-xs text-zinc-500">Outstanding Due</p><p class="text-xl font-bold">{{ $this->money($report['total_due']) }}</p></div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm"><thead class="border-b text-xs uppercase text-zinc-500"><tr><th class="p-2">Date</th><th class="p-2">Booking Ref</th><th class="p-2">Guest</th><th class="p-2">Facilities</th><th class="p-2">Status</th><th class="p-2 text-right">Total</th><th class="p-2 text-right">Due</th></tr></thead><tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($report['rows'] as $booking)
                            <tr><td class="p-2">{{ $booking->booking_date?->format('M d, Y') }}</td><td class="p-2">{{ $booking->b_ref_no }}</td><td class="p-2">{{ $this->guestName($booking->guest) }}</td><td class="p-2">@foreach ($booking->details as $detail) {{ $detail->facility?->facility_name }} ({{ $detail->rate_type }})@if (! $loop->last), @endif @endforeach</td><td class="p-2">{{ $booking->status }}</td><td class="p-2 text-right">{{ $this->money($booking->total_price) }}</td><td class="p-2 text-right">{{ $this->money($booking->amount_due) }}</td></tr>
                        @empty
                            <tr><td colspan="7" class="p-4 text-center text-zinc-500">No bookings found.</td></tr>
                        @endforelse
                    </tbody></table>
                </div>
            @endif

            @if ($reportType === 'cancellation')
                <div class="mb-4 rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800"><p class="text-xs text-zinc-500">Cancelled Reservations</p><p class="text-xl font-bold">{{ $report['count'] }}</p></div>
                <div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="border-b text-xs uppercase text-zinc-500"><tr><th class="p-2">Cancelled</th><th class="p-2">Reservation Ref</th><th class="p-2">Guest</th><th class="p-2">Facilities</th><th class="p-2">Reason</th></tr></thead><tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($report['rows'] as $reservation)
                        <tr><td class="p-2">{{ $reservation->cancelled_at?->format('M d, Y') }}</td><td class="p-2">{{ $reservation->r_ref_no }}</td><td class="p-2">{{ $this->guestName($reservation->guest) }}</td><td class="p-2">@foreach ($reservation->details as $detail) {{ $detail->facility?->facility_name }}@if (! $loop->last), @endif @endforeach</td><td class="p-2">{{ $reservation->cancellation_reason }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="p-4 text-center text-zinc-500">No cancelled reservations found.</td></tr>
                    @endforelse
                </tbody></table></div>
            @endif

            @if ($reportType === 'damaged_amenities')
                <div class="mb-4 grid gap-3 md:grid-cols-2"><div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800"><p class="text-xs text-zinc-500">Damage/Fine Records</p><p class="text-xl font-bold">{{ $report['count'] }}</p></div><div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800"><p class="text-xs text-zinc-500">Total Charges</p><p class="text-xl font-bold">{{ $this->money($report['total_charge']) }}</p></div></div>
                <div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="border-b text-xs uppercase text-zinc-500"><tr><th class="p-2">Date</th><th class="p-2">Booking</th><th class="p-2">Guest</th><th class="p-2">Facility</th><th class="p-2">Amenity / Fine</th><th class="p-2">Damage Type</th><th class="p-2">Reported By</th><th class="p-2 text-right">Charge</th></tr></thead><tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($report['rows'] as $fine)
                        <tr><td class="p-2">{{ $fine->date_checked?->format('M d, Y') }}</td><td class="p-2">{{ $fine->booking?->b_ref_no }}</td><td class="p-2">{{ $this->guestName($fine->booking?->guest) }}</td><td class="p-2">{{ $fine->facility?->facility_name }}</td><td class="p-2">{{ $fine->fine?->amenity?->amenityName?->amenity_name ?? $fine->fine?->situational_fine }}</td><td class="p-2">{{ $fine->fine?->damageType?->damage_type ?? 'Situational' }}</td><td class="p-2">{{ $fine->reportedBy?->username ?? 'N/A' }}</td><td class="p-2 text-right">{{ $this->money($fine->total_charge) }}</td></tr>
                    @empty
                        <tr><td colspan="8" class="p-4 text-center text-zinc-500">No damaged amenities/fines found.</td></tr>
                    @endforelse
                </tbody></table></div>
            @endif

            @if ($reportType === 'available_facilities')
                <div class="mb-4 rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800"><p class="text-xs text-zinc-500">Available Facilities</p><p class="text-xl font-bold">{{ $report['count'] }}</p></div>
                <div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="border-b text-xs uppercase text-zinc-500"><tr><th class="p-2">Facility</th><th class="p-2">Type</th><th class="p-2">Size</th><th class="p-2">Capacity</th><th class="p-2">Rates</th></tr></thead><tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($report['rows'] as $facility)
                        <tr><td class="p-2">{{ $facility->facility_name }}</td><td class="p-2">{{ $facility->facilityType?->facility_type }}</td><td class="p-2">{{ $facility->facility_size }}</td><td class="p-2">{{ $facility->capacity }}</td><td class="p-2">@foreach ($facility->prices as $price) {{ $price->rate_type }}: {{ $this->money($price->facility_price) }}@if (! $loop->last), @endif @endforeach</td></tr>
                    @empty
                        <tr><td colspan="5" class="p-4 text-center text-zinc-500">No available facilities found for the selected period.</td></tr>
                    @endforelse
                </tbody></table></div>
            @endif

            @if ($reportType === 'facility_utilization')
                <div class="mb-4 rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800"><p class="text-xs text-zinc-500">Booked Facility Uses</p><p class="text-xl font-bold">{{ $report['total_bookings'] }}</p></div>
                <div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="border-b text-xs uppercase text-zinc-500"><tr><th class="p-2">Facility</th><th class="p-2">Type</th><th class="p-2">Size</th><th class="p-2">Capacity</th><th class="p-2 text-right">Bookings</th><th class="p-2 text-right">Share</th></tr></thead><tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($report['rows'] as $row)
                        <tr><td class="p-2">{{ $row->facility_name }}</td><td class="p-2">{{ $row->facility_type }}</td><td class="p-2">{{ $row->facility_size }}</td><td class="p-2">{{ $row->capacity }}</td><td class="p-2 text-right">{{ $row->booking_count }}</td><td class="p-2 text-right">{{ number_format($row->utilization_percentage, 2) }}%</td></tr>
                    @empty
                        <tr><td colspan="6" class="p-4 text-center text-zinc-500">No utilization data found.</td></tr>
                    @endforelse
                </tbody></table></div>
            @endif

            @if ($reportType === 'tourism')
                <div class="mb-4 grid gap-3 md:grid-cols-4"><div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800"><p class="text-xs text-zinc-500">Entrance Slips</p><p class="text-xl font-bold">{{ $report['count'] }}</p></div><div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800"><p class="text-xs text-zinc-500">Male</p><p class="text-xl font-bold">{{ $report['male'] }}</p></div><div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800"><p class="text-xs text-zinc-500">Female</p><p class="text-xl font-bold">{{ $report['female'] }}</p></div><div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800"><p class="text-xs text-zinc-500">Tourists</p><p class="text-xl font-bold">{{ $report['tourist'] }}</p></div></div>
                <div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="border-b text-xs uppercase text-zinc-500"><tr><th class="p-2">Date</th><th class="p-2">Slip #</th><th class="p-2 text-right">Adult</th><th class="p-2 text-right">Children</th><th class="p-2 text-right">SC/PWD</th><th class="p-2 text-right">Male</th><th class="p-2 text-right">Female</th><th class="p-2 text-right">Tourist</th></tr></thead><tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($report['rows'] as $slip)
                        <tr><td class="p-2">{{ $slip->date_created?->format('M d, Y') }}</td><td class="p-2">#{{ $slip->entrance_slip_id }}</td><td class="p-2 text-right">{{ $slip->no_of_adult }}</td><td class="p-2 text-right">{{ $slip->no_of_children }}</td><td class="p-2 text-right">{{ $slip->no_of_PWD_SC }}</td><td class="p-2 text-right">{{ $slip->no_of_Male }}</td><td class="p-2 text-right">{{ $slip->no_of_Female }}</td><td class="p-2 text-right">{{ $slip->no_of_Tourist }}</td></tr>
                    @empty
                        <tr><td colspan="8" class="p-4 text-center text-zinc-500">No paid entrance slips found.</td></tr>
                    @endforelse
                </tbody></table></div>
            @endif
        </div>
    @endif
</div>
