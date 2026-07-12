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

    public function sortIndicator(string $field): string
    {
        if ($this->sortField !== $field) {
            return '';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
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

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">Cashier Check-out</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">Verify maintenance inspection and payment status before releasing the facility.</p>
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
                    @if ($selectedInspectionRequest === null)
                        <flux:button wire:click="sendInspectionRequest">Send Inspection Request</flux:button>
                    @elseif ($selectedInspectionRequest->status !== 'Completed')
                        <flux:button disabled>Waiting for Maintenance</flux:button>
                    @endif

                    @if ($selectedInspectionRequest !== null && $selectedInspectionRequest->status === 'Completed' && $selectedInspection !== null && $selectedBookingAmountDue <= 0)
                        <flux:button variant="primary" wire:click="confirmCheckOut">Confirm Check-out</flux:button>
                    @else
                        <flux:button variant="primary" disabled>Not Ready</flux:button>
                    @endif
                    <flux:button variant="ghost" wire:click="cancelSelection">Cancel</flux:button>
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

    <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
        <div class="grid gap-3 md:grid-cols-3">
            <flux:input label="Search" placeholder="Reference, guest, contact, facility..." wire:model.live.debounce.300ms="search" />
            <flux:select label="Status" wire:model.live="statusFilter">
                <option value="eligible">Checked-in / Pending check-out</option>
                <option value="checked_out">Checked-out history</option>
            </flux:select>
        </div>

        <div class="mt-4 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-3 py-2 text-left"><button wire:click="sortBy('b_ref_no')">Ref {{ $this->sortIndicator('b_ref_no') }}</button></th>
                        <th class="px-3 py-2 text-left"><button wire:click="sortBy('guest_name')">Guest {{ $this->sortIndicator('guest_name') }}</button></th>
                        <th class="px-3 py-2 text-left"><button wire:click="sortBy('facility_name')">Facility {{ $this->sortIndicator('facility_name') }}</button></th>
                        <th class="px-3 py-2 text-left"><button wire:click="sortBy('check_in_date')">Check-in {{ $this->sortIndicator('check_in_date') }}</button></th>
                        <th class="px-3 py-2 text-left"><button wire:click="sortBy('check_out_date')">Check-out {{ $this->sortIndicator('check_out_date') }}</button></th>
                        <th class="px-3 py-2 text-left"><button wire:click="sortBy('amount_due')">Balance {{ $this->sortIndicator('amount_due') }}</button></th>
                        <th class="px-3 py-2 text-left">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($bookingDetails as $detail)
                        <tr>
                            <td class="px-3 py-2 font-medium">{{ $detail->booking->b_ref_no }}</td>
                            <td class="px-3 py-2">{{ $detail->booking->guest->first_name }} {{ $detail->booking->guest->last_name }}</td>
                            <td class="px-3 py-2">{{ $detail->facility?->facility_name ?? 'No facility' }}</td>
                            <td class="px-3 py-2">{{ $detail->check_in_date?->format('M d, Y') }}</td>
                            <td class="px-3 py-2">{{ $detail->check_out_date?->format('M d, Y') }}</td>
                            <td class="px-3 py-2">₱{{ number_format((float) $detail->booking->amount_due, 2) }}</td>
                            <td class="px-3 py-2">
                                <flux:button size="sm" wire:click="selectCheckOut({{ $detail->booking_details_id }})">View</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-8 text-center text-zinc-500">No check-out records found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $bookingDetails->links() }}
        </div>
    </div>
</div>
