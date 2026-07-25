@php
    $homeSections = [
        ['label' => 'Experience', 'fragment' => 'experience'],
        ['label' => 'Facilities & Rates', 'fragment' => 'facilities'],
        ['label' => 'Gallery', 'fragment' => 'gallery'],
        ['label' => 'Plan Your Visit', 'fragment' => 'visit'],
    ];
@endphp

<header class="relative z-40 border-b border-public-forest/10 bg-public-cream">
    <div class="mx-auto flex min-h-20 max-w-[90rem] items-center justify-between gap-5 px-4 sm:px-6 lg:px-8">
        <x-public-brand
            compact
            class="text-public-forest focus-visible:outline-public-spring"
        />

        <nav
            aria-label="Primary navigation"
            class="hidden items-center gap-6 lg:flex"
        >
            @foreach ($homeSections as $item)
                <a
                    wire:key="public-navigation-desktop-{{ $item['fragment'] }}"
                    href="{{ route('guest.home') }}#{{ $item['fragment'] }}"
                    class="text-sm font-medium text-public-forest/75 transition hover:text-public-forest"
                >
                    {{ $item['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="hidden items-center gap-2 lg:flex">
            <a
                href="{{ route('guest.reservations.manage') }}"
                class="rounded-full px-4 py-2.5 text-sm font-semibold text-public-forest transition hover:bg-public-forest/5"
            >
                Manage
            </a>
            <a
                href="{{ route('guest.reservations.create') }}"
                class="rounded-full bg-public-forest px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-public-forest-deep"
            >
                Reserve a Facility
            </a>
        </div>

        <details class="group relative lg:hidden">
            <summary class="flex cursor-pointer list-none items-center gap-2 rounded-full border border-public-forest/20 bg-white/70 px-4 py-2.5 text-sm font-semibold text-public-forest marker:content-none [&::-webkit-details-marker]:hidden">
                <flux:icon.bars-3 class="size-5" />
                Menu
            </summary>

            <div class="absolute end-0 top-[calc(100%+0.75rem)] w-[min(21rem,calc(100vw-2rem))] rounded-3xl border border-public-forest/10 bg-public-cream p-3 shadow-public-soft">
                <nav aria-label="Mobile navigation" class="grid">
                    <a
                        href="{{ route('guest.home') }}"
                        class="rounded-2xl px-4 py-3 text-sm font-semibold text-public-forest hover:bg-white/70"
                    >
                        Home
                    </a>

                    @foreach ($homeSections as $item)
                        <a
                            wire:key="public-navigation-mobile-{{ $item['fragment'] }}"
                            href="{{ route('guest.home') }}#{{ $item['fragment'] }}"
                            class="rounded-2xl px-4 py-3 text-sm font-medium text-public-forest/75 hover:bg-white/70 hover:text-public-forest"
                        >
                            {{ $item['label'] }}
                        </a>
                    @endforeach

                    <div class="my-2 border-t border-public-forest/10"></div>

                    <a
                        href="{{ route('guest.reservations.manage') }}"
                        class="rounded-2xl px-4 py-3 text-sm font-medium text-public-forest/75 hover:bg-white/70 hover:text-public-forest"
                    >
                        Manage Reservation
                    </a>
                    <a
                        href="{{ route('guest.confirmations.lookup') }}"
                        class="rounded-2xl px-4 py-3 text-sm font-medium text-public-forest/75 hover:bg-white/70 hover:text-public-forest"
                    >
                        Find Booking
                    </a>
                    <a
                        href="{{ route('guest.bookings.create') }}"
                        class="rounded-2xl px-4 py-3 text-sm font-medium text-public-forest/75 hover:bg-white/70 hover:text-public-forest"
                    >
                        Direct Booking
                    </a>
                    <a
                        href="{{ route('guest.reservations.create') }}"
                        class="mt-2 rounded-2xl bg-public-forest px-4 py-3 text-center text-sm font-semibold text-white"
                    >
                        Reserve a Facility
                    </a>
                </nav>
            </div>
        </details>
    </div>
</header>
