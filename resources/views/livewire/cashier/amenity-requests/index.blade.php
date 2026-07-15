<?php

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\BookingDetail;
use App\Services\AmenityRequestWorkflowService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Amenity Requests - Olaer Spring Resort')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'sort', except: 'amenity_request_id')]
    public string $sortField = 'amenity_request_id';

    #[Url(as: 'direction', except: 'desc')]
    public string $sortDirection = 'desc';

    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    public bool $showCreateForm = false;

    public array $form = [
        'booking_id' => '',
        'facility_id' => '',
    ];

    public array $items = [
        ['amenity_id' => '', 'quantity' => 1],
    ];

    public string $editingRequestId = '';

    public array $editForm = [
        'facility_id' => '',
    ];

    public array $editItems = [];

    public function mount(): void
    {
        // Preserves Booking Workspace navigation:
        // /cashier/amenity-requests?booking={booking_id}
        $bookingId = request()->integer('booking');

        if ($bookingId <= 0) {
            return;
        }

        $booking = Booking::query()
            ->with('details')
            ->whereKey($bookingId)
            ->whereIn('status', ['Checked-in', 'Partially Checked-in'])
            ->whereHas(
                'details',
                fn ($query) => $query->where('status', 'Checked-in'),
            )
            ->first();

        if ($booking === null) {
            return;
        }

        $this->showCreateForm = true;
        $this->form['booking_id'] = (string) $booking->booking_id;

        $checkedInFacilityIds = $booking->details
            ->where('status', 'Checked-in')
            ->pluck('facility_id')
            ->filter()
            ->unique()
            ->values();

        if ($checkedInFacilityIds->count() === 1) {
            $this->form['facility_id'] =
                (string) $checkedInFacilityIds->first();
        }
    }

    public function with(): array
    {
        return [
            'requests' => $this->requests(),
            'checkedInBookings' => $this->checkedInBookings(),
            'rentableAmenities' => $this->rentableAmenities(),
            'selectedBookingFacilities' =>
                $this->selectedBookingFacilities(),
            'editBookingFacilities' =>
                $this->editBookingFacilities(),
            'currentTotal' => $this->calculateTotal($this->items),
            'editTotal' => $this->calculateTotal($this->editItems),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
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

    public function updatedFormBookingId(): void
    {
        $this->form['facility_id'] = '';
    }

    public function clearListFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->sortField = 'amenity_request_id';
        $this->sortDirection = 'desc';
        $this->perPage = 10;

        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        $allowed = [
            'amenity_request_id',
            'date_created',
            'total_price',
            'amenity_request_status',
        ];

        if (! in_array($field, $allowed, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection =
                $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection =
                $field === 'amenity_request_id' ? 'desc' : 'asc';
        }

        $this->resetPage();
    }

    public function toggleCreateForm(): void
    {
        $this->showCreateForm = ! $this->showCreateForm;
    }

    public function addItem(): void
    {
        $this->items[] = [
            'amenity_id' => '',
            'quantity' => 1,
        ];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);

        if ($this->items === []) {
            $this->addItem();
        }
    }

    public function addEditItem(): void
    {
        $this->editItems[] = [
            'amenity_id' => '',
            'quantity' => 1,
        ];
    }

    public function removeEditItem(int $index): void
    {
        unset($this->editItems[$index]);
        $this->editItems = array_values($this->editItems);

        if ($this->editItems === []) {
            $this->addEditItem();
        }
    }

    public function createRequest(
        AmenityRequestWorkflowService $workflow,
    ): void {
        $validated = $this->validate([
            'form.booking_id' => [
                'required',
                'integer',
                'exists:tbl_booking,booking_id',
            ],
            'form.facility_id' => [
                'required',
                'integer',
                'exists:tbl_facility,facility_id',
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.amenity_id' => [
                'required',
                'integer',
                'exists:tbl_amenity,amenity_id',
            ],
            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        try {
            $workflow->createBillableRequest([
                'booking_id' =>
                    (int) $validated['form']['booking_id'],
                'facility_id' =>
                    (int) $validated['form']['facility_id'],
                'items' => $validated['items'],
                'user_id' => Auth::id(),
            ]);

            $this->resetCreateForm();
            $this->showCreateForm = false;
            $this->resetPage();

            session()->flash(
                'success',
                'Amenity request created. Collect payment before maintenance delivery.',
            );
        } catch (\Throwable $exception) {
            $this->addError(
                'request',
                $exception->getMessage(),
            );
        }
    }

    public function openEdit(int $amenityRequestId): void
    {
        $request = AmenityRequest::query()
            ->with('details')
            ->findOrFail($amenityRequestId);

        if ($request->amenity_request_status !== 'Awaiting Payment') {
            $this->addError(
                'edit',
                'Only unpaid amenity requests can be modified.',
            );

            return;
        }

        $firstDetail = $request->details->first();

        $this->editingRequestId =
            (string) $request->amenity_request_id;

        $this->editForm = [
            'facility_id' =>
                (string) ($firstDetail?->facility_id ?? ''),
        ];

        $this->editItems = $request->details
            ->map(fn ($detail): array => [
                'amenity_id' =>
                    (string) $detail->amenity_id,
                'quantity' =>
                    (int) $detail->amenity_quantity,
            ])
            ->values()
            ->all();

        if ($this->editItems === []) {
            $this->addEditItem();
        }
    }

    public function cancelEdit(): void
    {
        $this->editingRequestId = '';
        $this->editForm = ['facility_id' => ''];
        $this->editItems = [];

        $this->resetErrorBag('edit');
    }

    public function saveEdit(
        AmenityRequestWorkflowService $workflow,
    ): void {
        $validated = $this->validate([
            'editingRequestId' => [
                'required',
                'integer',
                'exists:tbl_amenity_request,amenity_request_id',
            ],
            'editForm.facility_id' => [
                'required',
                'integer',
                'exists:tbl_facility,facility_id',
            ],
            'editItems' => ['required', 'array', 'min:1'],
            'editItems.*.amenity_id' => [
                'required',
                'integer',
                'exists:tbl_amenity,amenity_id',
            ],
            'editItems.*.quantity' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        try {
            $workflow->updateBillableRequest(
                (int) $validated['editingRequestId'],
                (int) $validated['editForm']['facility_id'],
                $validated['editItems'],
            );

            $this->cancelEdit();
            $this->resetPage();

            session()->flash(
                'success',
                'Amenity request updated successfully.',
            );
        } catch (\Throwable $exception) {
            $this->addError(
                'edit',
                $exception->getMessage(),
            );
        }
    }

    public function cancelRequest(
        int $amenityRequestId,
        AmenityRequestWorkflowService $workflow,
    ): void {
        try {
            $workflow->cancelUnpaidRequest(
                $amenityRequestId,
            );

            $this->resetPage();

            session()->flash(
                'success',
                'Unpaid amenity request cancelled.',
            );
        } catch (\Throwable $exception) {
            $this->addError(
                'request',
                $exception->getMessage(),
            );
        }
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            'Awaiting Payment' => 'amber',
            'Pending' => 'blue',
            'Delivering' => 'purple',
            'Delivered' => 'green',
            'Cancelled' => 'red',
            default => 'zinc',
        };
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

    public function staffName(
        mixed $staff,
        string $fallback = '—',
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

    private function resetCreateForm(): void
    {
        $this->form = [
            'booking_id' => '',
            'facility_id' => '',
        ];

        $this->items = [
            ['amenity_id' => '', 'quantity' => 1],
        ];

        $this->resetErrorBag();
    }

    private function requests()
    {
        $allowedSorts = [
            'amenity_request_id',
            'date_created',
            'total_price',
            'amenity_request_status',
        ];

        $sortField = in_array(
            $this->sortField,
            $allowedSorts,
            true,
        )
            ? $this->sortField
            : 'amenity_request_id';

        $direction =
            $this->sortDirection === 'asc'
                ? 'asc'
                : 'desc';

        $perPage = in_array(
            $this->perPage,
            [10, 25, 50, 100],
            true,
        )
            ? $this->perPage
            : 10;

        return AmenityRequest::query()
            ->with([
                'booking.guest',
                'details.amenity.amenityName',
                'details.facility',
                'user',
                'assignedTo',
            ])
            ->when(
                $this->statusFilter !== '',
                fn ($query) => $query->where(
                    'amenity_request_status',
                    $this->statusFilter,
                ),
            )
            ->when(
                trim($this->search) !== '',
                function ($query): void {
                    $search =
                        '%'.trim($this->search).'%';

                    $query->where(
                        function ($query) use ($search): void {
                            $query
                                ->where(
                                    'amenity_request_id',
                                    'like',
                                    $search,
                                )
                                ->orWhereHas(
                                    'booking',
                                    fn ($bookingQuery) =>
                                        $bookingQuery
                                            ->where(
                                                'b_ref_no',
                                                'like',
                                                $search,
                                            ),
                                )
                                ->orWhereHas(
                                    'booking.guest',
                                    function ($guestQuery) use ($search): void {
                                        $guestQuery
                                            ->where(
                                                'first_name',
                                                'like',
                                                $search,
                                            )
                                            ->orWhere(
                                                'middle_name',
                                                'like',
                                                $search,
                                            )
                                            ->orWhere(
                                                'last_name',
                                                'like',
                                                $search,
                                            )
                                            ->orWhere(
                                                'contact_no',
                                                'like',
                                                $search,
                                            )
                                            ->orWhere(
                                                'email',
                                                'like',
                                                $search,
                                            );
                                    },
                                )
                                ->orWhereHas(
                                    'details.facility',
                                    fn ($facilityQuery) =>
                                        $facilityQuery
                                            ->where(
                                                'facility_name',
                                                'like',
                                                $search,
                                            ),
                                )
                                ->orWhereHas(
                                    'details.amenity',
                                    function ($amenityQuery) use ($search): void {
                                        $amenityQuery
                                            ->where(
                                                'amenity_description',
                                                'like',
                                                $search,
                                            )
                                            ->orWhereHas(
                                                'amenityName',
                                                fn ($nameQuery) =>
                                                    $nameQuery
                                                        ->where(
                                                            'amenity_name',
                                                            'like',
                                                            $search,
                                                        ),
                                            );
                                    },
                                )
                                ->orWhereHas(
                                    'assignedTo',
                                    function ($staffQuery) use ($search): void {
                                        $staffQuery
                                            ->where(
                                                'first_name',
                                                'like',
                                                $search,
                                            )
                                            ->orWhere(
                                                'last_name',
                                                'like',
                                                $search,
                                            )
                                            ->orWhere(
                                                'username',
                                                'like',
                                                $search,
                                            );
                                    },
                                );
                        },
                    );
                },
            )
            ->orderBy($sortField, $direction)
            ->orderByDesc('amenity_request_id')
            ->paginate($perPage);
    }

    private function checkedInBookings()
    {
        return Booking::query()
            ->with([
                'guest',
                'details.facility',
            ])
            ->whereIn(
                'status',
                ['Checked-in', 'Partially Checked-in'],
            )
            ->whereHas(
                'details',
                fn ($query) =>
                    $query->where('status', 'Checked-in'),
            )
            ->latest('booking_id')
            ->get();
    }

    private function rentableAmenities()
    {
        return Amenity::query()
            ->with('amenityName')
            ->where('amenity_type', 'Rentable')
            ->orderBy('amenity_name_id')
            ->get();
    }

    private function selectedBookingFacilities()
    {
        if ($this->form['booking_id'] === '') {
            return collect();
        }

        return BookingDetail::query()
            ->with('facility')
            ->where(
                'booking_id',
                (int) $this->form['booking_id'],
            )
            ->where('status', 'Checked-in')
            ->whereNotNull('facility_id')
            ->get();
    }

    private function editBookingFacilities()
    {
        if ($this->editingRequestId === '') {
            return collect();
        }

        $request = AmenityRequest::query()
            ->find($this->editingRequestId);

        if ($request === null) {
            return collect();
        }

        return BookingDetail::query()
            ->with('facility')
            ->where(
                'booking_id',
                $request->booking_id,
            )
            ->where('status', 'Checked-in')
            ->whereNotNull('facility_id')
            ->get();
    }

    private function calculateTotal(array $items): float
    {
        $total = 0.00;

        foreach ($items as $item) {
            $amenityId =
                (int) ($item['amenity_id'] ?? 0);
            $quantity =
                (int) ($item['quantity'] ?? 0);

            if ($amenityId > 0 && $quantity > 0) {
                $price = (float) Amenity::query()
                    ->where('amenity_id', $amenityId)
                    ->value('amenity_price');

                $total += $price * $quantity;
            }
        }

        return round($total, 2);
    }
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                Amenity Requests
            </h1>

            <p class="text-sm text-gray-600 dark:text-gray-400">
                Create billable requests for checked-in bookings, collect payment, then release them to maintenance.
            </p>
        </div>

        <flux:button
            wire:click="toggleCreateForm"
            variant="primary"
        >
            {{ $showCreateForm
                ? 'Close Form'
                : 'Add Amenity Request' }}
        </flux:button>
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @error('request')
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            {{ $message }}
        </div>
    @enderror

    @if ($showCreateForm)
        <flux:card>
            <h2 class="mb-4 text-lg font-semibold">
                Create Amenity Request
            </h2>

            <form wire:submit="createRequest" class="space-y-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:select
                        wire:model.live="form.booking_id"
                        label="Checked-in Booking"
                    >
                        <option value="">Select booking</option>

                        @foreach ($checkedInBookings as $booking)
                            <option value="{{ $booking->booking_id }}">
                                {{ $booking->b_ref_no }}
                                —
                                {{ $booking->guest->first_name }}
                                {{ $booking->guest->last_name }}
                            </option>
                        @endforeach
                    </flux:select>

                    <flux:select
                        wire:model="form.facility_id"
                        label="Delivery Facility"
                    >
                        <option value="">Select facility</option>

                        @foreach ($selectedBookingFacilities as $detail)
                            <option value="{{ $detail->facility_id }}">
                                {{ $detail->facility?->facility_name }}
                            </option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="font-medium">
                            Requested Amenities
                        </h3>

                        <flux:button
                            type="button"
                            wire:click="addItem"
                            size="sm"
                        >
                            Add Item
                        </flux:button>
                    </div>

                    @foreach ($items as $index => $item)
                        <div
                            wire:key="create-amenity-item-{{ $index }}"
                            class="grid gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-800 md:grid-cols-[1fr_120px_auto]"
                        >
                            <flux:select
                                wire:model.live="items.{{ $index }}.amenity_id"
                                label="Amenity"
                            >
                                <option value="">Select amenity</option>

                                @foreach ($rentableAmenities as $amenity)
                                    <option value="{{ $amenity->amenity_id }}">
                                        {{ $amenity->amenityName?->amenity_name }}
                                        —
                                        ₱{{ number_format((float) $amenity->amenity_price, 2) }}
                                    </option>
                                @endforeach
                            </flux:select>

                            <flux:input
                                wire:model.live="items.{{ $index }}.quantity"
                                label="Qty"
                                type="number"
                                min="1"
                            />

                            <div class="flex items-end">
                                <flux:button
                                    type="button"
                                    wire:click="removeItem({{ $index }})"
                                    variant="danger"
                                >
                                    Remove
                                </flux:button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-col gap-3 rounded-lg bg-gray-50 p-4 dark:bg-gray-800 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Request total
                        </p>

                        <p class="text-2xl font-semibold">
                            ₱{{ number_format($currentTotal, 2) }}
                        </p>

                        <p class="text-xs text-gray-500">
                            Added to the booking balance. Maintenance receives the request only after the booking balance is fully paid.
                        </p>
                    </div>

                    <flux:button
                        type="submit"
                        variant="primary"
                    >
                        Create Request
                    </flux:button>
                </div>
            </form>
        </flux:card>
    @endif

    @if ($editingRequestId !== '')
        <flux:card class="border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950">
            <h2 class="mb-4 text-lg font-semibold">
                Modify Unpaid Amenity Request #{{ $editingRequestId }}
            </h2>

            @error('edit')
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                    {{ $message }}
                </div>
            @enderror

            <form wire:submit="saveEdit" class="space-y-4">
                <flux:select
                    wire:model="editForm.facility_id"
                    label="Delivery Facility"
                >
                    <option value="">Select facility</option>

                    @foreach ($editBookingFacilities as $detail)
                        <option value="{{ $detail->facility_id }}">
                            {{ $detail->facility?->facility_name }}
                        </option>
                    @endforeach
                </flux:select>

                @foreach ($editItems as $index => $item)
                    <div
                        wire:key="edit-amenity-item-{{ $index }}"
                        class="grid gap-3 rounded-lg border border-amber-200 p-3 dark:border-amber-800 md:grid-cols-[1fr_120px_auto]"
                    >
                        <flux:select
                            wire:model.live="editItems.{{ $index }}.amenity_id"
                            label="Amenity"
                        >
                            <option value="">Select amenity</option>

                            @foreach ($rentableAmenities as $amenity)
                                <option value="{{ $amenity->amenity_id }}">
                                    {{ $amenity->amenityName?->amenity_name }}
                                    —
                                    ₱{{ number_format((float) $amenity->amenity_price, 2) }}
                                </option>
                            @endforeach
                        </flux:select>

                        <flux:input
                            wire:model.live="editItems.{{ $index }}.quantity"
                            label="Qty"
                            type="number"
                            min="1"
                        />

                        <div class="flex items-end">
                            <flux:button
                                type="button"
                                wire:click="removeEditItem({{ $index }})"
                                variant="danger"
                            >
                                Remove
                            </flux:button>
                        </div>
                    </div>
                @endforeach

                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Updated total
                        </p>

                        <p class="text-2xl font-semibold">
                            ₱{{ number_format($editTotal, 2) }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <flux:button
                            type="button"
                            wire:click="addEditItem"
                        >
                            Add Item
                        </flux:button>

                        <flux:button
                            type="button"
                            wire:click="cancelEdit"
                        >
                            Cancel Edit
                        </flux:button>

                        <flux:button
                            type="submit"
                            variant="primary"
                        >
                            Save Changes
                        </flux:button>
                    </div>
                </div>
            </form>
        </flux:card>
    @endif

    <flux:card class="overflow-hidden p-0">
        <div class="border-b border-gray-200 p-5 dark:border-gray-800">
            <div>
                <h2 class="font-semibold">Request history</h2>

                <p class="mt-1 text-sm text-gray-500">
                    One request may contain several amenity items delivered to one checked-in facility.
                </p>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    label="Search"
                    placeholder="Request, booking, guest, facility, amenity"
                    clearable
                />

                <flux:select
                    wire:model.live="statusFilter"
                    label="Status"
                >
                    <option value="">All statuses</option>
                    <option value="Awaiting Payment">Awaiting Payment</option>
                    <option value="Pending">Pending</option>
                    <option value="Delivering">Delivering</option>
                    <option value="Delivered">Delivered</option>
                    <option value="Cancelled">Cancelled</option>
                </flux:select>

                <flux:select
                    wire:model.live="perPage"
                    label="Rows per page"
                >
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </flux:select>

                <div class="flex items-end">
                    <flux:button
                        wire:click="clearListFilters"
                        variant="ghost"
                        class="w-full"
                    >
                        Clear Filters
                    </flux:button>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[84rem] divide-y divide-gray-200 text-sm dark:divide-gray-800">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-600 dark:bg-gray-900 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">
                            <button
                                wire:click="sortBy('amenity_request_id')"
                                class="font-semibold"
                            >
                                Request {{ $this->sortIndicator('amenity_request_id') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">
                            <button
                                wire:click="sortBy('date_created')"
                                class="font-semibold"
                            >
                                Created {{ $this->sortIndicator('date_created') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">Guest / Booking</th>
                        <th class="px-4 py-3">Items and Facility</th>

                        <th class="px-4 py-3">
                            <button
                                wire:click="sortBy('total_price')"
                                class="font-semibold"
                            >
                                Total {{ $this->sortIndicator('total_price') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">
                            <button
                                wire:click="sortBy('amenity_request_status')"
                                class="font-semibold"
                            >
                                Status {{ $this->sortIndicator('amenity_request_status') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">Created By</th>
                        <th class="px-4 py-3">Assigned / Delivered</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($requests as $request)
                        <tr wire:key="cashier-amenity-request-{{ $request->amenity_request_id }}" class="align-top">
                            <td class="px-4 py-4 font-medium">
                                #{{ $request->amenity_request_id }}
                            </td>

                            <td class="px-4 py-4">
                                {{ $request->date_created?->format('M d, Y') ?? 'N/A' }}
                            </td>

                            <td class="px-4 py-4">
                                <p class="font-medium">
                                    {{ $request->booking?->guest?->full_name
                                        ?? trim(($request->booking?->guest?->first_name ?? '').' '.($request->booking?->guest?->last_name ?? ''))
                                        ?: 'Unknown guest' }}
                                </p>

                                <p class="mt-1 text-xs text-gray-500">
                                    {{ $request->booking?->b_ref_no ?? 'No booking reference' }}
                                </p>
                            </td>

                            <td class="max-w-xl px-4 py-4">
                                <ul class="space-y-1 text-xs leading-5">
                                    @foreach ($request->details as $detail)
                                        <li>
                                            {{ $detail->amenity?->amenityName?->amenity_name ?? 'Unknown amenity' }}
                                            × {{ $detail->amenity_quantity }}
                                            →
                                            {{ $detail->facility?->facility_name ?? 'Unknown facility' }}
                                        </li>
                                    @endforeach
                                </ul>
                            </td>

                            <td class="px-4 py-4 font-semibold">
                                ₱{{ number_format((float) $request->total_price, 2) }}
                            </td>

                            <td class="px-4 py-4">
                                <flux:badge
                                    color="{{ $this->statusColor((string) $request->amenity_request_status) }}"
                                    size="sm"
                                >
                                    {{ $request->amenity_request_status }}
                                </flux:badge>
                            </td>

                            <td class="px-4 py-4">
                                {{ $this->staffName($request->user) }}
                            </td>

                            <td class="px-4 py-4">
                                <p>{{ $this->staffName($request->assignedTo) }}</p>

                                @if ($request->delivered_at)
                                    <p class="mt-1 text-xs text-gray-500">
                                        {{ $request->delivered_at->format('M d, Y h:i A') }}
                                    </p>
                                @elseif ($request->cancelled_at)
                                    <p class="mt-1 text-xs text-red-500">
                                        Cancelled {{ $request->cancelled_at->format('M d, Y h:i A') }}
                                    </p>
                                @endif
                            </td>

                            <td class="px-4 py-4 text-right">
                                <div class="flex flex-wrap justify-end gap-2">
                                    @if ($request->booking_id && Route::has('cashier.bookings.show'))
                                        <flux:button
                                            href="{{ route('cashier.bookings.show', $request->booking_id) }}"
                                            wire:navigate
                                            size="sm"
                                            variant="ghost"
                                        >
                                            Booking
                                        </flux:button>
                                    @endif

                                    @if ($request->amenity_request_status === 'Awaiting Payment')
                                        @if (Route::has('cashier.payments.index'))
                                            <flux:button
                                                href="{{ route('cashier.payments.index', ['booking' => $request->booking_id]) }}"
                                                wire:navigate
                                                size="sm"
                                                variant="primary"
                                            >
                                                Payment
                                            </flux:button>
                                        @endif

                                        <flux:button
                                            size="sm"
                                            wire:click="openEdit({{ $request->amenity_request_id }})"
                                        >
                                            Modify
                                        </flux:button>

                                        <flux:button
                                            size="sm"
                                            variant="danger"
                                            wire:click="cancelRequest({{ $request->amenity_request_id }})"
                                            wire:confirm="Cancel this unpaid amenity request?"
                                        >
                                            Cancel
                                        </flux:button>
                                    @endif
                                </div>

                                @if ($request->amenity_request_status !== 'Awaiting Payment' && ! Route::has('cashier.bookings.show'))
                                    <span class="text-xs text-gray-500">
                                        No cashier action
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-12 text-center text-gray-500">
                                No amenity requests match the current filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex flex-col gap-3 border-t border-gray-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
            <p class="text-sm text-gray-500">
                Showing
                {{ $requests->firstItem() ?? 0 }}
                to
                {{ $requests->lastItem() ?? 0 }}
                of
                {{ $requests->total() }}
                amenity requests
            </p>

            {{ $requests->links() }}
        </div>
    </flux:card>
</div>
