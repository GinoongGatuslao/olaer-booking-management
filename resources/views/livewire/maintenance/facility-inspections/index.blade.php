<?php

use App\Models\BookingDetail;
use App\Models\FacilityInspection;
use App\Models\Fine;
use App\Models\GuestFine;
use App\Services\FacilityInspectionWorkflowService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = 'checked_in';
    public string $sortField = 'check_out_date';
    public string $sortDirection = 'asc';

    public ?int $selectedBookingDetailsId = null;
    public ?int $selectedBookingId = null;
    public string $selectedLabel = '';
    public string $selectedChecklistKey = '';
    public ?int $fineId = null;
    public int $fineQuantity = 1;
    public string $remarks = '';

    public function with(): array
    {
        return [
            'bookingDetails' => $this->bookingDetails(),
            'fines' => $this->fines(),
            'selectedChecklistItems' => $this->selectedChecklistItems(),
            'selectedGuestFines' => $this->selectedGuestFines(),
            'selectedInspection' => $this->selectedInspection(),
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

    public function updatedSelectedChecklistKey(): void
    {
        $this->fineId = null;
    }

    public function sortBy(string $field): void
    {
        $allowed = ['b_ref_no', 'guest_name', 'facility_name', 'check_in_date', 'check_out_date', 'inspection_status'];

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

    public function selectInspection(int $bookingDetailsId): void
    {
        $detail = BookingDetail::query()
            ->with(['booking.guest', 'facility'])
            ->findOrFail($bookingDetailsId);

        $guest = $detail->booking->guest;
        $facilityName = $detail->facility !== null ? $detail->facility->facility_name : 'No facility';

        $this->selectedBookingDetailsId = $bookingDetailsId;
        $this->selectedBookingId = (int) $detail->booking_id;
        $this->selectedLabel = $detail->booking->b_ref_no . ' - ' . $guest->first_name . ' ' . $guest->last_name . ' - ' . $facilityName;
        $this->selectedChecklistKey = '';
        $this->fineId = null;
        $this->fineQuantity = 1;
        $this->remarks = '';
    }

    public function cancelSelection(): void
    {
        $this->selectedBookingDetailsId = null;
        $this->selectedBookingId = null;
        $this->selectedLabel = '';
        $this->selectedChecklistKey = '';
        $this->fineId = null;
        $this->fineQuantity = 1;
        $this->remarks = '';
    }

    public function markNoDamage(FacilityInspectionWorkflowService $inspectionWorkflow): void
    {
        $validated = $this->validate([
            'selectedBookingDetailsId' => ['required', 'integer', 'exists:tbl_booking_details,booking_details_id'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $inspectionWorkflow->markNoDamage(
                (int) $validated['selectedBookingDetailsId'],
                (int) Auth::id(),
                trim((string) $validated['remarks']) !== '' ? trim((string) $validated['remarks']) : null
            );

            session()->flash('success', 'Inspection saved as cleared. Inclusive amenities and delivered requested amenities were recorded as complete.');
        } catch (\Throwable $exception) {
            $this->addError('inspection', $exception->getMessage());
        }
    }

    public function addFine(FacilityInspectionWorkflowService $inspectionWorkflow): void
    {
        $validated = $this->validate([
            'selectedBookingDetailsId' => ['required', 'integer', 'exists:tbl_booking_details,booking_details_id'],
            'selectedChecklistKey' => ['nullable', 'string', 'max:80'],
            'fineId' => ['required', 'integer', 'exists:tbl_fine,fine_id'],
            'fineQuantity' => ['required', 'integer', 'min:1', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        [$source, $sourceId] = $this->parseChecklistKey((string) ($validated['selectedChecklistKey'] ?? ''));

        try {
            $inspectionWorkflow->recordFine(
                (int) $validated['selectedBookingDetailsId'],
                (int) $validated['fineId'],
                (int) $validated['fineQuantity'],
                (int) Auth::id(),
                trim((string) $validated['remarks']) !== '' ? trim((string) $validated['remarks']) : null,
                $source,
                $sourceId
            );

            $this->fineId = null;
            $this->fineQuantity = 1;
            session()->flash('success', 'Fine recorded. The cashier must collect the added balance before check-out.');
        } catch (\Throwable $exception) {
            $this->addError('inspection', $exception->getMessage());
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

    public function sortIndicator(string $field): string
    {
        if ($this->sortField !== $field) {
            return '';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    private function parseChecklistKey(string $key): array
    {
        if ($key === '') {
            return [null, null];
        }

        $parts = explode(':', $key, 2);

        if (count($parts) !== 2 || ! in_array($parts[0], ['facility_amenity', 'amenity_request'], true) || (int) $parts[1] < 1) {
            throw new \InvalidArgumentException('Invalid checklist item selection.');
        }

        return [$parts[0], (int) $parts[1]];
    }

    private function bookingDetails(): LengthAwarePaginator
    {
        $query = BookingDetail::query()
            ->select('tbl_booking_details.*')
            ->join('tbl_booking', 'tbl_booking.booking_id', '=', 'tbl_booking_details.booking_id')
            ->join('tbl_guest', 'tbl_guest.guest_id', '=', 'tbl_booking.guest_id')
            ->leftJoin('tbl_facility', 'tbl_facility.facility_id', '=', 'tbl_booking_details.facility_id')
            ->leftJoin('tbl_facility_inspection', 'tbl_facility_inspection.booking_details_id', '=', 'tbl_booking_details.booking_details_id')
            ->with(['booking.guest', 'facility.facilityType']);

        if ($this->statusFilter === 'inspected') {
            $query->whereNotNull('tbl_facility_inspection.facility_inspection_id')
                ->where('tbl_booking_details.status', 'Checked-in');
        } elseif ($this->statusFilter === 'not_inspected') {
            $query->whereNull('tbl_facility_inspection.facility_inspection_id')
                ->where('tbl_booking_details.status', 'Checked-in');
        } else {
            $query->where('tbl_booking_details.status', 'Checked-in');
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
        } elseif ($this->sortField === 'inspection_status') {
            $query->orderBy('tbl_facility_inspection.inspection_status', $this->sortDirection);
        } else {
            $sortMap = [
                'b_ref_no' => 'tbl_booking.b_ref_no',
                'check_in_date' => 'tbl_booking_details.check_in_date',
                'check_out_date' => 'tbl_booking_details.check_out_date',
            ];

            $sortColumn = $sortMap[$this->sortField] ?? 'tbl_booking_details.check_out_date';
            $query->orderBy($sortColumn, $this->sortDirection);
        }

        return $query->paginate(10);
    }

    private function fines(): Collection
    {
        $query = Fine::query()->with(['amenity.amenityName', 'damageType']);

        if ($this->selectedChecklistKey !== '' && $this->selectedBookingDetailsId !== null) {
            $selectedItem = collect($this->selectedChecklistItems())
                ->firstWhere('key', $this->selectedChecklistKey);

            if ($selectedItem !== null) {
                $query->where('fine_type', 'Amenity Fine')
                    ->where('amenity_id', $selectedItem['amenity_id']);
            }
        }

        return $query->orderBy('fine_type')
            ->orderBy('fine_id')
            ->get();
    }

    private function selectedChecklistItems(): array
    {
        if ($this->selectedBookingDetailsId === null) {
            return [];
        }

        return app(FacilityInspectionWorkflowService::class)->checklistFor($this->selectedBookingDetailsId);
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

    private function selectedInspection(): ?FacilityInspection
    {
        if ($this->selectedBookingDetailsId === null) {
            return null;
        }

        return FacilityInspection::query()
            ->with(['inspectedBy', 'items.amenity.amenityName', 'items.fine.damageType'])
            ->where('booking_details_id', $this->selectedBookingDetailsId)
            ->first();
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">Facility Inspections</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">Inspect inclusive amenities and delivered amenity-request items before cashier check-out.</p>
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @error('inspection')
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            {{ $message }}
        </div>
    @enderror

    @if ($selectedBookingDetailsId !== null)
        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Selected inspection</p>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $selectedLabel }}</p>
                    @if ($selectedInspection !== null)
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                            Inspection status:
                            <span class="font-semibold">{{ $selectedInspection->inspection_status }}</span>
                            by {{ $selectedInspection->inspectedBy?->first_name }} {{ $selectedInspection->inspectedBy?->last_name }}
                        </p>
                    @else
                        <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">No inspection result recorded yet.</p>
                    @endif
                </div>

                <flux:button variant="ghost" wire:click="cancelSelection">Close</flux:button>
            </div>

            <div class="mt-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Checklist items to inspect</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">This includes the facility's inclusive amenities plus delivered amenity requests assigned to this facility.</p>

                <div class="mt-3 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                        <thead class="bg-zinc-50 dark:bg-zinc-900">
                            <tr>
                                <th class="px-3 py-2 text-left">Item</th>
                                <th class="px-3 py-2 text-left">Source</th>
                                <th class="px-3 py-2 text-left">Expected Qty</th>
                                <th class="px-3 py-2 text-left">Fine setup</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @forelse ($selectedChecklistItems as $item)
                                <tr>
                                    <td class="px-3 py-2 font-medium">{{ $item['amenity_name'] }}</td>
                                    <td class="px-3 py-2">{{ $item['source_label'] }}</td>
                                    <td class="px-3 py-2">{{ $item['expected_quantity'] }}</td>
                                    <td class="px-3 py-2">
                                        @if ($item['fine_count'] > 0)
                                            <span class="text-green-700 dark:text-green-300">{{ $item['fine_count'] }} fine option(s)</span>
                                        @else
                                            <span class="text-amber-700 dark:text-amber-300">No amenity fine configured</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-3 py-4 text-center text-zinc-500">No checklist items found for this facility.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4">
                <flux:textarea label="Inspection remarks" wire:model.live="remarks" rows="3" placeholder="Optional notes, e.g. all items complete, towel missing, stained blanket..." />
                @error('remarks') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="mt-4 flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                <div class="grid gap-3 lg:grid-cols-4 lg:items-end">
                    <div>
                        <flux:select label="Checklist item" wire:model.live="selectedChecklistKey">
                            <option value="">No specific item / situational fine</option>
                            @foreach ($selectedChecklistItems as $item)
                                <option value="{{ $item['key'] }}">{{ $item['amenity_name'] }} - {{ $item['source_label'] }}</option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div>
                        <flux:select label="Fine found" wire:model.live="fineId">
                            <option value="">Select fine</option>
                            @foreach ($fines as $fine)
                                <option value="{{ $fine->fine_id }}">{{ $this->formatFineLabel($fine) }}</option>
                            @endforeach
                        </flux:select>
                        @error('fineId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <flux:input type="number" label="Quantity" min="1" wire:model.live="fineQuantity" />
                        @error('fineQuantity') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <flux:button variant="primary" wire:click="addFine">Record Fine</flux:button>
                </div>

                @if ($selectedChecklistKey !== '' && $fines->isEmpty())
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                        This checklist item has no configured amenity fine. Admin/Manager must create an Amenity Fine for this amenity before maintenance can charge it.
                    </div>
                @endif

                <div class="flex flex-col gap-2 sm:flex-row">
                    <flux:button wire:click="markNoDamage">Mark All Complete / No Damage</flux:button>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Use this only after checking all inclusive and delivered requested amenities.</p>
                </div>
            </div>

            <div class="mt-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Recorded fines for this facility</h2>
                <div class="mt-2 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                        <thead class="bg-zinc-50 dark:bg-zinc-900">
                            <tr>
                                <th class="px-3 py-2 text-left">Fine</th>
                                <th class="px-3 py-2 text-left">Qty</th>
                                <th class="px-3 py-2 text-left">Charge</th>
                                <th class="px-3 py-2 text-left">Reported by</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @forelse ($selectedGuestFines as $guestFine)
                                <tr>
                                    <td class="px-3 py-2">{{ $this->formatFineLabel($guestFine->fine) }}</td>
                                    <td class="px-3 py-2">{{ $guestFine->quantity }}</td>
                                    <td class="px-3 py-2">₱{{ number_format((float) $guestFine->total_charge, 2) }}</td>
                                    <td class="px-3 py-2">{{ $guestFine->reportedBy?->first_name }} {{ $guestFine->reportedBy?->last_name }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-3 py-4 text-center text-zinc-500">No fines recorded for this facility.</td>
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
            <flux:select label="Filter" wire:model.live="statusFilter">
                <option value="checked_in">All checked-in</option>
                <option value="not_inspected">Not inspected</option>
                <option value="inspected">Already inspected</option>
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
                            <td class="px-3 py-2">
                                <flux:button size="sm" wire:click="selectInspection({{ $detail->booking_details_id }})">Inspect</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-8 text-center text-zinc-500">No checked-in facilities found.</td>
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
