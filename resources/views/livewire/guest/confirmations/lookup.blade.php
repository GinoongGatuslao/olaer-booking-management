<?php

use App\Models\Booking;
use App\Models\Reservation;
use App\Services\GuestConfirmationLookupService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.public')] #[Title('Find Confirmation - Olaer Spring Resort')] class extends Component
{
    public string $type = 'reservation';
    public string $reference_no = '';
    public string $email = '';

    public bool $searched = false;
    #[Locked]
    public ?int $reservation_id = null;
    #[Locked]
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

<section class="relative overflow-hidden bg-public-forest-deep py-12 text-white print:bg-white print:py-0 print:text-public-ink sm:py-16">
    <div class="absolute inset-0 opacity-20 print:hidden">
        <img
            src="{{ asset('images/olaer/aerial-pools.webp') }}"
            alt=""
            class="h-full w-full object-cover"
        >
    </div>
    <div class="absolute inset-0 bg-linear-to-b from-public-forest-deep/50 via-public-forest-deep/90 to-public-forest-deep print:hidden"></div>

    <div class="relative mx-auto max-w-[90rem] px-4 sm:px-6 lg:px-8">
        <header class="mx-auto mb-9 max-w-3xl text-center print:hidden">
            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-public-sand">Guest self-service</p>
            <h1 class="mt-4 font-public-display text-4xl leading-tight sm:text-5xl">Find your Olaer confirmation</h1>
            <p class="mx-auto mt-4 max-w-2xl text-sm leading-7 text-white/70 sm:text-base">
                Use the reference number and email from your reservation or booking. Your complete confirmation will be ready to view or print.
            </p>
        </header>

        <div class="grid items-start gap-6 lg:grid-cols-[23rem_minmax(0,1fr)] xl:gap-8">
            <aside class="rounded-3xl bg-public-cream p-6 text-public-ink shadow-public-soft print:hidden sm:p-7">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-public-terracotta">Find a record</p>
                    <h2 class="mt-2 font-public-display text-2xl text-public-forest">Your confirmation details</h2>
                    <p class="mt-2 text-sm leading-6 text-public-muted">
                        Select the correct record type, then enter the exact reference and email used during submission.
                    </p>
                </div>

                <form wire:submit="search" class="mt-6 space-y-5">
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
                    <flux:input
                        wire:model.live.debounce.500ms="reference_no"
                        autocomplete="off"
                        placeholder="Enter your reference"
                    />
                    <flux:error name="reference_no" />
                </flux:field>

                <flux:field>
                    <flux:label>Email address</flux:label>
                    <flux:input
                        type="email"
                        wire:model.live.debounce.500ms="email"
                        autocomplete="email"
                        placeholder="guest@example.com"
                    />
                    <flux:error name="email" />
                </flux:field>

                <div class="grid gap-2 sm:grid-cols-[1fr_auto] lg:grid-cols-1 xl:grid-cols-[1fr_auto]">
                    <flux:button
                        type="submit"
                        variant="primary"
                        wire:loading.attr="disabled"
                        wire:target="search"
                    >
                        <span wire:loading.remove wire:target="search">Find confirmation</span>
                        <span wire:loading wire:target="search">Searching…</span>
                    </flux:button>
                    <flux:button
                        type="button"
                        variant="ghost"
                        wire:click="clearSearch"
                        wire:loading.attr="disabled"
                        wire:target="clearSearch"
                    >
                        Clear
                    </flux:button>
                </div>
                </form>

                <div class="mt-6 border-t border-public-forest/10 pt-5 text-xs leading-5 text-public-muted">
                    Your email is used only to match the reference with the guest record. A generic message is shown when the details do not match.
                </div>
            </aside>

            <div>
            @if ($reservation)
                <article class="rounded-3xl bg-white p-6 text-zinc-950 shadow-public-soft dark:bg-zinc-900 dark:text-white print:rounded-none print:p-0 print:shadow-none sm:p-8" id="confirmation-slip">
                    <div class="mb-6 flex flex-col gap-3 border-b border-zinc-200 pb-5 dark:border-zinc-800 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-public-spring">Reservation confirmation</p>
                            <h2 class="mt-2 break-all font-public-display text-3xl text-public-forest dark:text-emerald-200">{{ $reservation->r_ref_no }}</h2>
                            <div class="mt-3"><x-status-badge :status="$reservation->status" /></div>
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

                    <div class="mt-6 overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-800">
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
                                    <tr wire:key="reservation-confirmation-detail-{{ $detail->reservation_details_id }}">
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
                                    <li wire:key="reservation-confirmation-extra-guest-{{ $guest->reservation_extra_guest_id }}">• {{ $guest->full_name }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </article>
            @elseif ($booking)
                <article class="rounded-3xl bg-white p-6 text-zinc-950 shadow-public-soft dark:bg-zinc-900 dark:text-white print:rounded-none print:p-0 print:shadow-none sm:p-8" id="confirmation-slip">
                    <div class="mb-6 flex flex-col gap-3 border-b border-zinc-200 pb-5 dark:border-zinc-800 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-public-spring">Booking confirmation</p>
                            <h2 class="mt-2 break-all font-public-display text-3xl text-public-forest dark:text-emerald-200">{{ $booking->b_ref_no }}</h2>
                            <div class="mt-3"><x-status-badge :status="$booking->status" /></div>
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

                    <div class="mt-6 overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-800">
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
                                    <tr wire:key="booking-confirmation-detail-{{ $detail->booking_details_id }}">
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
                                    <div wire:key="booking-confirmation-payment-{{ $payment->payment_id }}" class="flex justify-between gap-3">
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
                                    <div wire:key="booking-confirmation-amenity-request-{{ $request->amenity_request_id }}">Request {{ $request->amenity_request_ref_no ?? ('#' . $request->amenity_request_id) }} — {{ $request->status }}</div>
                                @empty
                                    <p class="text-zinc-500">No amenity requests.</p>
                                @endforelse

                                @forelse ($booking->guestFines as $fine)
                                    <div wire:key="booking-confirmation-fine-{{ $fine->guest_fine_id }}">Fine: {{ $fine->fine?->fine_name ?? 'Fine' }} — ₱{{ number_format((float) $fine->total_charge, 2) }}</div>
                                @empty
                                    <p class="text-zinc-500">No fines.</p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </article>
            @elseif ($searched)
                <div role="alert" class="rounded-3xl border border-red-200 bg-red-50 p-7 text-red-800 shadow-public-card dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-200">
                    <h2 class="font-public-display text-2xl">We could not find that confirmation.</h2>
                    <p class="mt-2 text-sm leading-6">
                        Check the {{ $type }} reference and email, then try again. For privacy, the resort cannot identify which entry did not match.
                    </p>
                </div>
            @else
                <div class="overflow-hidden rounded-3xl bg-public-cream text-public-ink shadow-public-soft">
                    <div class="grid gap-0 md:grid-cols-[1fr_15rem]">
                        <div class="p-7 sm:p-9">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-public-terracotta">Keep your reference ready</p>
                            <h2 class="mt-3 font-public-display text-3xl text-public-forest">Your visit details, in one place</h2>
                            <p class="mt-3 max-w-2xl text-sm leading-7 text-public-muted">
                                Reservation results show the held facility and balance. Booking results also show payment review, amenity requests, and recorded charges when available.
                            </p>
                            <div class="mt-6 flex flex-wrap gap-3 print:hidden">
                                <flux:button href="{{ route('guest.reservations.create') }}" variant="primary" wire:navigate>
                                    Reserve a Facility
                                </flux:button>
                                <flux:button href="{{ route('guest.reservations.manage') }}" variant="ghost" wire:navigate>
                                    Manage Reservation
                                </flux:button>
                            </div>
                        </div>
                        <img
                            src="{{ asset('images/olaer/spring-day.webp') }}"
                            alt="Spring pools and greenery at Olaer Spring Resort"
                            class="h-56 w-full object-cover md:h-full"
                        >
                    </div>
                </div>
            @endif
            </div>
        </div>
    </div>
</section>
