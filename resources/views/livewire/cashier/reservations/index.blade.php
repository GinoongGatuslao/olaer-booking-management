<?php

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationDetail;
use App\Models\FacilityProduct;
use App\Services\DecimalMoneyService;
use App\Services\StaffReservationCancellationService;
use App\Services\ReservationFacilityOperationService;
use App\Services\RoomOccupantService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Reservation Management - Olaer Spring Resort')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'Active')]
    public string $statusFilter = 'Active';

    #[Url(as: 'sort', except: 'reservation_id')]
    public string $sortField = 'reservation_id';

    #[Url(as: 'direction', except: 'desc')]
    public string $sortDirection = 'desc';

    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    public ?int $cancelReservationId = null;
    public string $cancellationReason = '';

    public ?int $detailActionId = null;
    public ?string $detailAction = null;
    public string $detailCheckInDate = '';
    public string $detailCheckOutDate = '';
    public string $detailFacilityProductId = '';
    public string $detailCancellationReason = '';
    public array $detailOccupants = [];

    public function with(): array
    {
        return [
            'reservations' => $this->reservations(),
        ];
    }

    public function reservations(): LengthAwarePaginator
    {
        $query = Reservation::query()
            ->with([
                'guest.address',
                'details.facility.facilityType',
                'details.discount',
                'details.facility.facilityProduct',
                'details.roomOccupants',
                'extraGuests',
                'payments.modeOfPayment',
            ]);

        if ($this->statusFilter !== 'All') {
            $query->where('status', $this->statusFilter);
        }

        if (trim($this->search) !== '') {
            $search = '%' . trim($this->search) . '%';

            $query->where(function ($query) use ($search): void {
                $query->where('r_ref_no', 'like', $search)
                    ->orWhereHas('guest', function ($guestQuery) use ($search): void {
                        $guestQuery->where('first_name', 'like', $search)
                            ->orWhere('middle_name', 'like', $search)
                            ->orWhere('last_name', 'like', $search)
                            ->orWhere('contact_no', 'like', $search)
                            ->orWhere('email', 'like', $search);
                    });
            });
        }

        $allowedSorts = [
            'reservation_id',
            'r_ref_no',
            'reservation_date',
            'total_price',
            'amount_due',
            'status',
            'created_at',
        ];

        $sortField = in_array($this->sortField, $allowedSorts, true) ? $this->sortField : 'reservation_id';
        $sortDirection = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        $perPage = in_array($this->perPage, [10, 25, 50, 100], true)
            ? $this->perPage
            : 10;

        return $query
            ->orderBy($sortField, $sortDirection)
            ->paginate($perPage);
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

    public function clearListFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'Active';
        $this->sortField = 'reservation_id';
        $this->sortDirection = 'desc';
        $this->perPage = 10;

        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        $allowedSorts = [
            'reservation_id',
            'r_ref_no',
            'reservation_date',
            'total_price',
            'amount_due',
            'status',
            'created_at',
        ];

        if (! in_array($field, $allowedSorts, true)) {
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

    public function beginCancellation(int $reservationId): void
    {
        $reservation = Reservation::query()->findOrFail($reservationId);

        if ($reservation->status !== 'Active') {
            session()->flash('error', 'Only active reservations can be cancelled.');
            return;
        }

        $this->cancelReservationId = $reservation->reservation_id;
        $this->cancellationReason = '';
        $this->resetValidation();
    }

    public function cancelReservation(
        StaffReservationCancellationService $cancellationService,
    ): void
    {
        $validated = $this->validate([
            'cancelReservationId' => ['required', 'exists:tbl_reservation,reservation_id'],
            'cancellationReason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $cancellationService->cancel(
                (int) $validated['cancelReservationId'],
                $validated['cancellationReason'],
                (int) Auth::id(),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addError(
                'cancellationReason',
                $exception->getMessage(),
            );

            return;
        }

        $this->cancelReservationId = null;
        $this->cancellationReason = '';
        session()->flash('success', 'Reservation cancelled successfully.');
    }

    public function openDetailAction(int $reservationDetailId, string $action): void
    {
        if (! in_array($action, ['reschedule', 'transfer', 'cancel', 'occupants'], true)) {
            return;
        }

        $detail = ReservationDetail::query()
            ->with(['reservation', 'facility.facilityProduct', 'roomOccupants'])
            ->findOrFail($reservationDetailId);

        if ($detail->reservation?->status !== 'Active'
            || in_array((string) $detail->status, ['Cancelled', 'Converted', 'No-show'], true)) {
            session()->flash('error', 'This reservation facility is no longer editable.');

            return;
        }

        $this->detailActionId = $reservationDetailId;
        $this->detailAction = $action;
        $this->detailCheckInDate = $detail->check_in_date?->toDateString() ?? '';
        $this->detailCheckOutDate = $detail->check_out_date?->toDateString() ?? '';
        $this->detailFacilityProductId = (string) ($detail->facility_product_id ?? '');
        $this->detailCancellationReason = '';
        $this->detailOccupants = $detail->roomOccupants
            ->map(fn ($occupant): array => [
                'first_name' => $occupant->first_name,
                'middle_name' => $occupant->middle_name ?? '',
                'last_name' => $occupant->last_name,
            ])
            ->all();
    }

    public function closeDetailAction(): void
    {
        $this->detailActionId = null;
        $this->detailAction = null;
        $this->detailCheckInDate = '';
        $this->detailCheckOutDate = '';
        $this->detailFacilityProductId = '';
        $this->detailCancellationReason = '';
        $this->detailOccupants = [];
    }

    public function detailTransferProducts(): Collection
    {
        if ($this->detailActionId === null) {
            return collect();
        }

        $detail = ReservationDetail::query()
            ->with('facility')
            ->find($this->detailActionId);

        if ($detail?->facility === null) {
            return collect();
        }

        return FacilityProduct::query()
            ->where('facility_type_id', $detail->facility->facility_type_id)
            ->where('is_active', true)
            ->orderBy('display_name')
            ->get();
    }

    public function saveDetailAction(
        ReservationFacilityOperationService $operations,
        RoomOccupantService $occupants,
    ): void {
        if ($this->detailActionId === null || $this->detailAction === null) {
            return;
        }

        try {
            match ($this->detailAction) {
                'reschedule' => $operations->rescheduleDetail(
                    $this->detailActionId,
                    $this->detailCheckInDate,
                    $this->detailCheckOutDate,
                    (int) Auth::id(),
                ),
                'transfer' => $operations->transferDetailToProduct(
                    $this->detailActionId,
                    (int) $this->detailFacilityProductId,
                    (int) Auth::id(),
                ),
                'cancel' => $operations->cancelDetail(
                    $this->detailActionId,
                    $this->detailCancellationReason,
                    (int) Auth::id(),
                ),
                'occupants' => $occupants->replaceReservationOccupants(
                    $this->detailActionId,
                    $this->detailOccupants,
                    (int) Auth::id(),
                ),
                default => null,
            };

            session()->flash('success', match ($this->detailAction) {
                'reschedule' => 'Facility schedule updated without changing the other reservation facilities.',
                'transfer' => 'Facility upgraded/transferred and automatically reassigned.',
                'cancel' => 'Facility cancelled. Any paid value was retained as same-transaction credit.',
                'occupants' => 'Room occupant names updated and audited.',
                default => 'Reservation facility updated.',
            });
            $this->closeDetailAction();
        } catch (\Throwable $exception) {
            $this->addError('detailAction', $exception->getMessage());
        }
    }

    public function getSortIcon(string $field): string
    {
        if ($this->sortField !== $field) {
            return '↕';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    public function fullName(Guest $guest): string
    {
        return trim(implode(' ', array_filter([
            $guest->first_name,
            $guest->middle_name,
            $guest->last_name,
        ])));
    }

    public function amountPaid(Reservation $reservation): string
    {
        return app(DecimalMoneyService::class)->add(...$reservation->payments
            ->where('payment_status', 'Verified')
            ->pluck('amount_paid')
            ->map(fn (mixed $amount): string => (string) $amount)
            ->all());
    }

};

?>

<div class="space-y-6">
    <x-staff-page-header
        eyebrow="Cashier operations"
        title="Reservation Management"
        description="Create temporary facility holds, review guest schedules, and manage active reservations before booking conversion."
    >
        <x-slot:actions>
            <flux:button href="{{ route('cashier.reservations.create') }}" variant="primary" wire:navigate>
                New reservation
            </flux:button>
            <flux:button
                :href="route('cashier.dashboard')"
                wire:navigate
                variant="ghost"
            >
                Back to dashboard
            </flux:button>
        </x-slot:actions>
    </x-staff-page-header>

    @if (session('success'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900/60 dark:bg-green-950/40 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            {{ session('error') }}
        </div>
    @endif

    @if ($detailActionId && $detailAction)
        <flux:card>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <flux:heading size="lg">
                        @switch($detailAction)
                            @case('reschedule') Reschedule facility @break
                            @case('transfer') Upgrade / transfer facility @break
                            @case('cancel') Cancel facility @break
                            @case('occupants') Edit room occupants @break
                        @endswitch
                    </flux:heading>
                    <flux:text class="mt-1">This action changes only the selected facility detail, not the other facilities under the reservation.</flux:text>
                </div>
                <flux:button type="button" variant="ghost" wire:click="closeDetailAction">Close</flux:button>
            </div>

            @error('detailAction')
                <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200">{{ $message }}</div>
            @enderror

            <form wire:submit="saveDetailAction" class="mt-5 space-y-4">
                @if ($detailAction === 'reschedule')
                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="detailCheckInDate" type="date" label="New check-in/use date *" />
                        <flux:input wire:model="detailCheckOutDate" type="date" label="New check-out/end date *" />
                    </div>
                @elseif ($detailAction === 'transfer')
                    <flux:select wire:model="detailFacilityProductId" label="Destination facility product *">
                        <option value="">Choose an upgrade product</option>
                        @foreach ($this->detailTransferProducts() as $product)
                            <option value="{{ $product->facility_product_id }}">{{ $product->display_name }}</option>
                        @endforeach
                    </flux:select>
                    <p class="text-xs text-zinc-500">The exact physical unit is assigned automatically. Cheaper/downgrade transfers are rejected by the service.</p>
                @elseif ($detailAction === 'cancel')
                    <flux:textarea wire:model="detailCancellationReason" label="Cancellation reason *" rows="3" />
                    <p class="text-xs text-zinc-500">Paid value remains as same-transaction credit and is not refunded or moved to another booking.</p>
                @elseif ($detailAction === 'occupants')
                    <div class="space-y-3">
                        @forelse ($detailOccupants as $index => $occupant)
                            <div class="grid gap-3 md:grid-cols-3">
                                <flux:input wire:model="detailOccupants.{{ $index }}.first_name" label="Occupant {{ $index + 1 }} first name *" />
                                <flux:input wire:model="detailOccupants.{{ $index }}.middle_name" label="Middle name" />
                                <flux:input wire:model="detailOccupants.{{ $index }}.last_name" label="Last name *" />
                            </div>
                        @empty
                            <p class="text-sm text-zinc-500">This facility has no room occupant list.</p>
                        @endforelse
                    </div>
                @endif

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="closeDetailAction">Cancel</flux:button>
                    <flux:button type="submit" variant="{{ $detailAction === 'cancel' ? 'danger' : 'primary' }}">
                        Save
                    </flux:button>
                </div>
            </form>
        </flux:card>
    @endif

    @if ($cancelReservationId)
        <section class="rounded-2xl border border-red-200 bg-red-50 p-5 shadow-sm dark:border-red-900/60 dark:bg-red-950/30">
            <h2 class="font-semibold">Cancel reservation #{{ $cancelReservationId }}</h2>
            <form wire:submit="cancelReservation" class="mt-4 space-y-4">
                <flux:input wire:model="cancellationReason" label="Cancellation reason" />
                @error('cancellationReason') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <div class="flex gap-3">
                    <flux:button type="submit" variant="danger">Confirm cancellation</flux:button>
                    <flux:button type="button" variant="ghost" wire:click="$set('cancelReservationId', null)">Close</flux:button>
                </div>
            </form>
        </section>
    @endif

    <x-staff-table-shell
        :first-item="$reservations->firstItem()"
        :last-item="$reservations->lastItem()"
        :total="$reservations->total()"
        record-label="reservations"
        loading-target="search,statusFilter,perPage,sortBy,clearListFilters"
    >
        <x-slot:filters>
            <x-staff-filter-panel
                title="Reservation registry"
                description="Search by reference, guest name, contact number, or email, then narrow the registry by lifecycle status."
                :count="$reservations->total()"
                count-label="reservations"
            >
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        label="Search"
                        placeholder="Reference, guest, contact, or email"
                        clearable
                    />

                    <flux:select wire:model.live="statusFilter" label="Status">
                        <option value="Active">Active</option>
                        <option value="Cancelled">Cancelled</option>
                        <option value="Converted">Converted</option>
                        <option value="No-show">No-show</option>
                        <option value="All">All</option>
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
                        Reset registry view
                    </flux:button>
                </x-slot:actions>
            </x-staff-filter-panel>
        </x-slot:filters>

        <table class="w-full min-w-[76rem] text-left text-sm">
            <thead class="border-b border-brand-border bg-brand-surface-muted text-xs uppercase tracking-wide text-brand-text-muted dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3">
                        <button
                            type="button"
                            wire:click="sortBy('r_ref_no')"
                            class="font-semibold transition hover:text-brand-primary dark:hover:text-white"
                        >
                            Ref {{ $this->getSortIcon('r_ref_no') }}
                        </button>
                    </th>
                    <th class="px-4 py-3 font-semibold">Guest</th>
                    <th class="px-4 py-3 font-semibold">Facility</th>
                    <th class="px-4 py-3 font-semibold">Schedule</th>
                    <th class="px-4 py-3">
                        <button
                            type="button"
                            wire:click="sortBy('total_price')"
                            class="font-semibold transition hover:text-brand-primary dark:hover:text-white"
                        >
                            Total {{ $this->getSortIcon('total_price') }}
                        </button>
                    </th>
                    <th class="px-4 py-3 font-semibold">Paid</th>
                    <th class="px-4 py-3">
                        <button
                            type="button"
                            wire:click="sortBy('amount_due')"
                            class="font-semibold transition hover:text-brand-primary dark:hover:text-white"
                        >
                            Due {{ $this->getSortIcon('amount_due') }}
                        </button>
                    </th>
                    <th class="px-4 py-3">
                        <button
                            type="button"
                            wire:click="sortBy('status')"
                            class="font-semibold transition hover:text-brand-primary dark:hover:text-white"
                        >
                            Status {{ $this->getSortIcon('status') }}
                        </button>
                    </th>
                    <th class="px-4 py-3 text-right font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-border dark:divide-zinc-800">
                @forelse ($reservations as $reservation)
                    <tr
                        wire:key="reservation-row-{{ $reservation->reservation_id }}"
                        class="transition hover:bg-brand-surface-muted/70 dark:hover:bg-zinc-800/50"
                    >
                        <td class="px-4 py-3 font-medium text-brand-text dark:text-white">{{ $reservation->r_ref_no }}</td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-brand-text dark:text-white">{{ $this->fullName($reservation->guest) }}</div>
                            <div class="text-xs text-brand-text-muted dark:text-zinc-400">{{ $reservation->guest->contact_no }} {{ $reservation->guest->email ? '• '.$reservation->guest->email : '' }}</div>
                        </td>
                        <td class="px-4 py-3" colspan="2">
                            <div class="space-y-3">
                                @forelse ($reservation->details as $detail)
                                    <div @class([
                                        'rounded-lg border p-3',
                                        'border-red-200 bg-red-50/60 dark:border-red-900 dark:bg-red-950/20' => $detail->status === 'Cancelled',
                                        'border-zinc-200 dark:border-zinc-700' => $detail->status !== 'Cancelled',
                                    ])>
                                        <div class="flex flex-col gap-2 xl:flex-row xl:items-start xl:justify-between">
                                            <div>
                                                <div class="font-medium text-brand-text dark:text-white">
                                                    {{ $detail->facility?->facility_number }} — {{ $detail->facility?->facility_name ?? 'Unassigned facility' }}
                                                </div>
                                                <div class="text-xs text-brand-text-muted dark:text-zinc-400">
                                                    {{ $detail->facility?->facilityType?->facility_type }}
                                                    · {{ $detail->facility?->facilityProduct?->display_name }}
                                                    · {{ $detail->rate_type }}
                                                    · {{ $detail->check_in_date?->format('M d, Y') }} → {{ $detail->check_out_date?->format('M d, Y') }}
                                                    · {{ $detail->status ?? 'Active' }}
                                                </div>
                                            </div>
                                            @if ($reservation->status === 'Active' && ! in_array((string) $detail->status, ['Cancelled', 'Converted', 'No-show'], true))
                                                <div class="flex flex-wrap gap-1">
                                                    <flux:button size="sm" type="button" variant="ghost" wire:click="openDetailAction({{ $detail->reservation_details_id }}, 'reschedule')">Reschedule</flux:button>
                                                    <flux:button size="sm" type="button" variant="ghost" wire:click="openDetailAction({{ $detail->reservation_details_id }}, 'transfer')">Transfer</flux:button>
                                                    @if ($detail->roomOccupants->isNotEmpty())
                                                        <flux:button size="sm" type="button" variant="ghost" wire:click="openDetailAction({{ $detail->reservation_details_id }}, 'occupants')">Occupants</flux:button>
                                                    @endif
                                                    <flux:button size="sm" type="button" variant="danger" wire:click="openDetailAction({{ $detail->reservation_details_id }}, 'cancel')">Cancel Facility</flux:button>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <span class="text-brand-text-muted dark:text-zinc-400">No facility details</span>
                                @endforelse
                            </div>
                        </td>
                        <td class="px-4 py-3">₱{{ number_format((float) $reservation->total_price, 2) }}</td>
                        <td class="px-4 py-3">₱{{ number_format($this->amountPaid($reservation), 2) }}</td>
                        <td class="px-4 py-3">₱{{ number_format((float) $reservation->amount_due, 2) }}</td>
                        <td class="px-4 py-3">
                            <x-status-badge :status="(string) $reservation->status" />
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if ($reservation->status === 'Active')
                                <flux:button size="sm" variant="danger" type="button" wire:click="beginCancellation({{ $reservation->reservation_id }})">
                                    Cancel Entire Reservation
                                </flux:button>
                            @else
                                <span class="text-xs text-brand-text-muted dark:text-zinc-400">No parent action</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-8">
                            <x-dashboard-empty-state
                                title="No reservations found"
                                description="Try another search term or status, or create a new reservation when the guest is ready."
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <x-slot:pagination>
            {{ $reservations->links() }}
        </x-slot:pagination>
    </x-staff-table-shell>
</div>
