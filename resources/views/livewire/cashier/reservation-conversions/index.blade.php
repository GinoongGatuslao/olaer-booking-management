<?php

use App\Models\ModeOfPayment;
use App\Models\Reservation;
use App\Services\ReservationToBookingWorkflowService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Convert Reservation to Booking - Olaer Spring Resort')] class extends Component
{
    use WithPagination;
    public function mount(): void
    {
        // The list starts on today.
        $this->dateFrom = now()->toDateString();
    }


    public function updated(string $property): void
    {
        // Clear the red highlight of a field as soon as the user edits it.
        $this->resetValidation($property);
    }

    /** Error text for a required field; an empty required field only turns red (no text). */
    public function visibleError(string $field): ?string
    {
        $message = $this->getErrorBag()->first($field);

        if ($message === '') {
            return null;
        }

        $value = data_get($this, $field);

        return $value === null || $value === '' || $value === [] ? null : $message;
    }
    public string $search = '';
    public ?int $selectedReservationId = null;
    public string $paymentAmount = '';
    public ?int $modeOfPaymentId = null;
    public string $referenceNumber = '';
    public string $cashPaid = '';
    public ?string $successMessage = null;
    public ?string $errorMessage = null;
    public ?int $convertedBookingId = null;
    public bool $showConversionModal = false;
    public string $dateFrom = '';
    public string $dateTo = '';
    public int $perPage = 10;
    public string $sortField = 'check_in_date';
    public string $sortDirection = 'asc';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
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
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->perPage = 10;
        $this->sortField = 'check_in_date';
        $this->sortDirection = 'asc';

        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, ['r_ref_no', 'guest_name', 'facility_name', 'check_in_date', 'amount_due'], true)) {
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

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    public function reservations()
    {
        $search = trim($this->search);
        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        $query = Reservation::query()
            ->with(['guest', 'details.facility.facilityType', 'details.discount', 'extraGuests', 'payments'])
            ->whereIn('status', ['Active', 'Paid'])
            ->whereDoesntHave('booking')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('r_ref_no', 'like', "%{$search}%")
                        ->orWhereHas('guest', function ($query) use ($search): void {
                            $query->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhereRaw(\App\Support\NameSearch::sql('first_name', 'middle_name', 'last_name'), \App\Support\NameSearch::bindings("%{$search}%"))
                                ->orWhere('contact_no', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->when($this->dateFrom !== '' || $this->dateTo !== '', function ($query): void {
                // From / To filter the check-in date of the reserved facility.
                $query->whereHas('details', function ($details): void {
                    if ($this->dateFrom !== '') {
                        $details->whereDate('check_in_date', '>=', $this->dateFrom);
                    }

                    if ($this->dateTo !== '') {
                        $details->whereDate('check_in_date', '<=', $this->dateTo);
                    }
                });
            });

        $guest = fn (string $column): string => "(select g.{$column} from tbl_guest g where g.guest_id = tbl_reservation.guest_id)";

        if ($this->sortField === 'guest_name') {
            $query->orderByRaw($guest('last_name').' '.$direction)
                ->orderByRaw($guest('first_name').' '.$direction);
        } elseif ($this->sortField === 'facility_name') {
            $query->orderByRaw('(select min(f.facility_name) from tbl_reservation_details d inner join tbl_facility f on f.facility_id = d.facility_id where d.reservation_id = tbl_reservation.reservation_id) '.$direction);
        } elseif ($this->sortField === 'check_in_date') {
            $query->orderByRaw('(select min(d.check_in_date) from tbl_reservation_details d where d.reservation_id = tbl_reservation.reservation_id) '.$direction);
        } elseif ($this->sortField === 'amount_due') {
            $query->orderBy('tbl_reservation.amount_due', $direction);
        } else {
            $query->orderBy('tbl_reservation.r_ref_no', $direction);
        }

        $perPage = in_array($this->perPage, [10, 25, 50, 100], true) ? $this->perPage : 10;

        return $query->orderByDesc('tbl_reservation.reservation_id')->paginate($perPage);
    }

    public function modesOfPayment()
    {
        return ModeOfPayment::query()->orderBy('mode_of_payment')->get();
    }

    public function selectedReservation(): ?Reservation
    {
        if (! $this->selectedReservationId) {
            return null;
        }

        return Reservation::query()
            ->with(['guest', 'details.facility.facilityType', 'details.discount', 'extraGuests', 'payments.modeOfPayment'])
            ->find($this->selectedReservationId);
    }

    public function selectReservation(int $reservationId): void
    {
        $this->resetMessages();
        $this->showConversionModal = false;
        $this->selectedReservationId = null;
        $this->paymentAmount = '';
        $this->modeOfPaymentId = null;
        $this->referenceNumber = '';
        $this->cashPaid = '';

        $reservation = Reservation::query()->find($reservationId);

        if ($reservation === null || ! in_array($reservation->status, ['Active', 'Paid'], true) || $reservation->booking()->exists()) {
            $this->errorMessage = 'This reservation is no longer available for conversion. Refresh the list.';

            return;
        }

        $this->selectedReservationId = (int) $reservation->reservation_id;
        $this->paymentAmount = (string) $reservation->amount_due;
        $this->showConversionModal = true;
    }

    public function updatedShowConversionModal(bool $open): void
    {
        if (! $open) {
            $this->selectedReservationId = null;
            $this->paymentAmount = '';
            $this->modeOfPaymentId = null;
            $this->referenceNumber = '';
            $this->cashPaid = '';
            $this->errorMessage = null;
            $this->resetValidation();
        }
    }

    public function isCashMode(): bool
    {
        return strtolower(trim((string) ModeOfPayment::query()
            ->whereKey((int) $this->modeOfPaymentId)
            ->value('mode_of_payment'))) === 'cash';
    }

    public function updatedModeOfPaymentId(): void
    {
        // Cash received only belongs to a cash payment.
        $this->cashPaid = '';
    }

    public function convert(ReservationToBookingWorkflowService $service): void
    {
        $this->resetMessages();

        $reservation = $this->selectedReservation();

        if (! $reservation) {
            $this->errorMessage = 'Select a reservation first.';
            return;
        }

        $hasAmountDue = bccomp((string) $reservation->amount_due, '0.00', 2) === 1;

        $rules = [
            'selectedReservationId' => ['required', 'integer', 'exists:tbl_reservation,reservation_id'],
            'paymentAmount' => ['required', 'numeric', 'min:0'],
            'referenceNumber' => ['nullable', 'string', 'max:50'],
            'cashPaid' => [$hasAmountDue && $this->isCashMode() ? 'required' : 'nullable', 'numeric', 'gte:paymentAmount'],
        ];

        if ($hasAmountDue) {
            $rules['modeOfPaymentId'] = ['required', 'integer', 'exists:tbl_mode_of_payment,mode_of_payment_id'];
        } else {
            $rules['modeOfPaymentId'] = ['nullable', 'integer', 'exists:tbl_mode_of_payment,mode_of_payment_id'];
        }

        $this->validate($rules, [
            'cashPaid.required' => 'Enter the cash received.',
            'cashPaid.numeric' => 'Cash paid must be a number.',
            'cashPaid.gte' => 'Cash paid must be at least the amount being paid.',
        ]);

        try {
            $booking = $service->convert((int) $this->selectedReservationId, [
                'payment_amount' => $this->paymentAmount,
                'mode_of_payment_id' => $this->modeOfPaymentId,
                'reference_number' => $this->referenceNumber,
                'cash_paid' => $hasAmountDue && $this->isCashMode() ? $this->cashPaid : null,
                'user_id' => Auth::id(),
            ]);

            $this->successMessage = 'Reservation converted to booking. Booking reference: ' . $booking->b_ref_no;
            $this->convertedBookingId = (int) $booking->booking_id;
            $this->showConversionModal = false;
            $this->selectedReservationId = null;
            $this->paymentAmount = '';
            $this->modeOfPaymentId = null;
            $this->referenceNumber = '';
            $this->cashPaid = '';
        } catch (\InvalidArgumentException $exception) {
            $this->errorMessage = $exception->getMessage();
        } catch (\Throwable $exception) {
            report($exception);
            $this->errorMessage = 'The reservation could not be converted. Refresh the list and try again.';
        }
    }

    private function resetMessages(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
        $this->convertedBookingId = null;
    }
};
?>

<div class="space-y-6">
    <x-staff-page-header
        eyebrow="Cashier operations"
        title="Reservation Conversion"
        description="Use this when a guest with an active reservation settles the required payment and wants the facility confirmed as a booking."
    >
    <x-slot:actions>
            <flux:button
                :href="route('cashier.dashboard')"
                wire:navigate
                variant="ghost"
            >
                Back to dashboard
            </flux:button>
        </x-slot:actions>
    </x-staff-page-header>

    <x-toast :message="$successMessage" :seconds="15">
        @if ($convertedBookingId && Route::has('cashier.bookings.show'))
                <flux:button
                    href="{{ route('cashier.bookings.show', $convertedBookingId) }}"
                    wire:navigate
                    size="sm"
                    variant="primary"
                >
                    View Booking
                </flux:button>
            @endif
    </x-toast>

    @if ($errorMessage && ! $showConversionModal)
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            {{ $errorMessage }}
        </div>
    @endif

    <div>
        <section>
            <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="grid gap-3 border-b border-zinc-200 p-5 md:grid-cols-4 dark:border-zinc-800">
                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        label="Search"
                        placeholder="Reference, guest, contact, or email"
                    />

                    <flux:input wire:model.live="dateFrom" type="date" label="From" />

                    <flux:input wire:model.live="dateTo" type="date" label="To" />

                    <div class="flex items-end gap-2">
                        <flux:select wire:model.live="perPage" label="Rows">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </flux:select>

                        <flux:button type="button" wire:click="clearListFilters" variant="ghost">Clear Filters</flux:button>
                    </div>
                </div>

                @php($reservations = $this->reservations())

                <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,dateFrom,dateTo,perPage,sortBy,clearListFilters,gotoPage,previousPage,nextPage">
                    <table class="w-full min-w-[56rem] text-left text-sm">
                        <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-950/60 dark:text-zinc-400">
                            <tr>
                                @foreach (['r_ref_no' => 'Reference', 'guest_name' => 'Guest', 'facility_name' => 'Facility', 'check_in_date' => 'Date'] as $field => $label)
                                    <th class="px-4 py-3">
                                        <button type="button" wire:click="sortBy('{{ $field }}')" class="font-semibold transition hover:text-zinc-900 dark:hover:text-white">
                                            {{ $label }} {{ $this->sortIndicator($field) }}
                                        </button>
                                    </th>
                                @endforeach
                                <th class="px-4 py-3 font-semibold">Status</th>
                                <th class="px-4 py-3 text-right">
                                    <button type="button" wire:click="sortBy('amount_due')" class="font-semibold transition hover:text-zinc-900 dark:hover:text-white">
                                        Amount Due {{ $this->sortIndicator('amount_due') }}
                                    </button>
                                </th>
                                <th class="px-4 py-3 text-right font-semibold">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($reservations as $reservation)
                                @php($detail = $reservation->details->first())
                                <tr wire:key="conversion-{{ $reservation->reservation_id }}" class="{{ $selectedReservationId === $reservation->reservation_id ? 'bg-blue-50 dark:bg-blue-950/30' : '' }} text-brand-text transition-colors hover:bg-brand-surface-muted/70 dark:text-zinc-200 dark:hover:bg-zinc-800/60">
                                    <td class="whitespace-nowrap px-4 py-4 font-medium">{{ $reservation->r_ref_no }}</td>
                                    <td class="px-4 py-4">
                                        <div class="font-medium">{{ $reservation->guest?->first_name }} {{ $reservation->guest?->last_name }}</div>
                                        <div class="text-xs text-zinc-500">{{ $reservation->guest?->contact_no }}</div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="font-medium">{{ $detail?->facility?->facility_name ?? 'No facility' }}</div>
                                        <div class="text-xs text-zinc-500">
                                            {{ $detail?->facility?->facilityType?->facility_type }} · {{ $detail?->rate_type }}
                                            @if ($detail?->discount)
                                                · {{ $detail->discount->discount_name }}
                                            @endif
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4">
                                        <div>{{ optional($detail?->check_in_date)->format('M d, Y') }}</div>
                                        <div class="text-xs text-zinc-500">to {{ optional($detail?->check_out_date)->format('M d, Y') }}</div>
                                    </td>
                                    <td class="px-4 py-4">
                                        @if ($reservation->status === 'Paid')
                                            <flux:badge size="sm" color="green">Fully paid</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="amber">Balance due</flux:badge>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4 text-right font-medium">₱{{ number_format((float) $reservation->amount_due, 2) }}</td>
                                    <td class="px-4 py-4 text-right">
                                        <flux:button size="sm" variant="primary" wire:click="selectReservation({{ $reservation->reservation_id }})">
                                            Convert
                                        </flux:button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-5 py-10 text-center text-zinc-500">No active reservations match the current filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-800">
                    {{ $reservations->links() }}
                </div>
            </div>
        </section>
    </div>

    <flux:modal wire:model="showConversionModal" class="w-full max-h-[90vh] overflow-y-auto md:w-[30rem]">
            <div>
                <h2 class="pe-8 font-semibold">Conversion Payment</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Exact remaining balance is required before conversion.</p>

                @if ($errorMessage)
                    <div class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
                        {{ $errorMessage }}
                    </div>
                @endif

                @php($selected = $this->selectedReservation())

                @if ($selected)
                    @php($selectedDetail = $selected->details->first())
                    <div class="mt-4 rounded-xl bg-zinc-50 p-4 text-sm dark:bg-zinc-950">
                        <div class="font-medium">{{ $selected->r_ref_no }}</div>
                        <div class="mt-1 text-zinc-600 dark:text-zinc-400">
                            {{ $selected->guest?->first_name }} {{ $selected->guest?->last_name }}<br>
                            {{ $selectedDetail?->facility?->facility_name }} · {{ $selectedDetail?->rate_type }}<br>
                            Total: ₱{{ number_format((float) $selected->total_price, 2) }}<br>
                            Balance: <strong>₱{{ number_format((float) $selected->amount_due, 2) }}</strong>
                        </div>
                    </div>

                    <div class="mt-4 space-y-4">
                        <flux:field class="relative">
                            <flux:label>Payment Amount <span class="text-red-500">*</span></flux:label>
                            <flux:input type="number" step="0.01" min="0" wire:model.live="paymentAmount" :invalid="$errors->has('paymentAmount')" />
                            @if ($msg = $this->visibleError('paymentAmount'))
                                <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                            @endif
                        </flux:field>

                        @if ((float) $selected->amount_due > 0)
                            <label class="block">
                                <span class="text-sm font-medium">Mode of Payment</span>
                                <select wire:model.live="modeOfPaymentId" class="mt-1 w-full rounded-lg border-zinc-300 dark:border-zinc-700 dark:bg-zinc-950">
                                    <option value="">Select mode</option>
                                    @foreach ($this->modesOfPayment() as $mode)
                                        <option value="{{ $mode->mode_of_payment_id }}">{{ $mode->mode_of_payment }}</option>
                                    @endforeach
                                </select>
                            </label>
                            @error('modeOfPaymentId') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                            @if($this->modeOfPaymentId == '2')
                            <flux:input label="Reference Number" wire:model.live="referenceNumber" placeholder="Required for GCash" />
                            @endif
                            @error('referenceNumber') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                            @if ($this->isCashMode())
                                <flux:field class="relative">
                                    <flux:label>Cash paid <span class="text-red-500">*</span></flux:label>
                                    <flux:input wire:model.live.debounce.300ms="cashPaid" type="number" min="0" step="0.01" :invalid="$errors->has('cashPaid')" />
                                    @if ($msg = $this->visibleError('cashPaid'))
                                        <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                                    @endif
                                </flux:field>
                                <div class="flex items-center justify-between rounded-lg bg-zinc-50 px-4 py-3 text-sm font-semibold dark:bg-zinc-900">
                                    <span>Change</span>
                                    <span>₱{{ number_format(is_numeric($cashPaid) && is_numeric($paymentAmount) ? max(0, (float) $cashPaid - (float) $paymentAmount) : 0, 2) }}</span>
                                </div>
                            @endif
                        @else
                            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900/60 dark:bg-green-950/40 dark:text-green-200">
                                This reservation is already fully paid. No new payment is required.
                            </div>
                        @endif

                        <flux:button variant="primary" class="w-full" wire:click="convert">
                            Convert to Booking
                        </flux:button>
                    </div>
                @else
                    <div class="mt-4 rounded-xl bg-zinc-50 p-4 text-sm text-zinc-500 dark:bg-zinc-950 dark:text-zinc-400">
                        Select a reservation from the list.
                    </div>
                @endif
            </div>
    </flux:modal>
</div>
