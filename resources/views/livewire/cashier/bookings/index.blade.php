<?php

use App\Models\BookingDetail;
use App\Models\FacilityProduct;
use App\Services\BookingWorkflowService;
use App\Services\RoomOccupantService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'booking_status', except: '')]
    public string $bookingStatusFilter = '';

    #[Url(as: 'facility_status', except: '')]
    public string $detailStatusFilter = '';

    #[Url(as: 'sort', except: 'booking_id')]
    public string $sortField = 'booking_id';

    #[Url(as: 'direction', except: 'desc')]
    public string $sortDirection = 'desc';

    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    public array $rescheduleForm = [
        'booking_details_id' => '',
        'label' => '',
        'new_check_in_date' => '',
    ];

    public array $transferForm = [
        'booking_details_id' => '',
        'label' => '',
        'facility_product_id' => '',
    ];

    public array $cancelDetailForm = [
        'booking_details_id' => '',
        'label' => '',
        'reason' => '',
    ];

    public array $occupantForm = [
        'booking_details_id' => '',
        'label' => '',
        'occupants' => [],
    ];

    public function with(): array
    {
        return [
            'bookings' => $this->bookings(),
            'bookingStatuses' => $this->bookingStatuses(),
            'detailStatuses' => $this->detailStatuses(),
            'transferProducts' => $this->transferProducts(),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedBookingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDetailStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 25, 50, 100], true)) {
            $this->perPage = 10;
        }

        $this->resetPage();
    }

    public function clearListFilters(): void
    {
        $this->search = '';
        $this->bookingStatusFilter = '';
        $this->detailStatusFilter = '';
        $this->sortField = 'booking_id';
        $this->sortDirection = 'desc';
        $this->perPage = 10;

        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        $allowed = [
            'booking_id',
            'b_ref_no',
            'booking_date',
            'guest_name',
            'facility_name',
            'total_price',
            'amount_due',
            'booking_status',
            'status',
        ];

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

    public function sortIndicator(string $field): string
    {
        if ($this->sortField !== $field) {
            return '↕';
        }

        return $this->sortDirection === 'asc'
            ? '↑'
            : '↓';
    }

    public function openReschedule(int $bookingDetailsId): void
    {
        $detail = BookingDetail::query()->with(['booking.guest', 'facility'])->findOrFail($bookingDetailsId);

        $this->rescheduleForm = [
            'booking_details_id' => (string) $bookingDetailsId,
            'label' => $detail->booking->b_ref_no . ' - ' . $detail->facility->facility_name,
            'new_check_in_date' => (string) $detail->check_in_date,
        ];
    }

    public function cancelReschedule(): void
    {
        $this->rescheduleForm = [
            'booking_details_id' => '',
            'label' => '',
            'new_check_in_date' => '',
        ];
    }

    public function saveReschedule(BookingWorkflowService $bookingWorkflow): void
    {
        $validated = $this->validate([
            'rescheduleForm.booking_details_id' => ['required', 'integer', 'exists:tbl_booking_details,booking_details_id'],
            'rescheduleForm.new_check_in_date' => ['required', 'date', 'after_or_equal:today'],
        ]);

        try {
            $bookingWorkflow->rescheduleBookingDetail(
                (int) $validated['rescheduleForm']['booking_details_id'],
                $validated['rescheduleForm']['new_check_in_date']
            );

            $this->cancelReschedule();
            $this->resetPage();
            session()->flash('success', 'Booking rescheduled successfully.');
        } catch (Throwable $exception) {
            $this->addError('reschedule', $exception->getMessage());
        }
    }

    public function openTransfer(int $bookingDetailsId): void
    {
        $detail = BookingDetail::query()->with(['booking.guest', 'facility'])->findOrFail($bookingDetailsId);

        $this->transferForm = [
            'booking_details_id' => (string) $bookingDetailsId,
            'label' => $detail->booking->b_ref_no . ' - ' . $detail->facility->facility_name,
            'facility_product_id' => (string) ($detail->facility_product_id ?? ''),
        ];
    }

    public function cancelTransfer(): void
    {
        $this->transferForm = [
            'booking_details_id' => '',
            'label' => '',
            'facility_product_id' => '',
        ];
    }

    public function saveTransfer(BookingWorkflowService $bookingWorkflow): void
    {
        $validated = $this->validate([
            'transferForm.booking_details_id' => ['required', 'integer', 'exists:tbl_booking_details,booking_details_id'],
            'transferForm.facility_product_id' => ['required', 'integer', 'exists:tbl_facility_product,facility_product_id'],
        ]);

        try {
            $bookingWorkflow->transferBookingDetailToProduct(
                (int) $validated['transferForm']['booking_details_id'],
                (int) $validated['transferForm']['facility_product_id']
            );

            $this->cancelTransfer();
            $this->resetPage();
            session()->flash('success', 'Facility transfer saved successfully. If there was an upgrade charge, it was added to amount due.');
        } catch (Throwable $exception) {
            $this->addError('transfer', $exception->getMessage());
        }
    }

    public function extendBooking(int $bookingDetailsId, BookingWorkflowService $bookingWorkflow): void
    {
        try {
            $bookingWorkflow->extendCottageDayRate($bookingDetailsId);
            $this->resetPage();
            session()->flash('success', 'Cottage extension recorded. The extension charge was added to amount due.');
        } catch (Throwable $exception) {
            $this->addError('extend', $exception->getMessage());
        }
    }

    public function openCancelDetail(int $bookingDetailsId): void
    {
        $detail = BookingDetail::query()->with(['booking', 'facility'])->findOrFail($bookingDetailsId);
        $this->cancelDetailForm = [
            'booking_details_id' => (string) $bookingDetailsId,
            'label' => $detail->booking?->b_ref_no.' - '.$detail->facility?->facility_name,
            'reason' => '',
        ];
    }

    public function cancelCancelDetail(): void
    {
        $this->cancelDetailForm = [
            'booking_details_id' => '',
            'label' => '',
            'reason' => '',
        ];
    }

    public function saveCancelDetail(BookingWorkflowService $workflow): void
    {
        $validated = $this->validate([
            'cancelDetailForm.booking_details_id' => ['required', 'integer', 'exists:tbl_booking_details,booking_details_id'],
            'cancelDetailForm.reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $workflow->cancelBookingDetail(
                (int) $validated['cancelDetailForm']['booking_details_id'],
                $validated['cancelDetailForm']['reason'],
                (int) Auth::id(),
            );
            $this->cancelCancelDetail();
            session()->flash('success', 'Facility cancelled. Paid value remains as same-transaction credit.');
        } catch (\Throwable $exception) {
            $this->addError('cancelDetail', $exception->getMessage());
        }
    }

    public function openOccupants(int $bookingDetailsId): void
    {
        $detail = BookingDetail::query()
            ->with(['booking', 'facility', 'roomOccupants'])
            ->findOrFail($bookingDetailsId);

        $this->occupantForm = [
            'booking_details_id' => (string) $bookingDetailsId,
            'label' => $detail->booking?->b_ref_no.' - '.$detail->facility?->facility_name,
            'occupants' => $detail->roomOccupants->map(fn ($occupant): array => [
                'first_name' => $occupant->first_name,
                'middle_name' => $occupant->middle_name ?? '',
                'last_name' => $occupant->last_name,
            ])->all(),
        ];
    }

    public function cancelOccupants(): void
    {
        $this->occupantForm = [
            'booking_details_id' => '',
            'label' => '',
            'occupants' => [],
        ];
    }

    public function saveOccupants(RoomOccupantService $occupants): void
    {
        try {
            $occupants->replaceBookingOccupants(
                (int) $this->occupantForm['booking_details_id'],
                $this->occupantForm['occupants'],
                (int) Auth::id(),
            );
            $this->cancelOccupants();
            session()->flash('success', 'Room occupant names updated and audited.');
        } catch (\Throwable $exception) {
            $this->addError('occupants', $exception->getMessage());
        }
    }

    private function bookings()
    {
        $query = DB::table('tbl_booking')
            ->join('tbl_guest', 'tbl_guest.guest_id', '=', 'tbl_booking.guest_id')
            ->leftJoin('tbl_booking_details', 'tbl_booking_details.booking_id', '=', 'tbl_booking.booking_id')
            ->leftJoin('tbl_facility', 'tbl_facility.facility_id', '=', 'tbl_booking_details.facility_id')
            ->select([
                'tbl_booking.booking_id',
                'tbl_booking.b_ref_no',
                'tbl_booking.booking_date',
                'tbl_booking.total_price',
                'tbl_booking.amount_due',
                'tbl_booking.no_of_extra_guests',
                'tbl_booking.status as booking_status',
                'tbl_booking_details.booking_details_id',
                'tbl_booking_details.rate_type',
                'tbl_booking_details.check_in_date',
                'tbl_booking_details.check_out_date',
                'tbl_booking_details.status',
                'tbl_facility.facility_name',
                DB::raw("CONCAT(tbl_guest.first_name, ' ', COALESCE(tbl_guest.middle_name, ''), ' ', tbl_guest.last_name) as guest_name"),
            ]);

        if ($this->search !== '') {
            $search = '%' . $this->search . '%';
            $query->where(function ($subQuery) use ($search): void {
                $subQuery->where('tbl_booking.b_ref_no', 'like', $search)
                    ->orWhere('tbl_guest.first_name', 'like', $search)
                    ->orWhere('tbl_guest.last_name', 'like', $search)
                    ->orWhere('tbl_guest.contact_no', 'like', $search)
                    ->orWhere('tbl_guest.email', 'like', $search)
                    ->orWhere('tbl_facility.facility_name', 'like', $search);
            });
        }

        if ($this->bookingStatusFilter !== '') {
            $query->where('tbl_booking.status', $this->bookingStatusFilter);
        }

        if ($this->detailStatusFilter !== '') {
            $query->where('tbl_booking_details.status', $this->detailStatusFilter);
        }

        $sortMap = [
            'booking_id' => 'tbl_booking.booking_id',
            'b_ref_no' => 'tbl_booking.b_ref_no',
            'booking_date' => 'tbl_booking.booking_date',
            'guest_name' => 'guest_name',
            'facility_name' => 'tbl_facility.facility_name',
            'total_price' => 'tbl_booking.total_price',
            'amount_due' => 'tbl_booking.amount_due',
            'booking_status' => 'tbl_booking.status',
            'status' => 'tbl_booking_details.status',
        ];

        $query->orderBy($sortMap[$this->sortField] ?? 'tbl_booking.booking_id', $this->sortDirection);

        $perPage = in_array($this->perPage, [10, 25, 50, 100], true)
            ? $this->perPage
            : 10;

        return $query->paginate($perPage);
    }

    private function bookingStatuses()
    {
        return DB::table('tbl_booking')
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->distinct()
            ->orderBy('status')
            ->pluck('status');
    }

    private function detailStatuses()
    {
        return DB::table('tbl_booking_details')
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->distinct()
            ->orderBy('status')
            ->pluck('status');
    }

    private function transferProducts()
    {
        if ($this->transferForm['booking_details_id'] === '') {
            return collect();
        }

        $detail = BookingDetail::query()
            ->with('facility')
            ->find((int) $this->transferForm['booking_details_id']);

        if (! $detail || ! $detail->facility) {
            return collect();
        }

        return FacilityProduct::query()
            ->where('facility_type_id', $detail->facility->facility_type_id)
            ->where('is_active', true)
            ->orderBy('display_name')
            ->get();
    }

    private function transferProducts()
    {
        if ($this->transferForm['booking_details_id'] === '') {
            return collect();
        }

        $detail = BookingDetail::query()
            ->with('facility')
            ->find((int) $this->transferForm['booking_details_id']);

        if (! $detail || ! $detail->facility) {
            return collect();
        }

        return FacilityProduct::query()
            ->where('facility_type_id', $detail->facility->facility_type_id)
            ->where('is_active', true)
            ->orderBy('display_name')
            ->get();
    }

};
?>

<div class="space-y-6">
    <x-staff-page-header
        eyebrow="Cashier operations"
        title="Booking Management"
        description="Create paid facility bookings, reschedule stays, transfer facilities, and extend eligible cottage bookings."
    >
        <x-slot:actions>
            <flux:button href="{{ route('cashier.bookings.create') }}" variant="primary" wire:navigate>
                New booking
            </flux:button>
        </x-slot:actions>
    </x-staff-page-header>

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @error('booking')
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ $message }}</div>
    @enderror

    @error('reschedule')
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ $message }}</div>
    @enderror

    @error('transfer')
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ $message }}</div>
    @enderror

    @error('extend')
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ $message }}</div>
    @enderror

    @if ($rescheduleForm['booking_details_id'] !== '')
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900 dark:bg-amber-950">
            <h2 class="text-lg font-semibold text-amber-950 dark:text-amber-100">Reschedule Booking</h2>
            <p class="text-sm text-amber-800 dark:text-amber-200">{{ $rescheduleForm['label'] }}</p>
            <form wire:submit.prevent="saveReschedule" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                <flux:input label="New check-in date" type="date" wire:model="rescheduleForm.new_check_in_date" />
                <flux:button type="submit" variant="primary">Save Reschedule</flux:button>
                <flux:button type="button" wire:click="cancelReschedule">Cancel</flux:button>
            </form>
        </div>
    @endif

    @if ($transferForm['booking_details_id'] !== '')
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-5 dark:border-blue-900 dark:bg-blue-950">
            <h2 class="text-lg font-semibold text-blue-950 dark:text-blue-100">Transfer Facility</h2>
            <p class="text-sm text-blue-800 dark:text-blue-200">{{ $transferForm['label'] }}</p>
            <form wire:submit.prevent="saveTransfer" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Destination product</label>
                    <select wire:model="transferForm.facility_product_id" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100">
                        <option value="">Choose upgrade product</option>
                        @foreach ($transferProducts as $product)
                            <option value="{{ $product->facility_product_id }}">{{ $product->display_name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-zinc-500">Exact unit is assigned automatically; cheaper transfers are rejected.</p>
                </div>
                <flux:button type="submit" variant="primary">Auto-assign Transfer</flux:button>
                <flux:button type="button" wire:click="cancelTransfer">Cancel</flux:button>
            </form>
        </div>
    @endif

    @if ($cancelDetailForm['booking_details_id'] !== '')
        <flux:card>
            <flux:heading size="lg">Cancel facility</flux:heading>
            <flux:text class="mt-1">{{ $cancelDetailForm['label'] }}</flux:text>
            @error('cancelDetail') <p class="mt-3 text-sm text-red-600">{{ $message }}</p> @enderror
            <form wire:submit="saveCancelDetail" class="mt-4 space-y-4">
                <flux:textarea wire:model="cancelDetailForm.reason" label="Cancellation reason *" rows="3" />
                <p class="text-xs text-zinc-500">Only this facility is cancelled. Paid value stays within this booking as transaction credit.</p>
                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="cancelCancelDetail">Close</flux:button>
                    <flux:button type="submit" variant="danger">Cancel Facility</flux:button>
                </div>
            </form>
        </flux:card>
    @endif

    @if ($occupantForm['booking_details_id'] !== '')
        <flux:card>
            <flux:heading size="lg">Edit room occupants</flux:heading>
            <flux:text class="mt-1">{{ $occupantForm['label'] }}</flux:text>
            @error('occupants') <p class="mt-3 text-sm text-red-600">{{ $message }}</p> @enderror
            <form wire:submit="saveOccupants" class="mt-4 space-y-4">
                @forelse ($occupantForm['occupants'] as $index => $occupant)
                    <div class="grid gap-3 md:grid-cols-3">
                        <flux:input wire:model="occupantForm.occupants.{{ $index }}.first_name" label="Occupant {{ $index + 1 }} first name *" />
                        <flux:input wire:model="occupantForm.occupants.{{ $index }}.middle_name" label="Middle name" />
                        <flux:input wire:model="occupantForm.occupants.{{ $index }}.last_name" label="Last name *" />
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">No room occupants are attached to this facility.</p>
                @endforelse
                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="cancelOccupants">Close</flux:button>
                    <flux:button type="submit" variant="primary">Save Occupants</flux:button>
                </div>
            </form>
        </flux:card>
    @endif

    <x-staff-table-shell
        :first-item="$bookings->firstItem()"
        :last-item="$bookings->lastItem()"
        :total="$bookings->total()"
        record-label="booking facility records"
        loading-target="search,bookingStatusFilter,detailStatusFilter,perPage,sortBy,clearListFilters"
    >
        <x-slot:filters>
            <x-staff-filter-panel
                title="Booking registry"
                description="Search the complete registry, narrow it by booking or facility status, and sort any operational column."
                :count="$bookings->total()"
                count-label="records"
            >
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        label="Search"
                        placeholder="Reference, guest, contact, email, or facility"
                        clearable
                    />

                    <flux:select wire:model.live="bookingStatusFilter" label="Booking status">
                        <option value="">All booking statuses</option>
                        @foreach ($bookingStatuses as $bookingStatus)
                            <option value="{{ $bookingStatus }}">{{ $bookingStatus }}</option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model.live="detailStatusFilter" label="Facility status">
                        <option value="">All facility statuses</option>
                        @foreach ($detailStatuses as $detailStatus)
                            <option value="{{ $detailStatus }}">{{ $detailStatus }}</option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model.live="perPage" label="Rows per page">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </flux:select>
                </div>

                <x-slot:actions>
                    <flux:button
                        type="button"
                        wire:click="clearListFilters"
                        variant="ghost"
                        size="sm"
                    >
                        Reset view
                    </flux:button>
                </x-slot:actions>
            </x-staff-filter-panel>
        </x-slot:filters>

        <table class="w-full min-w-[86rem] text-left text-sm">
            <thead class="border-b border-brand-border bg-brand-surface-muted text-xs uppercase tracking-wide text-brand-text-muted dark:border-zinc-800 dark:bg-zinc-950/60 dark:text-zinc-400">
                <tr>
                    <th class="px-5 py-3"><button type="button" wire:click="sortBy('booking_id')" class="font-semibold hover:text-brand-text dark:hover:text-white">ID {{ $this->sortIndicator('booking_id') }}</button></th>
                    <th class="px-5 py-3"><button type="button" wire:click="sortBy('b_ref_no')" class="font-semibold hover:text-brand-text dark:hover:text-white">Reference {{ $this->sortIndicator('b_ref_no') }}</button></th>
                    <th class="px-5 py-3"><button type="button" wire:click="sortBy('guest_name')" class="font-semibold hover:text-brand-text dark:hover:text-white">Guest {{ $this->sortIndicator('guest_name') }}</button></th>
                    <th class="px-5 py-3"><button type="button" wire:click="sortBy('facility_name')" class="font-semibold hover:text-brand-text dark:hover:text-white">Facility {{ $this->sortIndicator('facility_name') }}</button></th>
                    <th class="px-5 py-3"><button type="button" wire:click="sortBy('booking_date')" class="font-semibold hover:text-brand-text dark:hover:text-white">Booked On {{ $this->sortIndicator('booking_date') }}</button></th>
                    <th class="px-5 py-3 font-semibold">Date Range</th>
                    <th class="px-5 py-3 font-semibold">Rate</th>
                    <th class="px-5 py-3"><button type="button" wire:click="sortBy('total_price')" class="font-semibold hover:text-brand-text dark:hover:text-white">Total {{ $this->sortIndicator('total_price') }}</button></th>
                    <th class="px-5 py-3"><button type="button" wire:click="sortBy('amount_due')" class="font-semibold hover:text-brand-text dark:hover:text-white">Due {{ $this->sortIndicator('amount_due') }}</button></th>
                    <th class="px-5 py-3"><button type="button" wire:click="sortBy('booking_status')" class="font-semibold hover:text-brand-text dark:hover:text-white">Booking Status {{ $this->sortIndicator('booking_status') }}</button></th>
                    <th class="px-5 py-3"><button type="button" wire:click="sortBy('status')" class="font-semibold hover:text-brand-text dark:hover:text-white">Facility Status {{ $this->sortIndicator('status') }}</button></th>
                    <th class="px-5 py-3 text-right font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($bookings as $booking)
                    <tr wire:key="booking-row-{{ $booking->booking_id }}-{{ $booking->booking_details_id }}" class="text-brand-text transition-colors hover:bg-brand-surface-muted/70 dark:text-zinc-200 dark:hover:bg-zinc-800/60">
                        <td class="px-5 py-4">{{ $booking->booking_id }}</td>
                        <td class="px-5 py-4 font-semibold">{{ $booking->b_ref_no }}</td>
                        <td class="px-5 py-4">{{ trim($booking->guest_name) }}</td>
                        <td class="px-5 py-4">{{ $booking->facility_name ?? 'No facility' }}</td>
                        <td class="px-5 py-4">{{ $booking->booking_date }}</td>
                        <td class="px-5 py-4 whitespace-nowrap">{{ $booking->check_in_date }} → {{ $booking->check_out_date }}</td>
                        <td class="px-5 py-4">{{ $booking->rate_type }}</td>
                        <td class="px-5 py-4 font-medium">₱{{ number_format((float) $booking->total_price, 2) }}</td>
                        <td class="px-5 py-4 font-medium">₱{{ number_format((float) $booking->amount_due, 2) }}</td>
                        <td class="px-5 py-4">
                            <x-status-badge :status="(string) $booking->booking_status" />
                        </td>
                        <td class="px-5 py-4">
                            <x-status-badge :status="(string) $booking->status" />
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex flex-wrap justify-end gap-2">
                                @if (Route::has('cashier.bookings.show'))
                                    <flux:button
                                        size="sm"
                                        variant="primary"
                                        href="{{ route('cashier.bookings.show', $booking->booking_id) }}"
                                        wire:navigate
                                    >
                                        View
                                    </flux:button>
                                @endif

                                @if ($booking->booking_details_id)
                                    <flux:button size="sm" wire:click="openReschedule({{ $booking->booking_details_id }})">Reschedule</flux:button>
                                    <flux:button size="sm" wire:click="openTransfer({{ $booking->booking_details_id }})">Transfer</flux:button>
                                    <flux:button size="sm" wire:click="extendBooking({{ $booking->booking_details_id }})">Extend</flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="openOccupants({{ $booking->booking_details_id }})">Occupants</flux:button>
                                    <flux:button size="sm" variant="danger" wire:click="openCancelDetail({{ $booking->booking_details_id }})">Cancel Facility</flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="12" class="px-5 py-6">
                            <x-dashboard-empty-state
                                title="No booking records found"
                                description="No booking facility records match the current search and status filters."
                                class="border-0 bg-transparent py-6 shadow-none dark:bg-transparent"
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <x-slot:pagination>
            {{ $bookings->links() }}
        </x-slot:pagination>
    </x-staff-table-shell>
</div>
