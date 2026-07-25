<?php

use App\Models\BookingDetail;
use App\Models\FacilityInspection;
use App\Models\FacilityInspectionRequest;
use App\Models\GuestFine;
use App\Services\CheckOutWorkflowService;
use App\Services\CheckOutInspectionRequestService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Cashier Check-out - Olaer Spring Resort')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'list', except: 'eligible')]
    public string $statusFilter = 'eligible';

    #[Url(as: 'stage', except: 'all')]
    public string $stageFilter = 'all';

    #[Url(as: 'departure', except: 'all')]
    public string $dateFilter = 'all';

    #[Url(as: 'sort', except: 'check_out_date')]
    public string $sortField = 'check_out_date';

    #[Url(as: 'direction', except: 'asc')]
    public string $sortDirection = 'asc';

    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    public ?int $selectedBookingDetailsId = null;
    public ?int $selectedBookingId = null;
    public string $selectedLabel = '';
    public float $selectedBookingAmountDue = 0.00;

    public function mount(): void
    {
        $bookingId = request()->integer('booking');

        if ($bookingId <= 0) {
            return;
        }

        $checkedInDetails = BookingDetail::query()
            ->with('booking')
            ->where('booking_id', $bookingId)
            ->where('status', 'Checked-in')
            ->whereHas('booking', function ($query): void {
                $query->whereNotIn('status', ['Cancelled', 'Checked-out']);
            })
            ->get();

        $bookingReference = $checkedInDetails->first()?->booking?->b_ref_no;

        if (filled($bookingReference)) {
            $this->search = (string) $bookingReference;
        }

        if ($checkedInDetails->count() === 1) {
            $this->selectCheckOut((int) $checkedInDetails->first()->booking_details_id);
        }
    }

    public function with(): array
    {
        return [
            'bookingDetails' => $this->bookingDetails(),
            'selectedGuestFines' => $this->selectedGuestFines(),
            'selectedInspection' => $this->selectedInspection(),
            'selectedInspectionRequest' => $this->selectedInspectionRequest(),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->cancelSelection();

        if ($this->statusFilter === 'checked_out') {
            $this->stageFilter = 'all';
        }

        $this->resetPage();
    }

    public function updatedStageFilter(): void
    {
        $this->cancelSelection();
        $this->resetPage();
    }

    public function updatedDateFilter(): void
    {
        $this->cancelSelection();
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 25, 50, 100], true)) {
            $this->perPage = 10;
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'eligible';
        $this->stageFilter = 'all';
        $this->dateFilter = 'all';
        $this->sortField = 'check_out_date';
        $this->sortDirection = 'asc';
        $this->perPage = 10;
        $this->cancelSelection();

        $this->resetPage();
    }

    public function refreshSelectedState(): void
    {
        if ($this->selectedBookingDetailsId === null) {
            return;
        }

        $detail = BookingDetail::query()
            ->with(['booking.guest', 'facility'])
            ->find($this->selectedBookingDetailsId);

        if ($detail === null) {
            $this->cancelSelection();
            return;
        }

        $guest = $detail->booking?->guest;
        $facilityName = $detail->facility?->facility_name ?? 'No facility';

        $this->selectedBookingId = (int) $detail->booking_id;
        $this->selectedBookingAmountDue =
            (float) ($detail->booking?->amount_due ?? 0);

        $this->selectedLabel =
            ($detail->booking?->b_ref_no ?? 'Unknown booking')
            .' - '
            .trim(($guest?->first_name ?? '').' '.($guest?->last_name ?? ''))
            .' - '
            .$facilityName;
    }

    public function sortBy(string $field): void
    {
        $allowed = ['b_ref_no', 'guest_name', 'facility_name', 'check_in_date', 'check_out_date', 'amount_due', 'status'];

        if (! in_array($field, $allowed, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function selectCheckOut(int $bookingDetailsId): void
    {
        $detail = BookingDetail::query()
            ->with(['booking.guest', 'facility'])
            ->findOrFail($bookingDetailsId);

        $guest = $detail->booking->guest;
        $facilityName = $detail->facility !== null ? $detail->facility->facility_name : 'No facility';

        $this->selectedBookingDetailsId = $bookingDetailsId;
        $this->selectedBookingId = (int) $detail->booking_id;
        $this->selectedBookingAmountDue = (float) $detail->booking->amount_due;
        $this->selectedLabel = $detail->booking->b_ref_no . ' - ' . $guest->first_name . ' ' . $guest->last_name . ' - ' . $facilityName;
    }

    public function cancelSelection(): void
    {
        $this->selectedBookingDetailsId = null;
        $this->selectedBookingId = null;
        $this->selectedLabel = '';
        $this->selectedBookingAmountDue = 0.00;
    }


    public function sendInspectionRequest(CheckOutInspectionRequestService $inspectionRequestService): void
    {
        $validated = $this->validate([
            'selectedBookingDetailsId' => ['required', 'integer', 'exists:tbl_booking_details,booking_details_id'],
        ]);

        try {
            $inspectionRequestService->requestInspection(
                (int) $validated['selectedBookingDetailsId'],
                (int) Auth::id()
            );

            $this->refreshSelectedState();

            session()->flash('success', 'Inspection request sent to maintenance.');
        } catch (\Throwable $exception) {
            $this->addError('checkOut', $exception->getMessage());
        }
    }

    public function confirmCheckOut(CheckOutWorkflowService $checkOutWorkflow): void
    {
        $validated = $this->validate([
            'selectedBookingDetailsId' => ['required', 'integer', 'exists:tbl_booking_details,booking_details_id'],
        ]);

        try {
            $checkOutWorkflow->checkOutBookingDetail(
                (int) $validated['selectedBookingDetailsId'],
                (int) Auth::id()
            );

            $this->cancelSelection();
            $this->resetPage();
            session()->flash('success', 'Guest checked out successfully. Facility is now available.');
        } catch (\Throwable $exception) {
            $this->addError('checkOut', $exception->getMessage());
        }
    }

    public function formatFineLabel(?\App\Models\Fine $fine): string
    {
        if ($fine === null) {
            return 'Unknown fine';
        }

        $charge = '₱' . number_format((float) $fine->fine_charge, 2);

        if (in_array((string) $fine->fine_type, ['Amenity', 'Amenity Fine'], true)) {
            $amenityLabel = 'Amenity';
            $damageLabel = 'Damage';

            if ($fine->amenity !== null && $fine->amenity->amenityName !== null) {
                $amenityLabel = (string) $fine->amenity->amenityName->amenity_name;
            }

            if ($fine->damageType !== null) {
                $damageLabel = (string) $fine->damageType->damage_type;
            }

            return $amenityLabel . ' / ' . $damageLabel . ' - ' . $charge;
        }

        return (string) $fine->situational_fine . ' - ' . $charge;
    }

    public function sortIndicator(string $field): string
    {
        if ($this->sortField !== $field) {
            return '↕';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    public function workflowStage(BookingDetail $detail): string
    {
        if ($detail->status === 'Checked-out') {
            return 'Checked-out';
        }

        $requestStatus =
            $detail->getAttribute('latest_request_status');

        $inspectionStatus =
            $detail->getAttribute('latest_inspection_status');

        if (! filled($requestStatus)) {
            return 'Needs Inspection Request';
        }

        if ($requestStatus !== 'Completed') {
            return 'Inspection '.$requestStatus;
        }

        if (! filled($inspectionStatus)) {
            return 'Waiting for Inspection Result';
        }

        if ((float) $detail->booking->amount_due > 0) {
            return 'Payment Due';
        }

        return 'Ready for Check-out';
    }

    public function workflowStageColor(string $stage): string
    {
        return match ($stage) {
            'Ready for Check-out' => 'green',
            'Checked-out' => 'blue',
            'Payment Due' => 'red',
            'Needs Inspection Request' => 'amber',
            'Waiting for Inspection Result' => 'amber',
            default => str_starts_with($stage, 'Inspection ')
                ? 'purple'
                : 'zinc',
        };
    }

    private function bookingDetails(): LengthAwarePaginator
    {
        $query = BookingDetail::query()
            ->select('tbl_booking_details.*')
            ->join('tbl_booking', 'tbl_booking.booking_id', '=', 'tbl_booking_details.booking_id')
            ->join('tbl_guest', 'tbl_guest.guest_id', '=', 'tbl_booking.guest_id')
            ->leftJoin('tbl_facility', 'tbl_facility.facility_id', '=', 'tbl_booking_details.facility_id')
            ->with(['booking.guest', 'facility.facilityType'])
            ->addSelect([
                'latest_request_status' =>
                    FacilityInspectionRequest::query()
                        ->select('status')
                        ->whereColumn(
                            'booking_details_id',
                            'tbl_booking_details.booking_details_id',
                        )
                        ->latest('facility_inspection_request_id')
                        ->limit(1),
                'latest_inspection_status' =>
                    FacilityInspection::query()
                        ->select('inspection_status')
                        ->whereColumn(
                            'booking_details_id',
                            'tbl_booking_details.booking_details_id',
                        )
                        ->latest('facility_inspection_id')
                        ->limit(1),
            ]);

        if ($this->statusFilter === 'checked_out') {
            $query->where('tbl_booking_details.status', 'Checked-out');
        } else {
            $query->where('tbl_booking_details.status', 'Checked-in')
                ->whereNotIn('tbl_booking.status', ['Cancelled', 'Checked-out']);
        }

        $searchText = trim($this->search);

        if ($searchText !== '') {
            $needle = '%' . $searchText . '%';

            $query->where(function ($query) use ($needle): void {
                $query->where('tbl_booking.b_ref_no', 'like', $needle)
                    ->orWhere('tbl_guest.first_name', 'like', $needle)
                    ->orWhere('tbl_guest.middle_name', 'like', $needle)
                    ->orWhere('tbl_guest.last_name', 'like', $needle)
                    ->orWhere('tbl_guest.contact_no', 'like', $needle)
                    ->orWhere('tbl_guest.email', 'like', $needle)
                    ->orWhere('tbl_facility.facility_name', 'like', $needle);
            });
        }

        if ($this->dateFilter === 'today') {
            $query->whereDate(
                'tbl_booking_details.check_out_date',
                now()->toDateString(),
            );
        } elseif ($this->dateFilter === 'upcoming') {
            $query->whereDate(
                'tbl_booking_details.check_out_date',
                '>',
                now()->toDateString(),
            );
        } elseif ($this->dateFilter === 'overdue') {
            $query->whereDate(
                'tbl_booking_details.check_out_date',
                '<',
                now()->toDateString(),
            );
        }

        if (
            $this->statusFilter === 'eligible'
            && $this->stageFilter !== 'all'
        ) {
            if ($this->stageFilter === 'needs_request') {
                $query->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('tbl_facility_inspection_request as request_filter')
                        ->whereColumn(
                            'request_filter.booking_details_id',
                            'tbl_booking_details.booking_details_id',
                        );
                });
            } elseif ($this->stageFilter === 'waiting') {
                $query->whereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('tbl_facility_inspection_request as request_filter')
                        ->whereColumn(
                            'request_filter.booking_details_id',
                            'tbl_booking_details.booking_details_id',
                        )
                        ->where(
                            'request_filter.status',
                            '!=',
                            'Completed',
                        );
                });
            } elseif ($this->stageFilter === 'payment_due') {
                $query->where(
                    'tbl_booking.amount_due',
                    '>',
                    0,
                )->whereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('tbl_facility_inspection_request as request_filter')
                        ->whereColumn(
                            'request_filter.booking_details_id',
                            'tbl_booking_details.booking_details_id',
                        )
                        ->where(
                            'request_filter.status',
                            'Completed',
                        );
                });
            } elseif ($this->stageFilter === 'ready') {
                $query->where(
                    'tbl_booking.amount_due',
                    '<=',
                    0,
                )
                    ->whereExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('tbl_facility_inspection_request as request_filter')
                            ->whereColumn(
                                'request_filter.booking_details_id',
                                'tbl_booking_details.booking_details_id',
                            )
                            ->where(
                                'request_filter.status',
                                'Completed',
                            );
                    })
                    ->whereExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('tbl_facility_inspection as inspection_filter')
                            ->whereColumn(
                                'inspection_filter.booking_details_id',
                                'tbl_booking_details.booking_details_id',
                            );
                    });
            }
        }

        if ($this->sortField === 'guest_name') {
            $query->orderBy('tbl_guest.last_name', $this->sortDirection)
                ->orderBy('tbl_guest.first_name', $this->sortDirection);
        } elseif ($this->sortField === 'facility_name') {
            $query->orderBy('tbl_facility.facility_name', $this->sortDirection);
        } else {
            $sortMap = [
                'b_ref_no' => 'tbl_booking.b_ref_no',
                'check_in_date' => 'tbl_booking_details.check_in_date',
                'check_out_date' => 'tbl_booking_details.check_out_date',
                'amount_due' => 'tbl_booking.amount_due',
                'status' => 'tbl_booking_details.status',
            ];

            $sortColumn = $sortMap[$this->sortField] ?? 'tbl_booking_details.check_out_date';
            $query->orderBy($sortColumn, $this->sortDirection);
        }

        $perPage = in_array($this->perPage, [10, 25, 50, 100], true)
            ? $this->perPage
            : 10;

        return $query->paginate($perPage);
    }

    private function selectedGuestFines(): Collection
    {
        if ($this->selectedBookingId === null) {
            return new Collection();
        }

        $detail = BookingDetail::query()->find($this->selectedBookingDetailsId);

        if ($detail === null) {
            return new Collection();
        }

        return GuestFine::query()
            ->with(['fine.amenity.amenityName', 'fine.damageType', 'facility', 'reportedBy'])
            ->where('booking_id', $this->selectedBookingId)
            ->where('facility_id', $detail->facility_id)
            ->latest('guest_fine_id')
            ->get();
    }


    private function selectedInspectionRequest(): ?FacilityInspectionRequest
    {
        if ($this->selectedBookingDetailsId === null) {
            return null;
        }

        return FacilityInspectionRequest::query()
            ->with(['requestedBy', 'assignedTo', 'inspection'])
            ->where('booking_details_id', $this->selectedBookingDetailsId)
            ->latest('facility_inspection_request_id')
            ->first();
    }

    private function selectedInspection(): ?FacilityInspection
    {
        if ($this->selectedBookingDetailsId === null) {
            return null;
        }

        return FacilityInspection::query()
            ->with('inspectedBy')
            ->where('booking_details_id', $this->selectedBookingDetailsId)
            ->first();
    }
};
?>

<div class="space-y-6" wire:poll.10s.visible="refreshSelectedState">
    <x-staff-page-header
        eyebrow="Cashier operations"
        title="Cashier Check-out"
        description="Verify maintenance inspection and payment status before releasing the facility."
    />

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @error('checkOut')
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            {{ $message }}
        </div>
    @enderror

    @if ($selectedBookingDetailsId !== null)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-sm font-medium text-amber-900 dark:text-amber-100">Selected check-out</p>
                    <p class="text-sm text-amber-800 dark:text-amber-200">{{ $selectedLabel }}</p>
                    <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
                        Current balance: <span class="font-semibold">₱{{ number_format($selectedBookingAmountDue, 2) }}</span>
                    </p>

                    @if ($selectedInspectionRequest === null)
                        <p class="mt-1 text-xs font-semibold text-red-700 dark:text-red-300">No inspection request has been sent yet.</p>
                    @else
                        <p class="mt-1 text-xs text-amber-800 dark:text-amber-200">
                            Inspection request: <span class="font-semibold">{{ $selectedInspectionRequest->status }}</span>
                            requested by {{ $selectedInspectionRequest->requestedBy?->first_name }} {{ $selectedInspectionRequest->requestedBy?->last_name }}
                            @if ($selectedInspectionRequest->requested_at)
                                on {{ $selectedInspectionRequest->requested_at->format('M d, Y h:i A') }}
                            @endif
                        </p>
                    @endif

                    @if ($selectedInspection === null)
                        <p class="mt-1 text-xs font-semibold text-red-700 dark:text-red-300">Maintenance inspection result is still required before check-out.</p>
                    @else
                        <p class="mt-1 text-xs text-amber-800 dark:text-amber-200">
                            Inspection result: <span class="font-semibold">{{ $selectedInspection->inspection_status }}</span>
                            by {{ $selectedInspection->inspectedBy?->first_name }} {{ $selectedInspection->inspectedBy?->last_name }}
                            @if ($selectedInspection->inspected_at)
                                on {{ $selectedInspection->inspected_at->format('M d, Y h:i A') }}
                            @endif
                        </p>
                        @if ($selectedInspection->remarks)
                            <p class="mt-1 text-xs text-amber-800 dark:text-amber-200">Remarks: {{ $selectedInspection->remarks }}</p>
                        @endif
                    @endif

                    @if ($selectedBookingAmountDue > 0)
                        <p class="mt-1 text-xs font-semibold text-red-700 dark:text-red-300">Settle this balance in the Payment module before check-out.</p>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    @if ($selectedBookingId !== null && Route::has('cashier.bookings.show'))
                        <flux:button
                            href="{{ route('cashier.bookings.show', $selectedBookingId) }}"
                            wire:navigate
                            variant="ghost"
                        >
                            Booking Workspace
                        </flux:button>
                    @endif

                    @if (
                        $selectedBookingId !== null
                        && $selectedBookingAmountDue > 0
                        && Route::has('cashier.payments.index')
                    )
                        <flux:button
                            href="{{ route('cashier.payments.index', ['booking' => $selectedBookingId]) }}"
                            wire:navigate
                            variant="primary"
                        >
                            Settle Payment
                        </flux:button>
                    @endif

                    @if ($selectedInspectionRequest === null)
                        <flux:button wire:click="sendInspectionRequest">
                            Send Inspection Request
                        </flux:button>
                    @elseif ($selectedInspectionRequest->status !== 'Completed')
                        <flux:button disabled>
                            Waiting for Maintenance
                        </flux:button>
                    @endif

                    @if (
                        $selectedInspectionRequest !== null
                        && $selectedInspectionRequest->status === 'Completed'
                        && $selectedInspection !== null
                        && $selectedBookingAmountDue <= 0
                    )
                        <flux:button
                            variant="primary"
                            wire:click="confirmCheckOut"
                            wire:confirm="Confirm this facility check-out?"
                        >
                            Confirm Check-out
                        </flux:button>
                    @else
                        <flux:button variant="primary" disabled>
                            Not Ready
                        </flux:button>
                    @endif

                    <flux:button
                        variant="ghost"
                        wire:click="cancelSelection"
                    >
                        Cancel
                    </flux:button>
                </div>
            </div>

            <div class="mt-4">
                <h2 class="text-sm font-semibold text-amber-950 dark:text-amber-50">Fines reported by maintenance</h2>
                <div class="mt-2 overflow-x-auto rounded-lg border border-amber-200 dark:border-amber-900">
                    <table class="min-w-full divide-y divide-amber-200 text-sm dark:divide-amber-900">
                        <thead>
                            <tr>
                                <th class="px-3 py-2 text-left">Fine</th>
                                <th class="px-3 py-2 text-left">Qty</th>
                                <th class="px-3 py-2 text-left">Charge</th>
                                <th class="px-3 py-2 text-left">Reported by</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-amber-200 dark:divide-amber-900">
                            @forelse ($selectedGuestFines as $guestFine)
                                <tr>
                                    <td class="px-3 py-2">{{ $this->formatFineLabel($guestFine->fine) }}</td>
                                    <td class="px-3 py-2">{{ $guestFine->quantity }}</td>
                                    <td class="px-3 py-2">₱{{ number_format((float) $guestFine->total_charge, 2) }}</td>
                                    <td class="px-3 py-2">{{ $guestFine->reportedBy?->first_name }} {{ $guestFine->reportedBy?->last_name }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-3 py-4 text-center text-amber-800 dark:text-amber-200">No fines recorded for this facility.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    <x-staff-table-shell
        :first-item="$bookingDetails->firstItem()"
        :last-item="$bookingDetails->lastItem()"
        :total="$bookingDetails->total()"
        record-label="facility check-out records"
        loading-target="search,statusFilter,stageFilter,dateFilter,perPage,sortBy,clearFilters"
    >
        <x-slot:filters>
            <x-staff-filter-panel
                title="Check-out queue and history"
                description="Track inspection, payment, and release readiness for every checked-in facility."
                :count="$bookingDetails->total()"
                count-label="records"
            >
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                    <flux:input
                        label="Search"
                        placeholder="Reference, guest, contact, email, facility"
                        wire:model.live.debounce.300ms="search"
                        clearable
                        class="xl:col-span-2"
                    />

                    <flux:select
                        label="List"
                        wire:model.live="statusFilter"
                    >
                        <option value="eligible">Checked-in / Pending check-out</option>
                        <option value="checked_out">Checked-out history</option>
                    </flux:select>

                    <flux:select
                        label="Workflow stage"
                        wire:model.live="stageFilter"
                        :disabled="$statusFilter === 'checked_out'"
                    >
                        <option value="all">All stages</option>
                        <option value="needs_request">Needs inspection request</option>
                        <option value="waiting">Waiting for maintenance</option>
                        <option value="payment_due">Inspection complete / payment due</option>
                        <option value="ready">Ready for check-out</option>
                    </flux:select>

                    <flux:select
                        label="Scheduled departure"
                        wire:model.live="dateFilter"
                    >
                        <option value="all">All departure dates</option>
                        <option value="today">Due today</option>
                        <option value="upcoming">Upcoming</option>
                        <option value="overdue">Past scheduled date</option>
                    </flux:select>

                    <flux:select
                        label="Rows per page"
                        wire:model.live="perPage"
                    >
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </flux:select>
                </div>

                <x-slot:actions>
                    <flux:button
                        type="button"
                        wire:click="clearFilters"
                        variant="ghost"
                        size="sm"
                    >
                        Reset view
                    </flux:button>
                </x-slot:actions>
            </x-staff-filter-panel>
        </x-slot:filters>

        <table class="w-full min-w-[78rem] text-left text-sm">
            <thead class="border-b border-brand-border bg-brand-surface-muted text-xs uppercase tracking-wide text-brand-text-muted dark:border-zinc-800 dark:bg-zinc-950/60 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3">
                        <button
                            type="button"
                            wire:click="sortBy('b_ref_no')"
                            class="font-semibold transition hover:text-brand-text dark:hover:text-white"
                        >
                            Ref {{ $this->sortIndicator('b_ref_no') }}
                        </button>
                    </th>

                    <th class="px-4 py-3">
                        <button
                            type="button"
                            wire:click="sortBy('guest_name')"
                            class="font-semibold transition hover:text-brand-text dark:hover:text-white"
                        >
                            Guest {{ $this->sortIndicator('guest_name') }}
                        </button>
                    </th>

                    <th class="px-4 py-3">
                        <button
                            type="button"
                            wire:click="sortBy('facility_name')"
                            class="font-semibold transition hover:text-brand-text dark:hover:text-white"
                        >
                            Facility {{ $this->sortIndicator('facility_name') }}
                        </button>
                    </th>

                    <th class="px-4 py-3">
                        <button
                            type="button"
                            wire:click="sortBy('check_in_date')"
                            class="font-semibold transition hover:text-brand-text dark:hover:text-white"
                        >
                            Check-in {{ $this->sortIndicator('check_in_date') }}
                        </button>
                    </th>

                    <th class="px-4 py-3">
                        <button
                            type="button"
                            wire:click="sortBy('check_out_date')"
                            class="font-semibold transition hover:text-brand-text dark:hover:text-white"
                        >
                            Check-out {{ $this->sortIndicator('check_out_date') }}
                        </button>
                    </th>

                    <th class="px-4 py-3">
                        <button
                            type="button"
                            wire:click="sortBy('amount_due')"
                            class="font-semibold transition hover:text-brand-text dark:hover:text-white"
                        >
                            Balance {{ $this->sortIndicator('amount_due') }}
                        </button>
                    </th>

                    <th class="px-4 py-3 font-semibold">Workflow Stage</th>
                    <th class="px-4 py-3 text-right font-semibold">Actions</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-brand-border/70 dark:divide-zinc-800">
                @forelse ($bookingDetails as $detail)
                    <tr
                        wire:key="check-out-detail-{{ $detail->booking_details_id }}"
                        class="align-top text-brand-text transition-colors hover:bg-brand-surface-muted/70 dark:text-zinc-200 dark:hover:bg-zinc-800/60"
                    >
                        <td class="px-4 py-4 font-medium">{{ $detail->booking->b_ref_no }}</td>
                        <td class="px-4 py-4">{{ $detail->booking->guest->first_name }} {{ $detail->booking->guest->last_name }}</td>
                        <td class="px-4 py-4">{{ $detail->facility?->facility_name ?? 'No facility' }}</td>
                        <td class="px-4 py-4">{{ $detail->check_in_date?->format('M d, Y') }}</td>
                        <td class="px-4 py-4">{{ $detail->check_out_date?->format('M d, Y') }}</td>
                        <td class="px-4 py-4">₱{{ number_format((float) $detail->booking->amount_due, 2) }}</td>
                        <td class="px-4 py-4">
                            @php($stage = $this->workflowStage($detail))

                            <flux:badge
                                color="{{ $this->workflowStageColor($stage) }}"
                                size="sm"
                            >
                                {{ $stage }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-4 text-right">
                            <div class="flex flex-wrap justify-end gap-2">
                                @if (Route::has('cashier.bookings.show'))
                                    <flux:button
                                        href="{{ route('cashier.bookings.show', $detail->booking_id) }}"
                                        wire:navigate
                                        size="sm"
                                        variant="ghost"
                                    >
                                        Booking
                                    </flux:button>
                                @endif

                                <flux:button
                                    type="button"
                                    size="sm"
                                    variant="primary"
                                    wire:click="selectCheckOut({{ $detail->booking_details_id }})"
                                >
                                    View
                                </flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-5 py-6">
                            <x-dashboard-empty-state
                                title="No check-out records found"
                                description="No facility check-out record matches the selected search, list, workflow stage, and departure filters."
                                class="border-0 bg-transparent py-6 shadow-none dark:bg-transparent"
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <x-slot:pagination>
            {{ $bookingDetails->links() }}
        </x-slot:pagination>
    </x-staff-table-shell>
</div>
