<?php

use App\Services\ReportsService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Admin Reports - Olaer Spring Resort')] class extends Component
{
    use WithPagination;

    #[Url(as: 'report', except: 'revenue')]
    public string $reportType = 'revenue';

    #[Url(as: 'start', except: '')]
    public string $startDate = '';

    #[Url(as: 'end', except: '')]
    public string $endDate = '';

    #[Url(as: 'facility_type', except: '')]
    public string $facilityTypeId = '';

    #[Url(as: 'month', except: '')]
    public string $month = '';

    #[Url(as: 'year', except: '')]
    public string $year = '';

    #[Url(as: 'generated', except: false)]
    public bool $generated = false;

    #[Url(as: 'q', except: '')]
    public string $reportSearch = '';

    #[Url(as: 'per_page', except: 25)]
    public int $perPage = 25;

    #[Url(as: 'print_all', except: false)]
    public bool $printAll = false;

    public function mount(): void
    {
        $today = now()->toDateString();

        if ($this->startDate === '') {
            $this->startDate = $today;
        }

        if ($this->endDate === '') {
            $this->endDate = $today;
        }

        if ($this->month === '') {
            $this->month = (string) now()->month;
        }

        if ($this->year === '') {
            $this->year = (string) now()->year;
        }
    }

    public function with(): array
    {
        $service = app(ReportsService::class);

        return [
            'facilityTypes' => $service->facilityTypes(),
            'report' => $this->generated
                ? $this->buildReport($service)
                : null,
            'reportTitle' => $this->reportTitle(),
        ];
    }

    public function generate(): void
    {
        $rules = [
            'reportType' => [
                'required',
                Rule::in(['revenue', 'booking_summary', 'cancellation', 'damaged_amenities', 'available_facilities', 'facility_utilization', 'tourism']),
            ],
            'perPage' => ['required', 'integer', Rule::in([10, 25, 50, 100])],
            'reportSearch' => ['nullable', 'string', 'max:100'],
        ];

        if ($this->reportType === 'tourism') {
            $rules['month'] = ['required', 'integer', 'between:1,12'];
            $rules['year'] = ['required', 'integer', 'between:2000,2100'];
        } else {
            $rules['startDate'] = ['required', 'date'];
            $rules['endDate'] = [
                'required',
                'date',
                'after_or_equal:startDate',
            ];
        }

        if ($this->usesFacilityType()) {
            $rules['facilityTypeId'] = [
                'nullable',
                'integer',
                'exists:tbl_facility_type,facility_type_id',
            ];
        }

        $this->validate($rules);

        $this->generated = true;
        $this->printAll = false;
        $this->resetPage('reportPage');
    }

    public function clearReport(): void
    {
        $this->generated = false;
        $this->printAll = false;
        $this->reportSearch = '';
        $this->resetPage('reportPage');
    }

    public function updatedReportType(): void
    {
        $this->invalidateReport();
    }

    public function updatedStartDate(): void
    {
        $this->invalidateReport();
    }

    public function updatedEndDate(): void
    {
        $this->invalidateReport();
    }

    public function updatedFacilityTypeId(): void
    {
        $this->invalidateReport();
    }

    public function updatedMonth(): void
    {
        $this->invalidateReport();
    }

    public function updatedYear(): void
    {
        $this->invalidateReport();
    }

    public function updatedReportSearch(): void
    {
        $this->resetPage('reportPage');
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 25, 50, 100], true)) {
            $this->perPage = 25;
        }

        $this->resetPage('reportPage');
    }

    public function usesFacilityType(): bool
    {
        return in_array(
            $this->reportType,
            ['available_facilities', 'facility_utilization'],
            true,
        );
    }

    public function usesMonthYear(): bool
    {
        return $this->reportType === 'tourism';
    }

    public function money(mixed $amount): string
    {
        return '₱'.number_format((float) $amount, 2);
    }

    public function guestName(mixed $guest): string
    {
        if ($guest === null) {
            return 'Walk-in / Unknown';
        }

        return $guest->full_name
            ?? trim(implode(' ', array_filter([
                $guest->first_name,
                $guest->middle_name,
                $guest->last_name,
            ])))
            ?: 'Walk-in / Unknown';
    }

    public function staffName(
        mixed $staff,
        string $fallback = 'N/A',
    ): string {
        if ($staff === null) {
            return $fallback;
        }

        return $staff->full_name
            ?? trim(implode(' ', array_filter([
                $staff->first_name,
                $staff->middle_name,
                $staff->last_name,
            ])))
            ?: ($staff->username ?? $fallback);
    }

    public function paymentTargetLabel(mixed $payment): string
    {
        if ($payment->booking !== null) {
            return 'Booking '.$payment->booking->b_ref_no;
        }

        if ($payment->reservation !== null) {
            return 'Reservation '.$payment->reservation->r_ref_no;
        }

        if ($payment->entranceSlip !== null) {
            return 'Entrance Slip #'
                .$payment->entranceSlip->entrance_slip_id;
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

    public function printUrl(): string
    {
        return route('admin.reports.index', [
            'report' => $this->reportType,
            'start' => $this->startDate,
            'end' => $this->endDate,
            'facility_type' => $this->facilityTypeId,
            'month' => $this->month,
            'year' => $this->year,
            'generated' => 1,
            'q' => $this->reportSearch,
            'per_page' => $this->perPage,
            'print_all' => 1,
        ]);
    }

    public function paginatedUrl(): string
    {
        return route('admin.reports.index', [
            'report' => $this->reportType,
            'start' => $this->startDate,
            'end' => $this->endDate,
            'facility_type' => $this->facilityTypeId,
            'month' => $this->month,
            'year' => $this->year,
            'generated' => 1,
            'q' => $this->reportSearch,
            'per_page' => $this->perPage,
        ]);
    }

    private function invalidateReport(): void
    {
        $this->generated = false;
        $this->printAll = false;
        $this->resetPage('reportPage');
    }

    private function buildReport(ReportsService $service): array
    {
        $facilityTypeId = $this->facilityTypeId !== ''
            ? (int) $this->facilityTypeId
            : null;

        return match ($this->reportType) {
            'revenue' => $service->revenueReport(
                $this->startDate,
                $this->endDate,
                null,
                $this->perPage,
                $this->reportSearch,
                'reportPage',
                $this->printAll,
            ),
            'booking_summary' => $service->bookingSummaryReport(
                $this->startDate,
                $this->endDate,
                null,
                $this->perPage,
                $this->reportSearch,
                'reportPage',
                $this->printAll,
            ),
            'cancellation' => $service->cancellationReport(
                $this->startDate,
                $this->endDate,
                $this->perPage,
                $this->reportSearch,
                'reportPage',
                $this->printAll,
            ),
            'damaged_amenities' => $service->damagedAmenitiesReport(
                $this->startDate,
                $this->endDate,
                $this->perPage,
                $this->reportSearch,
                'reportPage',
                $this->printAll,
            ),
            'available_facilities' => $service->availableFacilitiesReport(
                $this->startDate,
                $this->endDate,
                $facilityTypeId,
                $this->perPage,
                $this->reportSearch,
                'reportPage',
                $this->printAll,
            ),
            'facility_utilization' => $service->facilityUtilizationReport(
                $this->startDate,
                $this->endDate,
                $facilityTypeId,
                $this->perPage,
                $this->reportSearch,
                'reportPage',
                $this->printAll,
            ),
            'tourism' => $service->tourismEnterpriseMonthlyReport(
                (int) $this->year,
                (int) $this->month,
                $this->perPage,
                $this->reportSearch,
                'reportPage',
                $this->printAll,
            ),
            default => [
                'rows' => collect(),
                'count' => 0,
            ],
        };
    }
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between print:hidden">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                Admin Reports
            </h1>

            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Generate, filter, paginate, and print management reports.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($generated && ! $printAll)
                <flux:button
                    href="{{ $this->printUrl() }}"
                    target="_blank"
                    variant="primary"
                >
                    Print Full Report
                </flux:button>
            @elseif ($generated && $printAll)
                <flux:button
                    type="button"
                    onclick="window.print()"
                    variant="primary"
                >
                    Print
                </flux:button>

                <flux:button
                    href="{{ $this->paginatedUrl() }}"
                    variant="ghost"
                >
                    Return to Paginated View
                </flux:button>
            @endif
        </div>
    </div>

    @if (! $printAll)
        <flux:card class="print:hidden">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
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
                            <option value="{{ $monthNumber }}">
                                {{ CarbonImmutable::create(2026, $monthNumber, 1)->format('F') }}
                            </option>
                        @endforeach
                    </flux:select>

                    <flux:input
                        wire:model.live="year"
                        label="Year"
                        type="number"
                        min="2000"
                        max="2100"
                    />
                @else
                    <flux:input
                        wire:model.live="startDate"
                        label="Start Date"
                        type="date"
                    />

                    <flux:input
                        wire:model.live="endDate"
                        label="End Date"
                        type="date"
                    />
                @endif

                @if ($this->usesFacilityType())
                    <flux:select
                        wire:model.live="facilityTypeId"
                        label="Facility Type"
                    >
                        <option value="">All facility types</option>

                        @foreach ($facilityTypes as $facilityType)
                            <option value="{{ $facilityType->facility_type_id }}">
                                {{ $facilityType->facility_type }}
                            </option>
                        @endforeach
                    </flux:select>
                @endif
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <flux:button wire:click="generate" variant="primary">
                    Generate Report
                </flux:button>

                <flux:button wire:click="clearReport" variant="ghost">
                    Clear
                </flux:button>
            </div>

            @error('reportType')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror

            @error('startDate')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror

            @error('endDate')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror

            @error('month')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror

            @error('year')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror

            @error('facilityTypeId')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </flux:card>
    @endif

    @if (! $generated)
        <div class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center text-sm text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
            Choose a report type and click <strong>Generate Report</strong>.
        </div>
    @else
        @if (! $printAll)
            <flux:card class="print:hidden">
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <flux:input
                        wire:model.live.debounce.300ms="reportSearch"
                        label="Search generated report"
                        placeholder="Reference, guest, facility, staff, or status"
                        clearable
                        class="xl:col-span-2"
                    />

                    <flux:select wire:model.live="perPage" label="Rows per page">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </flux:select>

                    <div class="flex items-end">
                        <flux:button
                            href="{{ $this->printUrl() }}"
                            target="_blank"
                            variant="ghost"
                            class="w-full"
                        >
                            Open Full Print View
                        </flux:button>
                    </div>
                </div>
            </flux:card>
        @endif

        @include('livewire.reports.partials.report-output')
    @endif
</div>
