<?php

use App\Models\Booking;
use App\Models\Reservation;
use App\Services\GuestConfirmationLookupService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.public')] #[Title('Find Confirmation - Olaer Spring Resort')] class extends Component
{
    public string $type = 'reservation';
    public string $reference_no = '';
    public string $email = '';

    public bool $searched = false;
    public ?int $reservation_id = null;
    public ?int $booking_id = null;

    public function updatedType(): void
    {
        $this->clearResult();
    }

    public function updatedReferenceNo(): void
    {
        $this->clearResult();
    }

    public function updatedEmail(): void
    {
        $this->clearResult();
    }

    public function search(GuestConfirmationLookupService $lookup): void
    {
        $validated = $this->validate([
            'type' => ['required', 'in:reservation,booking'],
            'reference_no' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:50'],
        ]);

        $this->searched = true;
        $this->reservation_id = null;
        $this->booking_id = null;

        if ($validated['type'] === 'reservation') {
            $reservation = $lookup->reservation($validated['reference_no'], $validated['email']);
            $this->reservation_id = $reservation?->reservation_id;

            return;
        }

        $booking = $lookup->booking($validated['reference_no'], $validated['email']);
        $this->booking_id = $booking?->booking_id;
    }

    public function clearSearch(): void
    {
        $this->type = 'reservation';
        $this->reference_no = '';
        $this->email = '';
        $this->clearResult();
    }

    public function with(): array
    {
        return [
            'reservation' => $this->reservation_id
                ? Reservation::query()
                    ->with([
                        'guest.address',
                        'details.facility.facilityType',
                        'details.discount',
                        'extraGuests',
                        'payments.modeOfPayment',
                    ])
                    ->find($this->reservation_id)
                : null,
            'booking' => $this->booking_id
                ? Booking::query()
                    ->with([
                        'guest.address',
                        'details.facility.facilityType',
                        'details.discount',
                        'extraGuests',
                        'payments.modeOfPayment',
                        'amenityRequests.details.amenity.amenityName',
                        'guestFines.fine.damageType',
                        'guestFines.fine.amenity.amenityName',
                    ])
                    ->find($this->booking_id)
                : null,
        ];
    }

    private function clearResult(): void
    {
        $this->searched = false;
        $this->reservation_id = null;
        $this->booking_id = null;
        $this->resetValidation();
    }
};
?>

<div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
    <div class="mb-8 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Guest confirmation</p>
            <h1 class="text-3xl font-bold tracking-tight text-zinc-950 dark:text-white">Find your confirmation slip</h1>
            <p class="mt-2 max-w-2xl text-sm text-zinc-600 dark:text-zinc-300">
                Enter the reference number and email used during reservation or booking.
            </p>
        </div>

        <div class="flex gap-2 print:hidden">
            <flux:button variant="ghost" href="{{ route('guest.reservations.create') }}">Reserve</flux:button>
            @if (Route::has('guest.bookings.create'))
                <flux:button variant="ghost" href="{{ route('guest.bookings.create') }}">Book</flux:button>
            @endif
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-[420px_1fr]">
        <section class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 print:hidden">
            <form wire:submit="search" class="space-y-5">
                <flux:field>
                    <flux:label>Confirmation type</flux:label>
                    <flux:select wire:model.live="type">
                        <option value="reservation">Reservation</option>
                        <option value="booking">Booking</option>
                    </flux:select>
                    <flux:error name="type" />
                </flux:field>

                <flux:field>
                    <flux:label>Reference number</flux:label>
                    <flux:input wire:model.live.debounce.500ms="reference_no" placeholder="Example: RES-2026-000001" />
                    <flux:error name="reference_no" />
                </flux:field>

                <flux:field>
                    <flux:label>Email address</flux:label>
                    <flux:input type="email" wire:model.live.debounce.500ms="email" placeholder="guest@example.com" />
                    <flux:error name="email" />
                </flux:field>

                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary">Find confirmation</flux:button>
                    <flux:button type="button" variant="ghost" wire:click="clearSearch">Clear</flux:button>
                </div>
            </form>
        </section>

        <section>
            @if ($reservation)
                <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900" id="confirmation-slip">
                    <div class="mb-6 flex flex-col gap-3 border-b border-zinc-200 pb-5 dark:border-zinc-800 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Reservation Confirmation Slip</p>
                            <h2 class="mt-1 text-2xl font-bold text-zinc-950 dark:text-white">{{ $reservation->r_ref_no }}</h2>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">Status: <span class="font-medium">{{ $reservation->status }}</span></p>
                        </div>
                        <flux:button type="button" variant="primary" onclick="window.print()" class="print:hidden">Print</flux:button>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <p class="text-xs font-semibold uppercase text-zinc-500">Guest</p>
                            <p class="mt-1 font-medium text-zinc-950 dark:text-white">{{ $reservation->guest?->full_name }}</p>
                            <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $reservation->guest?->email }}</p>
                            <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $reservation->guest?->contact_no }}</p>
                        </div>

                        <div>
                            <p class="text-xs font-semibold uppercase text-zinc-500">Reservation</p>
                            <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">Reservation date: {{ optional($reservation->reservation_date)->format('M d, Y') }}</p>
                            <p class="text-sm text-zinc-700 dark:text-zinc-300">Total: ₱{{ number_format((float) $reservation->total_price, 2) }}</p>
                            <p class="text-sm text-zinc-700 dark:text-zinc-300">Amount due: ₱{{ number_format((float) $reservation->amount_due, 2) }}</p>
                        </div>
                    </div>

                    <div class="mt-6 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-800">
                        <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                            <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold">Facility</th>
                                    <th class="px-4 py-3 text-left font-semibold">Rate</th>
                                    <th class="px-4 py-3 text-left font-semibold">Check-in</th>
                                    <th class="px-4 py-3 text-left font-semibold">Check-out</th>
                                    <th class="px-4 py-3 text-left font-semibold">Discount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @foreach ($reservation->details as $detail)
                                    <tr>
                                        <td class="px-4 py-3">
                                            <div class="font-medium">{{ $detail->facility?->facility_name }}</div>
                                            <div class="text-xs text-zinc-500">{{ $detail->facility?->facilityType?->facility_type }}</div>
                                        </td>
                                        <td class="px-4 py-3">{{ $detail->rate_type }}</td>
                                        <td class="px-4 py-3">{{ optional($detail->check_in_date)->format('M d, Y') }}</td>
                                        <td class="px-4 py-3">{{ optional($detail->check_out_date)->format('M d, Y') }}</td>
                                        <td class="px-4 py-3">{{ $detail->discount?->discount_name ?? 'None' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($reservation->extraGuests->isNotEmpty())
                        <div class="mt-6">
                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">Extra Guests</p>
                            <ul class="mt-2 grid gap-1 text-sm text-zinc-700 dark:text-zinc-300 sm:grid-cols-2">
                                @foreach ($reservation->extraGuests as $guest)
                                    <li>• {{ $guest->full_name }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @elseif ($booking)
                <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900" id="confirmation-slip">
                    <div class="mb-6 flex flex-col gap-3 border-b border-zinc-200 pb-5 dark:border-zinc-800 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Booking Confirmation Slip</p>
                            <h2 class="mt-1 text-2xl font-bold text-zinc-950 dark:text-white">{{ $booking->b_ref_no }}</h2>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">Status: <span class="font-medium">{{ $booking->status }}</span></p>
                        </div>
                        <flux:button type="button" variant="primary" onclick="window.print()" class="print:hidden">Print</flux:button>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <p class="text-xs font-semibold uppercase text-zinc-500">Guest</p>
                            <p class="mt-1 font-medium text-zinc-950 dark:text-white">{{ $booking->guest?->full_name }}</p>
                            <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $booking->guest?->email }}</p>
                            <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $booking->guest?->contact_no }}</p>
                        </div>

                        <div>
                            <p class="text-xs font-semibold uppercase text-zinc-500">Booking</p>
                            <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">Booking date: {{ optional($booking->booking_date)->format('M d, Y') }}</p>
                            <p class="text-sm text-zinc-700 dark:text-zinc-300">Total: ₱{{ number_format((float) $booking->total_price, 2) }}</p>
                            <p class="text-sm text-zinc-700 dark:text-zinc-300">Amount due: ₱{{ number_format((float) $booking->amount_due, 2) }}</p>
                        </div>
                    </div>

                    <div class="mt-6 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-800">
                        <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                            <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold">Facility</th>
                                    <th class="px-4 py-3 text-left font-semibold">Rate</th>
                                    <th class="px-4 py-3 text-left font-semibold">Check-in</th>
                                    <th class="px-4 py-3 text-left font-semibold">Check-out</th>
                                    <th class="px-4 py-3 text-left font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @foreach ($booking->details as $detail)
                                    <tr>
                                        <td class="px-4 py-3">
                                            <div class="font-medium">{{ $detail->facility?->facility_name }}</div>
                                            <div class="text-xs text-zinc-500">{{ $detail->facility?->facilityType?->facility_type }}</div>
                                        </td>
                                        <td class="px-4 py-3">{{ $detail->rate_type }}</td>
                                        <td class="px-4 py-3">{{ optional($detail->check_in_date)->format('M d, Y') }}</td>
                                        <td class="px-4 py-3">{{ optional($detail->check_out_date)->format('M d, Y') }}</td>
                                        <td class="px-4 py-3">{{ $detail->status }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-6 grid gap-4 md:grid-cols-2">
                        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">Payments</p>
                            <div class="mt-2 space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
                                @forelse ($booking->payments as $payment)
                                    <div class="flex justify-between gap-3">
                                        <span>{{ $payment->modeOfPayment?->mode_of_payment }} — {{ $payment->payment_status }}</span>
                                        <span>₱{{ number_format((float) $payment->amount_paid, 2) }}</span>
                                    </div>
                                @empty
                                    <p class="text-zinc-500">No payment record yet.</p>
                                @endforelse
                            </div>
                        </div>

                        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">Extra Charges</p>
                            <div class="mt-2 space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
                                @forelse ($booking->amenityRequests as $request)
                                    <div>Request {{ $request->amenity_request_ref_no ?? ('#' . $request->amenity_request_id) }} — {{ $request->status }}</div>
                                @empty
                                    <p class="text-zinc-500">No amenity requests.</p>
                                @endforelse

                                @forelse ($booking->guestFines as $fine)
                                    <div>Fine: {{ $fine->fine?->fine_name ?? 'Fine' }} — ₱{{ number_format((float) $fine->total_charge, 2) }}</div>
                                @empty
                                    <p class="text-zinc-500">No fines.</p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            @elseif ($searched)
                <div class="rounded-xl border border-red-200 bg-red-50 p-6 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300">
                    No {{ $type }} confirmation was found for that reference number and email.
                </div>
            @else
                <div class="rounded-xl border border-dashed border-zinc-300 bg-white p-10 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
                    Search for a confirmation slip to view it here.
                </div>
            @endif
        </section>
    </div>
</div>
