<?php

use App\Models\BookingDetail;
use App\Models\Fine;
use App\Models\GuestFine;
use App\Services\CheckOutWorkflowService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = 'eligible';
    public string $sortField = 'check_out_date';
    public string $sortDirection = 'asc';

    public ?int $selectedBookingDetailsId = null;
    public ?int $selectedBookingId = null;
    public string $selectedLabel = '';
    public float $selectedBookingAmountDue = 0.00;

    public ?int $fineId = null;
    public ?int $fineFacilityId = null;
    public int $fineQuantity = 1;

    public function with(): array
    {
        return [
            'bookingDetails' => $this->bookingDetails(),
            'fines' => $this->fines(),
            'selectedGuestFines' => $this->selectedGuestFines(),
            'selectedBookingFacilities' => $this->selectedBookingFacilities(),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->cancelSelection();
        $this->resetPage();
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
            ->with(['booking.guest', 'booking.details.facility', 'facility'])
            ->findOrFail($bookingDetailsId);

        $guest = $detail->booking->guest;
        $facilityName = $detail->facility !== null ? $detail->facility->facility_name : 'No facility';

        $this->selectedBookingDetailsId = $bookingDetailsId;
        $this->selectedBookingId = (int) $detail->booking_id;
        $this->selectedBookingAmountDue = (float) $detail->booking->amount_due;
        $this->selectedLabel = $detail->booking->b_ref_no . ' - ' . $guest->first_name . ' ' . $guest->last_name . ' - ' . $facilityName;
        $this->fineId = null;
        $this->fineFacilityId = $detail->facility_id !== null ? (int) $detail->facility_id : null;
        $this->fineQuantity = 1;
    }

    public function cancelSelection(): void
    {
        $this->selectedBookingDetailsId = null;
        $this->selectedBookingId = null;
        $this->selectedLabel = '';
        $this->selectedBookingAmountDue = 0.00;
        $this->fineId = null;
        $this->fineFacilityId = null;
        $this->fineQuantity = 1;
    }

    public function addFine(CheckOutWorkflowService $checkOutWorkflow): void
    {
        $validated = $this->validate([
            'selectedBookingDetailsId' => ['required', 'integer', 'exists:tbl_booking_details,booking_details_id'],
            'fineId' => ['required', 'integer', 'exists:tbl_fine,fine_id'],
            'fineFacilityId' => ['required', 'integer', 'exists:tbl_facility,facility_id'],
            'fineQuantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $checkOutWorkflow->recordFineForBookingDetail(
                (int) $validated['selectedBookingDetailsId'],
                (int) $validated['fineId'],
                (int) $validated['fineFacilityId'],
                (int) $validated['fineQuantity']
            );

            $this->refreshSelectedAmountDue();
            $this->fineId = null;
            $this->fineQuantity = 1;
            session()->flash('success', 'Fine recorded. The guest balance was updated.');
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
            session()->flash('success', 'Guest checked out successfully.');
        } catch (\Throwable $exception) {
            $this->addError('checkOut', $exception->getMessage());
        }
    }

    public function formatFineLabel(?Fine $fine): string
    {
        if ($fine === null) {
            return 'Unknown fine';
        }

        $charge = '₱' . number_format((float) $fine->fine_charge, 2);

        if ((string) $fine->fine_type === 'Amenity Fine') {
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

    private function refreshSelectedAmountDue(): void
    {
        if ($this->selectedBookingDetailsId === null) {
            $this->selectedBookingAmountDue = 0.00;
            return;
        }

        $detail = BookingDetail::query()
            ->with('booking')
            ->find($this->selectedBookingDetailsId);

        $this->selectedBookingAmountDue = $detail !== null && $detail->booking !== null
            ? (float) $detail->booking->amount_due
            : 0.00;
    }

    private function bookingDetails(): LengthAwarePaginator
    {
        $query = BookingDetail::query()
            ->select('tbl_booking_details.*')
            ->join('tbl_booking', 'tbl_booking.booking_id', '=', 'tbl_booking_details.booking_id')
            ->join('tbl_guest', 'tbl_guest.guest_id', '=', 'tbl_booking.guest_id')
            ->leftJoin('tbl_facility', 'tbl_facility.facility_id', '=', 'tbl_booking_details.facility_id')
            ->with(['booking.guest', 'facility.facilityType']);

        if ($this->statusFilter === 'checked_out') {
            $query->where('tbl_booking_details.status', 'Checked-out');
        } else {
            $query->where('tbl_booking_details.status', 'Checked-in')
                ->whereNotIn('tbl_booking.status', ['Cancelled', 'Checked-out']);
        }

        $searchText = trim($this->search);

        if ($searchText !== '') {
            $needle = '%' . $searchText . '%';

            $query->whereRaw(
                '(tbl_booking.b_ref_no LIKE ? OR tbl_guest.first_name LIKE ? OR tbl_guest.last_name LIKE ? OR tbl_guest.contact_no LIKE ? OR tbl_facility.facility_name LIKE ?)',
                [$needle, $needle, $needle, $needle, $needle]
            );
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

        return $query->paginate(10);
    }

    private function fines(): Collection
    {
        return Fine::query()
            ->with(['amenity.amenityName', 'damageType'])
            ->orderBy('fine_type')
            ->orderBy('fine_id')
            ->get();
    }

    private function selectedGuestFines(): Collection
    {
        if ($this->selectedBookingId === null) {
            return new Collection();
        }

        return GuestFine::query()
            ->with(['fine.amenity.amenityName', 'fine.damageType', 'facility'])
            ->where('booking_id', $this->selectedBookingId)
            ->latest('guest_fine_id')
            ->get();
    }

    private function selectedBookingFacilities(): array
    {
        if ($this->selectedBookingId === null) {
            return [];
        }

        $details = BookingDetail::query()
            ->with('facility')
            ->where('booking_id', $this->selectedBookingId)
            ->whereNotNull('facility_id')
            ->get();

        $facilities = [];

        foreach ($details as $detail) {
            if ($detail->facility === null) {
                continue;
            }

            $facilities[] = [
                'facility_id' => (int) $detail->facility_id,
                'label' => (string) $detail->facility->facility_name,
            ];
        }

        return $facilities;
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">Cashier Check-out</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">Inspect checked-in guests, record fines when needed, and release facilities after the bill is settled.</p>
        </div>
    </div>

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
                    @if ($selectedBookingAmountDue > 0)
                        <p class="mt-1 text-xs text-amber-800 dark:text-amber-200">Settle this balance in the Payment module before check-out.</p>
                    @endif
                </div>

                <div class="flex gap-2">
                    @if ($selectedBookingAmountDue <= 0)
                        <flux:button variant="primary" wire:click="confirmCheckOut">Confirm Check-out</flux:button>
                    @else
                        <flux:button variant="primary" disabled>Payment Required</flux:button>
                    @endif
                    <flux:button variant="ghost" wire:click="cancelSelection">Cancel</flux:button>
                </div>
            </div>

            <div class="mt-4 grid gap-3 lg:grid-cols-4">
                <flux:select label="Fine" wire:model.live="fineId">
                    <option value="">Select fine</option>
                    @foreach ($fines as $fine)
                        <option value="{{ $fine->fine_id }}">{{ $this->formatFineLabel($fine) }}</option>
                    @endforeach
                </flux:select>

                <flux:select label="Facility" wire:model.live="fineFacilityId">
                    <option value="">Select facility</option>
                    @foreach ($selectedBookingFacilities as $facility)
                        <option value="{{ $facility['facility_id'] }}">{{ $facility['label'] }}</option>
                    @endforeach
                </flux:select>

                <flux:input label="Quantity" type="number" min="1" wire:model.live="fineQuantity" />

                <div class="flex items-end">
                    <flux:button wire:click="addFine">Record Fine</flux:button>
                </div>
            </div>

            @if ($selectedGuestFines->isNotEmpty())
                <div class="mt-4 overflow-hidden rounded-lg border border-amber-200 bg-white dark:border-amber-900 dark:bg-zinc-900">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                        <thead class="bg-zinc-50 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                            <tr>
                                <th class="px-3 py-2">Fine</th>
                                <th class="px-3 py-2">Facility</th>
                                <th class="px-3 py-2">Qty</th>
                                <th class="px-3 py-2">Charge</th>
                                <th class="px-3 py-2">Date Checked</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($selectedGuestFines as $guestFine)
                                <tr>
                                    <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">{{ $this->formatFineLabel($guestFine->fine) }}</td>
                                    <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">{{ $guestFine->facility !== null ? $guestFine->facility->facility_name : 'No facility' }}</td>
                                    <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">{{ $guestFine->quantity }}</td>
                                    <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">₱{{ number_format((float) $guestFine->total_charge, 2) }}</td>
                                    <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">{{ optional($guestFine->date_checked)->format('M d, Y') ?? $guestFine->date_checked }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="grid gap-3 md:grid-cols-3">
            <flux:input label="Search" placeholder="Reference, guest, contact, facility" wire:model.live.debounce.300ms="search" />

            <flux:select label="List" wire:model.live="statusFilter">
                <option value="eligible">Ready for check-out</option>
                <option value="checked_out">Already checked-out</option>
            </flux:select>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                <thead class="bg-zinc-50 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-3"><button wire:click="sortBy('b_ref_no')">Reference</button></th>
                        <th class="px-4 py-3"><button wire:click="sortBy('guest_name')">Guest</button></th>
                        <th class="px-4 py-3"><button wire:click="sortBy('facility_name')">Facility</button></th>
                        <th class="px-4 py-3">Rate</th>
                        <th class="px-4 py-3"><button wire:click="sortBy('check_in_date')">Check-in</button></th>
                        <th class="px-4 py-3"><button wire:click="sortBy('check_out_date')">Check-out</button></th>
                        <th class="px-4 py-3"><button wire:click="sortBy('amount_due')">Due</button></th>
                        <th class="px-4 py-3"><button wire:click="sortBy('status')">Status</button></th>
                        <th class="px-4 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($bookingDetails as $detail)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">{{ $detail->booking->b_ref_no }}</td>
                            <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                                {{ $detail->booking->guest->first_name }} {{ $detail->booking->guest->last_name }}
                                <div class="text-xs text-zinc-500">{{ $detail->booking->guest->contact_no }}</div>
                            </td>
                            <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                                {{ $detail->facility !== null ? $detail->facility->facility_name : 'No facility' }}
                                <div class="text-xs text-zinc-500">{{ $detail->facility !== null && $detail->facility->facilityType !== null ? $detail->facility->facilityType->facility_type : '' }}</div>
                            </td>
                            <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">{{ $detail->rate_type }}</td>
                            <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">{{ optional($detail->check_in_date)->format('M d, Y') ?? $detail->check_in_date }}</td>
                            <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">{{ optional($detail->check_out_date)->format('M d, Y') ?? $detail->check_out_date }}</td>
                            <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">₱{{ number_format((float) $detail->booking->amount_due, 2) }}</td>
                            <td class="px-4 py-3">
                                <flux:badge>{{ $detail->status }}</flux:badge>
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if ($statusFilter === 'eligible')
                                    <flux:button size="sm" wire:click="selectCheckOut({{ $detail->booking_details_id }})">Inspect / Check-out</flux:button>
                                @else
                                    <span class="text-xs text-zinc-500">Released</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-sm text-zinc-500">No booking details found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-800">
            {{ $bookingDetails->links() }}
        </div>
    </div>
</div>
