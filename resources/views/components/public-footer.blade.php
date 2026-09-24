@php
    $resort = config('olaer.public');
@endphp

<footer class="bg-public-forest-deep text-white">
    <div class="mx-auto grid max-w-[90rem] gap-12 px-4 py-14 sm:px-6 md:grid-cols-2 lg:grid-cols-[1.3fr_0.7fr_0.8fr_1fr] lg:px-8 lg:py-20">
        <div class="max-w-md">
            <x-public-brand class="text-white" />
            <p class="mt-5 text-sm leading-7 text-white/65">
                A relaxed spring-resort escape in General Santos City, made for unhurried days with family and friends.
            </p>
            <p class="mt-5 text-xs font-semibold uppercase tracking-[0.22em] text-public-sand">
                {{ $resort['hours'] }}
            </p>
        </div>

        <div>
            <h2 class="text-xs font-semibold uppercase tracking-[0.2em] text-public-sand">Explore</h2>
            <nav aria-label="Footer resort links" class="mt-5 grid gap-3 text-sm text-white/70">
                <a href="{{ route('guest.home') }}#experience" class="hover:text-white">The Experience</a>
                <a href="{{ route('guest.home') }}#facilities" class="hover:text-white">Facilities & Rates</a>
                <a href="{{ route('guest.home') }}#gallery" class="hover:text-white">Gallery</a>
                <a href="{{ route('guest.home') }}#visit" class="hover:text-white">Plan Your Visit</a>
            </nav>
        </div>

        <div>
            <h2 class="text-xs font-semibold uppercase tracking-[0.2em] text-public-sand">Your Visit</h2>
            <nav aria-label="Footer booking links" class="mt-5 grid gap-3 text-sm text-white/70">
                <a href="{{ route('guest.reservations.create') }}" class="hover:text-white">Reserve a Facility</a>
                <a href="{{ route('guest.bookings.create') }}" class="hover:text-white">Direct Booking</a>
                <a href="{{ route('guest.reservations.manage') }}" class="hover:text-white">Manage Reservation</a>
                <a href="{{ route('guest.confirmations.lookup') }}" class="hover:text-white">Find Booking</a>
            </nav>
        </div>

        <div>
            <h2 class="text-xs font-semibold uppercase tracking-[0.2em] text-public-sand">Contact</h2>
            <address class="mt-5 grid gap-3 text-sm not-italic leading-6 text-white/70">
                <p>{{ $resort['address'] }}</p>
                @foreach ($resort['phones'] as $phone)
                    <a
                        wire:key="public-footer-phone-{{ $loop->index }}"
                        href="{{ $phone['href'] }}"
                        class="w-fit hover:text-white"
                    >
                        {{ $phone['display'] }}
                    </a>
                @endforeach
                <a href="mailto:{{ $resort['email'] }}" class="w-fit break-all hover:text-white">
                    {{ $resort['email'] }}
                </a>
            </address>
            <div class="mt-5 flex flex-wrap gap-x-4 gap-y-2 text-sm font-semibold">
                <a
                    href="{{ $resort['map_url'] }}"
                    target="_blank"
                    rel="noreferrer"
                    class="text-public-sand hover:text-white"
                >
                    Open Map
                </a>
                <a
                    href="{{ $resort['facebook_url'] }}"
                    target="_blank"
                    rel="noreferrer"
                    class="text-public-sand hover:text-white"
                >
                    Facebook
                </a>
            </div>
        </div>
    </div>

    <div class="border-t border-white/10">
        <div class="mx-auto flex max-w-[90rem] flex-col gap-3 px-4 py-5 text-xs text-white/50 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
            <p>&copy; {{ now()->year }} Olaer Spring Resort. All rights reserved.</p>
            <a href="{{ route('login') }}" class="w-fit hover:text-white">Staff Login</a>
        </div>
    </div>
</footer>
