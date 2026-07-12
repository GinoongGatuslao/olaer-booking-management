<?php

use App\Models\BookingDetail;
use App\Services\CheckInWorkflowService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = 'eligible';
    public string $sortField = 'check_in_date';
    public string $sortDirection = 'asc';
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

        return $query->paginate(10);
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">Cashier Check-in</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">Verify fully paid bookings and mark facilities as occupied.</p>
        </div>
    </div>

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

    <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="grid gap-3 md:grid-cols-3">
            <flux:input label="Search" placeholder="Reference, guest, contact, facility" wire:model.live.debounce.300ms="search" />

            <flux:select label="List" wire:model.live="statusFilter">
                <option value="eligible">Ready for check-in</option>
                <option value="checked_in">Already checked-in</option>
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
                                {{ optional($detail->facility)->facility_name ?? 'No facility' }}
                                <div class="text-xs text-zinc-500">{{ optional(optional($detail->facility)->facilityType)->facility_type }}</div>
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
                                    <flux:button size="sm" wire:click="selectCheckIn({{ $detail->booking_details_id }})">Check-in</flux:button>
                                @else
                                    <span class="text-xs text-zinc-500">Checked in at {{ $detail->check_in_time ?? 'recorded' }}</span>
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
