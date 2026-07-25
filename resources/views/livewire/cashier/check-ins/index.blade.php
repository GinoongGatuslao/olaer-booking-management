<?php

use App\Models\BookingDetail;
use App\Services\CheckInWorkflowService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'list', except: 'eligible')]
    public string $statusFilter = 'eligible';

    #[Url(as: 'arrival', except: 'all')]
    public string $dateFilter = 'all';

    #[Url(as: 'sort', except: 'check_in_date')]
    public string $sortField = 'check_in_date';

    #[Url(as: 'direction', except: 'asc')]
    public string $sortDirection = 'asc';

    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    public ?int $selectedBookingDetailsId = null;
    public string $selectedLabel = '';

    public function mount(): void
    {
        $bookingId = request()->integer('booking');

        if ($bookingId <= 0) {
            return;
        }

        $eligibleDetails = BookingDetail::query()
            ->with('booking')
            ->where('booking_id', $bookingId)
            ->whereIn('status', ['Booked', 'Rescheduled', 'Transferred', 'Extended'])
            ->whereHas('booking', function ($query): void {
                $query->where('amount_due', '<=', 0)
                    ->whereNotIn('status', ['Cancelled', 'Checked-out']);
            })
            ->get();

        $bookingReference = $eligibleDetails->first()?->booking?->b_ref_no;

        if (filled($bookingReference)) {
            $this->search = (string) $bookingReference;
        }

        if ($eligibleDetails->count() === 1) {
            $this->selectCheckIn((int) $eligibleDetails->first()->booking_details_id);
        }
    }

    public function with(): array
    {
        return [
            'bookingDetails' => $this->bookingDetails(),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->selectedBookingDetailsId = null;
        $this->selectedLabel = '';
        $this->resetPage();
    }

    public function updatedDateFilter(): void
    {
        $this->selectedBookingDetailsId = null;
        $this->selectedLabel = '';
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
        $this->dateFilter = 'all';
        $this->sortField = 'check_in_date';
        $this->sortDirection = 'asc';
        $this->perPage = 10;
        $this->selectedBookingDetailsId = null;
        $this->selectedLabel = '';

        $this->resetPage();
    }

    public function sortIndicator(string $field): string
    {
        if ($this->sortField !== $field) {
            return '↕';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            'Booked', 'Rescheduled', 'Transferred', 'Extended' => 'blue',
            'Checked-in' => 'green',
            default => 'zinc',
        };
    }

    public function sortBy(string $field): void
    {
        $allowed = ['booking_id', 'b_ref_no', 'guest_name', 'facility_name', 'check_in_date', 'check_out_date', 'amount_due', 'status'];

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

    public function selectCheckIn(int $bookingDetailsId): void
    {
        $detail = BookingDetail::query()
            ->with(['booking.guest', 'facility'])
            ->findOrFail($bookingDetailsId);

        $guest = $detail->booking->guest;
        $facilityName = optional($detail->facility)->facility_name ?? 'No facility';

        $this->selectedBookingDetailsId = $bookingDetailsId;
        $this->selectedLabel = $detail->booking->b_ref_no . ' - ' . $guest->first_name . ' ' . $guest->last_name . ' - ' . $facilityName;
    }

    public function cancelSelection(): void
    {
        $this->selectedBookingDetailsId = null;
        $this->selectedLabel = '';
    }

    public function confirmCheckIn(CheckInWorkflowService $checkInWorkflow): void
    {
        $validated = $this->validate([
            'selectedBookingDetailsId' => ['required', 'integer', 'exists:tbl_booking_details,booking_details_id'],
        ]);

        try {
            $checkInWorkflow->checkInBookingDetail(
                (int) $validated['selectedBookingDetailsId'],
                (int) Auth::id()
            );

            $this->cancelSelection();
            $this->resetPage();
            session()->flash('success', 'Guest checked in successfully.');
        } catch (\Throwable $exception) {
            $this->addError('checkIn', $exception->getMessage());
        }
    }

    private function bookingDetails()
    {
        $query = BookingDetail::query()
            ->select('tbl_booking_details.*')
            ->join('tbl_booking', 'tbl_booking.booking_id', '=', 'tbl_booking_details.booking_id')
            ->join('tbl_guest', 'tbl_guest.guest_id', '=', 'tbl_booking.guest_id')
            ->leftJoin('tbl_facility', 'tbl_facility.facility_id', '=', 'tbl_booking_details.facility_id')
            ->with(['booking.guest', 'facility.facilityType']);

        if ($this->statusFilter === 'checked_in') {
            $query->where('tbl_booking_details.status', 'Checked-in');
        } else {
            $query->whereIn('tbl_booking_details.status', ['Booked', 'Rescheduled', 'Transferred', 'Extended'])
                ->whereNotIn('tbl_booking.status', ['Cancelled', 'Checked-out'])
                ->where('tbl_booking.amount_due', '<=', 0);
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
                'tbl_booking_details.check_in_date',
                now()->toDateString(),
            );
        } elseif ($this->dateFilter === 'upcoming') {
            $query->whereDate(
                'tbl_booking_details.check_in_date',
                '>',
                now()->toDateString(),
            );
        } elseif ($this->dateFilter === 'overdue') {
            $query->whereDate(
                'tbl_booking_details.check_in_date',
                '<',
                now()->toDateString(),
            );
        }

        if ($this->sortField === 'guest_name') {
            $query->orderBy('tbl_guest.last_name', $this->sortDirection)
                ->orderBy('tbl_guest.first_name', $this->sortDirection);
        } elseif ($this->sortField === 'facility_name') {
            $query->orderBy('tbl_facility.facility_name', $this->sortDirection);
        } else {
            $sortMap = [
                'booking_id' => 'tbl_booking.booking_id',
                'b_ref_no' => 'tbl_booking.b_ref_no',
                'check_in_date' => 'tbl_booking_details.check_in_date',
                'check_out_date' => 'tbl_booking_details.check_out_date',
                'amount_due' => 'tbl_booking.amount_due',
                'status' => 'tbl_booking_details.status',
            ];

            $sortColumn = $sortMap[$this->sortField] ?? 'tbl_booking_details.check_in_date';
            $query->orderBy($sortColumn, $this->sortDirection);
        }

        $perPage = in_array($this->perPage, [10, 25, 50, 100], true)
            ? $this->perPage
            : 10;

        return $query->paginate($perPage);
    }
};
?>

<div class="space-y-6">
    <x-staff-page-header
        eyebrow="Cashier operations"
        title="Cashier Check-in"
        description="Review fully paid booking candidates and confirm eligible arrivals before marking facilities as occupied."
    />

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @error('checkIn')
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            {{ $message }}
        </div>
    @enderror

    @if ($selectedBookingDetailsId !== null)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-medium text-amber-900 dark:text-amber-100">Confirm check-in</p>
                    <p class="text-sm text-amber-800 dark:text-amber-200">{{ $selectedLabel }}</p>
                </div>
                <div class="flex gap-2">
                    <flux:button variant="primary" wire:click="confirmCheckIn">Confirm Check-in</flux:button>
                    <flux:button variant="ghost" wire:click="cancelSelection">Cancel</flux:button>
                </div>
            </div>
        </div>
    @endif

    <x-staff-table-shell
        :first-item="$bookingDetails->firstItem()"
        :last-item="$bookingDetails->lastItem()"
        :total="$bookingDetails->total()"
        record-label="facility check-in records"
        loading-target="search,statusFilter,dateFilter,perPage,sortBy,clearFilters"
    >
        <x-slot:filters>
            <x-staff-filter-panel
                title="Check-in queue and history"
                description="Review fully paid booking candidates and completed facility check-ins by scheduled arrival."
                :count="$bookingDetails->total()"
                count-label="records"
            >
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <flux:input
                        label="Search"
                        placeholder="Reference, guest, contact, email, facility"
                        wire:model.live.debounce.300ms="search"
                        clearable
                    />

                    <flux:select
                        label="List"
                        wire:model.live="statusFilter"
                    >
                        <option value="eligible">Check-in candidates</option>
                        <option value="checked_in">Already checked-in</option>
                    </flux:select>

                    <flux:select
                        label="Scheduled arrival"
                        wire:model.live="dateFilter"
                    >
                        <option value="all">All arrival dates</option>
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

        <table class="w-full min-w-[88rem] text-left text-sm">
            <thead class="border-b border-brand-border bg-brand-surface-muted text-xs uppercase tracking-wide text-brand-text-muted dark:border-zinc-800 dark:bg-zinc-950/60 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3">
                        <button
                            type="button"
                            wire:click="sortBy('b_ref_no')"
                            class="font-semibold transition hover:text-brand-text dark:hover:text-white"
                        >
                            Reference {{ $this->sortIndicator('b_ref_no') }}
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

                    <th class="px-4 py-3 font-semibold">Rate</th>

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
                            Due {{ $this->sortIndicator('amount_due') }}
                        </button>
                    </th>

                    <th class="px-4 py-3">
                        <button
                            type="button"
                            wire:click="sortBy('status')"
                            class="font-semibold transition hover:text-brand-text dark:hover:text-white"
                        >
                            Status {{ $this->sortIndicator('status') }}
                        </button>
                    </th>

                    <th class="px-4 py-3 text-right font-semibold">Actions</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-brand-border/70 dark:divide-zinc-800">
                @forelse ($bookingDetails as $detail)
                    <tr
                        wire:key="check-in-detail-{{ $detail->booking_details_id }}"
                        class="align-top text-brand-text transition-colors hover:bg-brand-surface-muted/70 dark:text-zinc-200 dark:hover:bg-zinc-800/60"
                    >
                        <td class="px-4 py-4 font-medium">{{ $detail->booking->b_ref_no }}</td>
                        <td class="px-4 py-4">
                            <p class="font-medium">
                                {{ $detail->booking->guest->first_name }} {{ $detail->booking->guest->last_name }}
                            </p>

                            <p class="mt-1 text-xs text-brand-text-muted dark:text-zinc-400">
                                {{ $detail->booking->guest->contact_no }}
                            </p>
                        </td>
                        <td class="px-4 py-4">
                            <p class="font-medium">
                                {{ optional($detail->facility)->facility_name ?? 'No facility' }}
                            </p>

                            <p class="mt-1 text-xs text-brand-text-muted dark:text-zinc-400">
                                {{ optional(optional($detail->facility)->facilityType)->facility_type }}
                            </p>
                        </td>
                        <td class="px-4 py-4">{{ $detail->rate_type }}</td>
                        <td class="px-4 py-4">{{ optional($detail->check_in_date)->format('M d, Y') ?? $detail->check_in_date }}</td>
                        <td class="px-4 py-4">{{ optional($detail->check_out_date)->format('M d, Y') ?? $detail->check_out_date }}</td>
                        <td class="px-4 py-4">₱{{ number_format((float) $detail->booking->amount_due, 2) }}</td>
                        <td class="px-4 py-4">
                            <flux:badge
                                color="{{ $this->statusColor((string) $detail->status) }}"
                                size="sm"
                            >
                                {{ $detail->status }}
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

                                @if ($statusFilter === 'eligible')
                                    <flux:button
                                        type="button"
                                        size="sm"
                                        variant="primary"
                                        wire:click="selectCheckIn({{ $detail->booking_details_id }})"
                                    >
                                        Check-in
                                    </flux:button>
                                @else
                                    <span class="self-center text-xs text-brand-text-muted dark:text-zinc-400">
                                        Checked in at {{ $detail->check_in_time ?? 'recorded' }}
                                    </span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-5 py-6">
                            <x-dashboard-empty-state
                                title="No check-in records found"
                                description="No facility check-in record matches the selected search, list, and scheduled-arrival filters."
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
