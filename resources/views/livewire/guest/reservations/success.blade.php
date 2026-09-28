<?php

use App\Models\Reservation;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.public')] #[Title('Reservation Received - Olaer Spring Resort')] class extends Component
{
    public function with(): array
    {
        $reservationId = session(
            'guest.reservation_confirmation_id',
        );

        return [
            'reservation' => $reservationId
                ? Reservation::query()
                    ->with([
                        'guest',
                        'details.facility.facilityType',
                    ])
                    ->find((int) $reservationId)
                : null,
        ];
    }
};

?>

<section class="relative overflow-hidden bg-emerald-950 py-12 text-white sm:py-16">
    <div class="absolute inset-0 opacity-20">
        <img
            src="{{ asset('images/olaer/hero-spring.webp') }}"
            alt=""
            class="h-full w-full object-cover"
        >
    </div>
    <div class="absolute inset-0 bg-linear-to-b from-emerald-950/60 via-emerald-950/85 to-emerald-950"></div>

    <div class="relative mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
        @if ($reservation)
            @php($detail = $reservation->details->first())

            <div class="overflow-hidden rounded-3xl bg-white text-zinc-950 shadow-2xl shadow-black/20 dark:bg-zinc-900 dark:text-white">
                <header class="border-b border-emerald-100 bg-emerald-50 px-6 py-8 dark:border-emerald-900 dark:bg-emerald-950/50 sm:px-10">
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">
                        Reservation received
                    </p>
                    <h1 class="mt-3 text-3xl font-bold tracking-tight text-emerald-950 dark:text-emerald-50 sm:text-4xl">
                        Thank you, {{ $reservation->guest?->first_name }}.
                    </h1>
                    <p class="mt-3 max-w-3xl text-sm leading-6 text-emerald-800 dark:text-emerald-200 sm:text-base">
                        Your facility reservation is now on temporary hold. Save the reference below while the resort completes payment and booking confirmation.
                    </p>
                </header>

            <div class="grid gap-8 px-6 py-8 sm:px-10 lg:grid-cols-[minmax(0,1fr)_300px]">
                <div class="space-y-8">
                    <div>
                        <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Reservation reference</p>
                        <p class="mt-1 break-all text-3xl font-bold tracking-tight text-emerald-700 dark:text-emerald-300">
                            {{ $reservation->r_ref_no }}
                        </p>
                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                            Keep this reference for confirmation lookup, reservation management, payment, and cashier assistance.
                        </p>
                    </div>

                    <dl class="grid gap-px overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-200 text-sm dark:border-zinc-700 dark:bg-zinc-700 sm:grid-cols-2">
                        <div class="bg-white p-5 dark:bg-zinc-900">
                            <dt class="text-zinc-500 dark:text-zinc-400">Guest</dt>
                            <dd class="mt-1 font-semibold">{{ $reservation->guest?->full_name }}</dd>
                            <dd class="mt-1 text-zinc-600 dark:text-zinc-300">{{ $reservation->guest?->email }}</dd>
                        </div>
                        <div class="bg-white p-5 dark:bg-zinc-900">
                            <dt class="text-zinc-500 dark:text-zinc-400">Facility</dt>
                            <dd class="mt-1 font-semibold">{{ $detail?->facility?->facility_name }}</dd>
                            <dd class="mt-1 text-zinc-600 dark:text-zinc-300">
                                {{ $detail?->facility?->facilityType?->facility_type }} · {{ $detail?->rate_type }}
                            </dd>
                        </div>
                        <div class="bg-white p-5 dark:bg-zinc-900">
                            <dt class="text-zinc-500 dark:text-zinc-400">Schedule</dt>
                            <dd class="mt-1 font-semibold">{{ optional($detail?->check_in_date)->format('M d, Y') }}</dd>
                            <dd class="mt-1 text-zinc-600 dark:text-zinc-300">
                                Until {{ optional($detail?->check_out_date)->format('M d, Y') }}
                            </dd>
                        </div>
                        <div class="bg-white p-5 dark:bg-zinc-900">
                            <dt class="text-zinc-500 dark:text-zinc-400">Party and amount</dt>
                            <dd class="mt-1 font-semibold">
                                {{ $reservation->total_guest_count }} {{ Str::plural('guest', $reservation->total_guest_count) }}
                            </dd>
                            <dd class="mt-1 text-zinc-600 dark:text-zinc-300">
                                Estimated total: ₱{{ number_format((float) $reservation->total_price, 2) }}
                            </dd>
                            <dd class="mt-1 text-zinc-600 dark:text-zinc-300">Status: {{ $reservation->status }}</dd>
                        </div>
                    </dl>

                    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                        <p class="font-semibold">This is a reservation hold, not yet a confirmed booking.</p>
                        <p class="mt-1 leading-6">
                            Payment and final booking confirmation are still handled by the resort. An unpaid reservation remains subject to the resort’s reservation workflow.
                        </p>
                    </div>
                </div>

                <aside class="space-y-5">
                    <div class="rounded-2xl bg-zinc-50 p-5 dark:bg-zinc-950">
                        <h2 class="text-base font-semibold">What happens next</h2>
                        <ol class="mt-4 space-y-4 text-sm text-zinc-600 dark:text-zinc-300">
                            <li>
                                <span class="font-semibold text-zinc-950 dark:text-white">1. Save your reference.</span>
                                You will need it to retrieve or manage the reservation.
                            </li>
                            <li>
                                <span class="font-semibold text-zinc-950 dark:text-white">2. Check your email.</span>
                                A confirmation copy may also arrive at the address you provided.
                            </li>
                            <li>
                                <span class="font-semibold text-zinc-950 dark:text-white">3. Complete confirmation.</span>
                                Coordinate payment and final booking confirmation with the resort.
                            </li>
                        </ol>
                    </div>

                    <div class="flex flex-col gap-3 print:hidden">
                        <flux:button href="{{ route('guest.reservations.manage') }}" variant="primary" wire:navigate>
                            Manage this reservation
                        </flux:button>
                        <flux:button href="{{ route('guest.reservations.create') }}" variant="ghost" wire:navigate>
                            Reserve another facility
                        </flux:button>
                        <a
                            href="{{ route('guest.home') }}"
                            wire:navigate
                            class="text-center text-sm font-medium text-emerald-700 hover:text-emerald-800 hover:underline dark:text-emerald-300 dark:hover:text-emerald-200"
                        >
                            Return to homepage
                        </a>
                    </div>
                </aside>
                </div>
            </div>
        @else
            <div class="rounded-3xl bg-white px-6 py-12 text-center text-zinc-950 shadow-2xl shadow-black/20 dark:bg-zinc-900 dark:text-white sm:px-10">
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">
                    Reservation confirmation
                </p>
                <h1 class="mt-3 text-3xl font-bold tracking-tight">
                    No recent reservation is available in this browser.
                </h1>
                <p class="mx-auto mt-3 max-w-2xl text-sm leading-6 text-zinc-600 dark:text-zinc-300 sm:text-base">
                    Submit a facility reservation first, or use your reference number and email address to retrieve an existing confirmation.
                </p>
                <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                    <flux:button href="{{ route('guest.reservations.create') }}" variant="primary" wire:navigate>
                        Reserve a Facility
                    </flux:button>
                    <flux:button href="{{ route('guest.confirmations.lookup') }}" variant="subtle" wire:navigate>
                        Find a confirmation
                    </flux:button>
                </div>
            </div>
        @endif
    </div>
</section>
