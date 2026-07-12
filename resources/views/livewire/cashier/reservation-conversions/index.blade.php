<?php

use App\Models\ModeOfPayment;
use App\Models\Reservation;
use App\Services\ReservationToBookingWorkflowService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Convert Reservation to Booking - Olaer Spring Resort')] class extends Component
{
    public string $search = '';
    public ?int $selectedReservationId = null;
    public string $paymentAmount = '';
    public ?int $modeOfPaymentId = null;
    public string $referenceNumber = '';
    public ?string $successMessage = null;
    public ?string $errorMessage = null;
    public ?int $convertedBookingId = null;

    public function reservations()
    {
        $search = trim($this->search);

        return Reservation::query()
            ->with(['guest', 'details.facility.facilityType', 'details.discount', 'extraGuests', 'payments'])
            ->whereIn('status', ['Active', 'Paid'])
            ->whereDoesntHave('booking')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('r_ref_no', 'like', "%{$search}%")
                        ->orWhereHas('guest', function ($query) use ($search): void {
                            $query->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('contact_no', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('reservation_id')
            ->limit(25)
            ->get();
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

        $reservation = Reservation::query()->findOrFail($reservationId);

        $this->selectedReservationId = (int) $reservation->reservation_id;
        $this->paymentAmount = number_format((float) $reservation->amount_due, 2, '.', '');
        $this->modeOfPaymentId = null;
        $this->referenceNumber = '';
    }

    public function convert(ReservationToBookingWorkflowService $service): void
    {
        $this->resetMessages();

        $reservation = $this->selectedReservation();

        if (! $reservation) {
            $this->errorMessage = 'Select a reservation first.';
            return;
        }

        $amountDue = round((float) $reservation->amount_due, 2);

        $rules = [
            'selectedReservationId' => ['required', 'integer', 'exists:tbl_reservation,reservation_id'],
            'paymentAmount' => ['required', 'numeric', 'min:0'],
            'referenceNumber' => ['nullable', 'string', 'max:50'],
        ];

        if ($amountDue > 0) {
            $rules['modeOfPaymentId'] = ['required', 'integer', 'exists:tbl_mode_of_payment,mode_of_payment_id'];
        } else {
            $rules['modeOfPaymentId'] = ['nullable', 'integer', 'exists:tbl_mode_of_payment,mode_of_payment_id'];
        }

        $this->validate($rules);

        try {
            $booking = $service->convert((int) $this->selectedReservationId, [
                'payment_amount' => (float) $this->paymentAmount,
                'mode_of_payment_id' => $this->modeOfPaymentId,
                'reference_number' => $this->referenceNumber,
                'user_id' => Auth::id(),
            ]);

            $this->successMessage = 'Reservation converted to booking. Booking reference: ' . $booking->b_ref_no;
            $this->convertedBookingId = (int) $booking->booking_id;
            $this->selectedReservationId = null;
            $this->paymentAmount = '';
            $this->modeOfPaymentId = null;
            $this->referenceNumber = '';
        } catch (Throwable $exception) {
            $this->errorMessage = $exception->getMessage();
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
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="text-sm font-medium text-zinc-500">Cashier</p>
            <h1 class="text-2xl font-bold tracking-tight">Convert Reservation to Booking</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Use this when a guest with an active reservation settles the required payment and wants the facility confirmed as a booking.
            </p>
        </div>

        <a href="{{ route('cashier.dashboard') }}" class="text-sm font-medium text-zinc-600 hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-white">
            Back to dashboard
        </a>
    </div>

    @if ($successMessage)
        <div class="flex flex-col gap-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900/60 dark:bg-green-950/40 dark:text-green-200 sm:flex-row sm:items-center sm:justify-between">
            <span>{{ $successMessage }}</span>

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
        </div>
    @endif

    @if ($errorMessage)
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            {{ $errorMessage }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="xl:col-span-2">
            <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 p-5 dark:border-zinc-800">
                    <h2 class="font-semibold">Active Reservations</h2>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Search by reference number, guest name, contact number, or email.</p>
                    <div class="mt-4 max-w-md">
                        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search reservations..." />
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                        <thead class="bg-zinc-50 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:bg-zinc-950 dark:text-zinc-400">
                            <tr>
                                <th class="px-5 py-3">Reference</th>
                                <th class="px-5 py-3">Guest</th>
                                <th class="px-5 py-3">Facility</th>
                                <th class="px-5 py-3">Date</th>
                                <th class="px-5 py-3 text-right">Amount Due</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($this->reservations() as $reservation)
                                @php($detail = $reservation->details->first())
                                <tr class="{{ $selectedReservationId === $reservation->reservation_id ? 'bg-blue-50 dark:bg-blue-950/30' : '' }}">
                                    <td class="px-5 py-4 font-medium">{{ $reservation->r_ref_no }}</td>
                                    <td class="px-5 py-4">
                                        {{ $reservation->guest?->first_name }} {{ $reservation->guest?->last_name }}
                                        <div class="text-xs text-zinc-500">{{ $reservation->guest?->contact_no }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        {{ $detail?->facility?->facility_name ?? 'No facility' }}
                                        <div class="text-xs text-zinc-500">
                                            {{ $detail?->facility?->facilityType?->facility_type }} · {{ $detail?->rate_type }}
                                            @if ($detail?->discount)
                                                · {{ $detail->discount->discount_name }}
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        {{ optional($detail?->check_in_date)->format('M d, Y') }}
                                        <div class="text-xs text-zinc-500">to {{ optional($detail?->check_out_date)->format('M d, Y') }}</div>
                                    </td>
                                    <td class="px-5 py-4 text-right font-medium">₱{{ number_format((float) $reservation->amount_due, 2) }}</td>
                                    <td class="px-5 py-4 text-right">
                                        <flux:button size="sm" wire:click="selectReservation({{ $reservation->reservation_id }})">
                                            Select
                                        </flux:button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-8 text-center text-zinc-500">No active reservations found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <aside class="space-y-6">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <h2 class="font-semibold">Conversion Payment</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Exact remaining balance is required before conversion.</p>

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
                        <flux:input type="number" step="0.01" min="0" label="Payment Amount" wire:model.live="paymentAmount" />
                        @error('paymentAmount') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

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

                            <flux:input label="Reference Number" wire:model.live="referenceNumber" placeholder="Required for GCash" />
                            @error('referenceNumber') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
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
        </aside>
    </div>
</div>
