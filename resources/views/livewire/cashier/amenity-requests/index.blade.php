<?php

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\BookingDetail;
use App\Services\AmenityRequestWorkflowService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
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

    public function with(): array
    {
        return [
            'requests' => $this->requests(),
            'checkedInBookings' => $this->checkedInBookings(),
            'rentableAmenities' => $this->rentableAmenities(),
            'selectedBookingFacilities' => $this->selectedBookingFacilities(),
            'editBookingFacilities' => $this->editBookingFacilities(),
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

    public function updatedFormBookingId(): void
    {
        $this->form['facility_id'] = '';
    }

    public function toggleCreateForm(): void
    {
        $this->showCreateForm = ! $this->showCreateForm;
    }

    public function addItem(): void
    {
        $this->items[] = ['amenity_id' => '', 'quantity' => 1];
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
        $this->editItems[] = ['amenity_id' => '', 'quantity' => 1];
    }

    public function removeEditItem(int $index): void
    {
        unset($this->editItems[$index]);
        $this->editItems = array_values($this->editItems);

        if ($this->editItems === []) {
            $this->addEditItem();
        }
    }

    public function createRequest(AmenityRequestWorkflowService $workflow): void
    {
        $validated = $this->validate([
            'form.booking_id' => ['required', 'integer', 'exists:tbl_booking,booking_id'],
            'form.facility_id' => ['required', 'integer', 'exists:tbl_facility,facility_id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.amenity_id' => ['required', 'integer', 'exists:tbl_amenity,amenity_id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $workflow->createBillableRequest([
                'booking_id' => (int) $validated['form']['booking_id'],
                'facility_id' => (int) $validated['form']['facility_id'],
                'items' => $validated['items'],
                'user_id' => Auth::id(),
            ]);

            $this->resetCreateForm();
            $this->showCreateForm = false;
            $this->resetPage();
            session()->flash('success', 'Amenity request created. Collect payment in the Payment module before maintenance delivers it.');
        } catch (\Throwable $exception) {
            $this->addError('request', $exception->getMessage());
        }
    }

    public function openEdit(int $amenityRequestId): void
    {
        $request = AmenityRequest::query()
            ->with(['details'])
            ->findOrFail($amenityRequestId);

        if ($request->amenity_request_status !== 'Awaiting Payment') {
            $this->addError('edit', 'Only unpaid amenity requests can be modified.');
            return;
        }

        $firstDetail = $request->details->first();

        $this->editingRequestId = (string) $request->amenity_request_id;
        $this->editForm = [
            'facility_id' => (string) ($firstDetail?->facility_id ?? ''),
        ];
        $this->editItems = $request->details->map(fn ($detail) => [
            'amenity_id' => (string) $detail->amenity_id,
            'quantity' => (int) $detail->amenity_quantity,
        ])->values()->all();

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

    public function saveEdit(AmenityRequestWorkflowService $workflow): void
    {
        $validated = $this->validate([
            'editingRequestId' => ['required', 'integer', 'exists:tbl_amenity_request,amenity_request_id'],
            'editForm.facility_id' => ['required', 'integer', 'exists:tbl_facility,facility_id'],
            'editItems' => ['required', 'array', 'min:1'],
            'editItems.*.amenity_id' => ['required', 'integer', 'exists:tbl_amenity,amenity_id'],
            'editItems.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $workflow->updateBillableRequest(
                (int) $validated['editingRequestId'],
                (int) $validated['editForm']['facility_id'],
                $validated['editItems']
            );

            $this->cancelEdit();
            $this->resetPage();
            session()->flash('success', 'Amenity request updated successfully.');
        } catch (\Throwable $exception) {
            $this->addError('edit', $exception->getMessage());
        }
    }

    public function cancelRequest(int $amenityRequestId, AmenityRequestWorkflowService $workflow): void
    {
        try {
            $workflow->cancelUnpaidRequest($amenityRequestId);
            $this->resetPage();
            session()->flash('success', 'Unpaid amenity request cancelled.');
        } catch (\Throwable $exception) {
            $this->addError('request', $exception->getMessage());
        }
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
        return AmenityRequest::query()
            ->with(['booking.guest', 'details.amenity.amenityName', 'details.facility', 'user', 'assignedTo'])
            ->when($this->statusFilter !== '', fn ($query) => $query->where('amenity_request_status', $this->statusFilter))
            ->when($this->search !== '', function ($query) {
                $search = '%' . $this->search . '%';

                $query->where(function ($query) use ($search) {
                    $query->whereHas('booking', fn ($bookingQuery) => $bookingQuery->where('b_ref_no', 'like', $search))
                        ->orWhereHas('booking.guest', function ($guestQuery) use ($search) {
                            $guestQuery->where('first_name', 'like', $search)
                                ->orWhere('last_name', 'like', $search)
                                ->orWhere('contact_no', 'like', $search);
                        });
                });
            })
            ->latest('amenity_request_id')
            ->paginate(10);
    }

    private function checkedInBookings()
    {
        return Booking::query()
            ->with(['guest', 'details.facility'])
            ->whereIn('status', ['Checked-in', 'Partially Checked-in'])
            ->whereHas('details', fn ($query) => $query->where('status', 'Checked-in'))
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
            ->where('booking_id', (int) $this->form['booking_id'])
            ->where('status', 'Checked-in')
            ->whereNotNull('facility_id')
            ->get();
    }

    private function editBookingFacilities()
    {
        if ($this->editingRequestId === '') {
            return collect();
        }

        $request = AmenityRequest::query()->find($this->editingRequestId);

        if (! $request) {
            return collect();
        }

        return BookingDetail::query()
            ->with('facility')
            ->where('booking_id', $request->booking_id)
            ->where('status', 'Checked-in')
            ->whereNotNull('facility_id')
            ->get();
    }

    private function calculateTotal(array $items): float
    {
        $total = 0.00;

        foreach ($items as $item) {
            $amenityId = (int) ($item['amenity_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($amenityId > 0 && $quantity > 0) {
                $price = (float) Amenity::query()->where('amenity_id', $amenityId)->value('amenity_price');
                $total += $price * $quantity;
            }
        }

        return round($total, 2);
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Amenity Requests</h1>
            <p class="text-sm text-gray-600 dark:text-gray-400">Create billable amenity requests for checked-in bookings, then collect payment before maintenance delivery.</p>
        </div>

        <flux:button wire:click="toggleCreateForm" variant="primary">
            {{ $showCreateForm ? 'Close Form' : 'Add Amenity Request' }}
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
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">Create Amenity Request</h2>

            <form wire:submit="createRequest" class="space-y-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:select wire:model.live="form.booking_id" label="Checked-in Booking">
                        <option value="">Select booking</option>
                        @foreach ($checkedInBookings as $booking)
                            <option value="{{ $booking->booking_id }}">
                                {{ $booking->b_ref_no }} — {{ $booking->guest->first_name }} {{ $booking->guest->last_name }}
                            </option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="form.facility_id" label="Delivery Facility">
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
                        <h3 class="font-medium text-gray-900 dark:text-gray-100">Requested Amenities</h3>
                        <flux:button type="button" wire:click="addItem" size="sm">Add Item</flux:button>
                    </div>

                    @foreach ($items as $index => $item)
                        <div class="grid gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-800 md:grid-cols-[1fr_120px_auto]">
                            <flux:select wire:model.live="items.{{ $index }}.amenity_id" label="Amenity">
                                <option value="">Select amenity</option>
                                @foreach ($rentableAmenities as $amenity)
                                    <option value="{{ $amenity->amenity_id }}">
                                        {{ $amenity->amenityName?->amenity_name }} — ₱{{ number_format((float) $amenity->amenity_price, 2) }}
                                    </option>
                                @endforeach
                            </flux:select>

                            <flux:input wire:model.live="items.{{ $index }}.quantity" label="Qty" type="number" min="1" />

                            <div class="flex items-end">
                                <flux:button type="button" wire:click="removeItem({{ $index }})" variant="danger">Remove</flux:button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-col gap-3 rounded-lg bg-gray-50 p-4 dark:bg-gray-800 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Request total</p>
                        <p class="text-2xl font-semibold text-gray-900 dark:text-gray-100">₱{{ number_format($currentTotal, 2) }}</p>
                        <p class="text-xs text-gray-500">This amount will be added to the booking balance and must be paid before maintenance can deliver.</p>
                    </div>
                    <flux:button type="submit" variant="primary">Create Request</flux:button>
                </div>
            </form>
        </div>
    @endif

    @if ($editingRequestId !== '')
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 shadow-sm dark:border-amber-900 dark:bg-amber-950">
            <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">Modify Unpaid Amenity Request #{{ $editingRequestId }}</h2>

            @error('edit')
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                    {{ $message }}
                </div>
            @enderror

            <form wire:submit="saveEdit" class="space-y-4">
                <flux:select wire:model="editForm.facility_id" label="Delivery Facility">
                    <option value="">Select facility</option>
                    @foreach ($editBookingFacilities as $detail)
                        <option value="{{ $detail->facility_id }}">{{ $detail->facility?->facility_name }}</option>
                    @endforeach
                </flux:select>

                @foreach ($editItems as $index => $item)
                    <div class="grid gap-3 rounded-lg border border-amber-200 p-3 dark:border-amber-800 md:grid-cols-[1fr_120px_auto]">
                        <flux:select wire:model.live="editItems.{{ $index }}.amenity_id" label="Amenity">
                            <option value="">Select amenity</option>
                            @foreach ($rentableAmenities as $amenity)
                                <option value="{{ $amenity->amenity_id }}">
                                    {{ $amenity->amenityName?->amenity_name }} — ₱{{ number_format((float) $amenity->amenity_price, 2) }}
                                </option>
                            @endforeach
                        </flux:select>

                        <flux:input wire:model.live="editItems.{{ $index }}.quantity" label="Qty" type="number" min="1" />

                        <div class="flex items-end">
                            <flux:button type="button" wire:click="removeEditItem({{ $index }})" variant="danger">Remove</flux:button>
                        </div>
                    </div>
                @endforeach

                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Updated total</p>
                        <p class="text-2xl font-semibold text-gray-900 dark:text-gray-100">₱{{ number_format($editTotal, 2) }}</p>
                    </div>
                    <div class="flex gap-2">
                        <flux:button type="button" wire:click="addEditItem">Add Item</flux:button>
                        <flux:button type="button" wire:click="cancelEdit">Cancel Edit</flux:button>
                        <flux:button type="submit" variant="primary">Save Changes</flux:button>
                    </div>
                </div>
            </form>
        </div>
    @endif

    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="mb-4 grid gap-3 md:grid-cols-2">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search guest or booking ref..." label="Search" />
            <flux:select wire:model.live="statusFilter" label="Status">
                <option value="">All statuses</option>
                <option value="Awaiting Payment">Awaiting Payment</option>
                <option value="Pending">Pending</option>
                <option value="Delivering">Delivering</option>
                <option value="Delivered">Delivered</option>
                <option value="Cancelled">Cancelled</option>
            </flux:select>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                <thead>
                    <tr class="text-left text-gray-600 dark:text-gray-400">
                        <th class="px-3 py-2">Ref</th>
                        <th class="px-3 py-2">Guest</th>
                        <th class="px-3 py-2">Items</th>
                        <th class="px-3 py-2">Total</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2">Assigned</th>
                        <th class="px-3 py-2">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($requests as $request)
                        <tr>
                            <td class="px-3 py-3 font-medium text-gray-900 dark:text-gray-100">#{{ $request->amenity_request_id }}<br><span class="text-xs text-gray-500">{{ $request->booking?->b_ref_no }}</span></td>
                            <td class="px-3 py-3">{{ $request->booking?->guest?->first_name }} {{ $request->booking?->guest?->last_name }}</td>
                            <td class="px-3 py-3">
                                <ul class="list-inside list-disc">
                                    @foreach ($request->details as $detail)
                                        <li>{{ $detail->amenity?->amenityName?->amenity_name }} x {{ $detail->amenity_quantity }} → {{ $detail->facility?->facility_name }}</li>
                                    @endforeach
                                </ul>
                            </td>
                            <td class="px-3 py-3">₱{{ number_format((float) $request->total_price, 2) }}</td>
                            <td class="px-3 py-3">{{ $request->amenity_request_status }}</td>
                            <td class="px-3 py-3">{{ $request->assignedTo?->first_name ?? '—' }}</td>
                            <td class="px-3 py-3">
                                @if ($request->amenity_request_status === 'Awaiting Payment')
                                    <div class="flex flex-wrap gap-2">
                                        <flux:button size="sm" wire:click="openEdit({{ $request->amenity_request_id }})">Modify</flux:button>
                                        <flux:button size="sm" variant="danger" wire:click="cancelRequest({{ $request->amenity_request_id }})" wire:confirm="Cancel this unpaid amenity request?">Cancel</flux:button>
                                    </div>
                                @else
                                    <span class="text-xs text-gray-500">No cashier action</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-6 text-center text-gray-500">No amenity requests found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $requests->links() }}
        </div>
    </div>
</div>
