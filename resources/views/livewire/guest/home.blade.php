<?php

use App\Models\EntranceFee;
use App\Models\Facility;
use App\Models\FacilityPrice;
use App\Models\FacilityType;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.public')] #[Title('Olaer Spring Resort | General Santos City')] class extends Component
{
    public function with(): array
    {
        return [
            'facilityTypes' => $this->facilityTypes(),
            'entranceFees' => $this->entranceFees(),
            'resort' => config('olaer.public'),
        ];
    }

    private function facilityTypes(): Collection
    {
        $facilityTypes = FacilityType::query()
            ->select([
                'facility_type_id',
                'facility_type',
            ])
            ->whereIn(
                'facility_type_id',
                Facility::query()
                    ->select('facility_type_id')
                    ->distinct(),
            )
            ->withCount('facilities')
            ->orderBy('facility_type')
            ->get();

        $ratesByFacilityType = FacilityPrice::query()
            ->join(
                'tbl_facility',
                'tbl_facility.facility_id',
                '=',
                'tbl_facility_price.facility_id',
            )
            ->select([
                'tbl_facility.facility_type_id',
                'tbl_facility_price.rate_type',
            ])
            ->selectRaw(
                'MIN(tbl_facility_price.facility_price) as starting_price',
            )
            ->groupBy([
                'tbl_facility.facility_type_id',
                'tbl_facility_price.rate_type',
            ])
            ->orderBy('tbl_facility_price.rate_type')
            ->toBase()
            ->get()
            ->groupBy(
                fn (object $rate): int => (int) $rate->facility_type_id,
            );

        return $facilityTypes->map(
            function (
                FacilityType $facilityType,
            ) use ($ratesByFacilityType): array {
                $rates = $ratesByFacilityType->get(
                    (int) $facilityType->facility_type_id,
                    collect(),
                );

                $startingPrice = $rates->min(
                    fn (object $rate): float => (float) $rate->starting_price,
                );

                return [
                    'id' => (int) $facilityType->facility_type_id,
                    'name' => (string) $facilityType->facility_type,
                    'facility_count' => (int) $facilityType->facilities_count,
                    'starting_price' => $startingPrice !== null
                        ? Number::currency(
                            (float) $startingPrice,
                            in: 'PHP',
                            locale: 'en_PH',
                            precision: 2,
                        )
                        : null,
                    'rates' => $rates
                        ->map(
                            fn (object $rate): array => [
                                'name' => (string) $rate->rate_type,
                                'price' => Number::currency(
                                    (float) $rate->starting_price,
                                    in: 'PHP',
                                    locale: 'en_PH',
                                    precision: 2,
                                ),
                            ],
                        )
                        ->all(),
                ];
            },
        );
    }

    private function entranceFees(): Collection
    {
        return EntranceFee::query()
            ->select([
                'entrance_fee_id',
                'entrance_fee_name',
                'entrance_fee_price',
            ])
            ->orderBy('entrance_fee_name')
            ->get()
            ->map(
                fn (EntranceFee $entranceFee): array => [
                    'id' => (int) $entranceFee->entrance_fee_id,
                    'name' => (string) $entranceFee->entrance_fee_name,
                    'price' => Number::currency(
                        (float) $entranceFee->entrance_fee_price,
                        in: 'PHP',
                        locale: 'en_PH',
                        precision: 2,
                    ),
                ],
            );
    }
};

?>

<div class="overflow-hidden bg-public-cream text-public-ink dark:bg-zinc-950 dark:text-zinc-100">
    <section class="relative isolate min-h-[44rem] overflow-hidden bg-public-forest-deep text-white">
        <img
            src="{{ asset('images/olaer/hero-spring.webp') }}"
            alt=""
            class="absolute inset-0 -z-20 size-full object-cover object-center"
            width="1920"
            height="1440"
            loading="eager"
            fetchpriority="high"
            decoding="async"
        >
        <div class="absolute inset-0 -z-10 bg-linear-to-r from-public-forest-deep via-public-forest-deep/75 to-public-forest-deep/10"></div>
        <div class="absolute inset-0 -z-10 bg-linear-to-t from-public-forest-deep/85 via-transparent to-black/10"></div>

        <div class="mx-auto flex min-h-[44rem] max-w-[90rem] items-end px-4 pb-28 pt-20 sm:px-6 lg:px-8 lg:pb-32">
            <div class="max-w-3xl">
                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-public-sand sm:text-sm">
                    Purok Olaer · General Santos City
                </p>
                <h1 class="mt-5 max-w-3xl font-public-display text-5xl font-semibold leading-[0.98] tracking-[-0.035em] sm:text-6xl lg:text-8xl">
                    GenSan’s refreshing spring escape.
                </h1>
                <p class="mt-6 max-w-2xl text-base leading-7 text-white/80 sm:text-lg sm:leading-8">
                    Step away from the rush and settle into a relaxed resort day surrounded by water, greenery, and the people who matter most.
                </p>

                <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
                    <flux:button
                        href="{{ route('guest.reservations.create') }}"
                        variant="primary"
                        class="!rounded-full !bg-white !px-6 !py-3 !font-semibold !text-public-forest hover:!bg-public-cream"
                    >
                        Reserve a Facility
                    </flux:button>
                    <a
                        href="#experience"
                        class="inline-flex min-h-11 items-center justify-center rounded-full border border-white/40 px-6 py-3 text-sm font-semibold text-white transition hover:border-white hover:bg-white/10"
                    >
                        Explore the Resort
                    </a>
                </div>
            </div>
        </div>

        <div class="absolute inset-x-0 bottom-0 border-t border-white/15 bg-public-forest-deep/65 backdrop-blur-md">
            <div class="mx-auto flex max-w-[90rem] flex-col gap-3 px-4 py-5 text-sm text-white/75 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                <p class="font-semibold text-white">{{ $resort['hours'] }}</p>
                <div class="flex flex-wrap gap-x-6 gap-y-2">
                    <a href="tel:09279435323" class="hover:text-white">09279435323</a>
                    <a
                        href="{{ $resort['map_url'] }}"
                        target="_blank"
                        rel="noreferrer"
                        class="font-semibold text-public-sand hover:text-white"
                    >
                        Get Directions
                    </a>
                </div>
            </div>
        </div>
    </section>

    <section id="experience" class="scroll-mt-8 px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
        <div class="mx-auto grid max-w-[90rem] items-center gap-12 lg:grid-cols-[0.9fr_1.1fr] lg:gap-20">
            <div class="relative mx-auto w-full max-w-xl lg:mx-0">
                <div class="overflow-hidden rounded-[2.5rem] bg-public-forest shadow-public-soft">
                    <img
                        src="{{ asset('images/olaer/spring-day.webp') }}"
                        alt="The clear spring pools and green surroundings at Olaer Spring Resort"
                        class="aspect-[4/5] size-full object-cover"
                        width="1080"
                        height="1438"
                        loading="lazy"
                        decoding="async"
                    >
                </div>
                <div class="absolute -bottom-7 -end-3 max-w-56 rounded-3xl bg-public-forest p-5 text-white shadow-public-card sm:end-8">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-public-sand">A local escape</p>
                    <p class="mt-2 font-public-display text-2xl leading-tight">Close to home. Far from the rush.</p>
                </div>
            </div>

            <div class="pt-8 lg:pt-0">
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-public-terracotta">The Olaer experience</p>
                <h2 class="mt-4 max-w-3xl font-public-display text-4xl font-semibold leading-tight tracking-tight text-public-forest sm:text-5xl lg:text-6xl dark:text-white">
                    Easygoing by nature, memorable because you share it.
                </h2>
                <p class="mt-6 max-w-2xl text-base leading-8 text-public-muted dark:text-zinc-300">
                    Olaer is a familiar GenSan place for cooling down, gathering together, and making an ordinary day feel like a proper break.
                </p>

                <div class="mt-10 grid gap-7 sm:grid-cols-3">
                    <div class="border-t border-public-forest/20 pt-5 dark:border-white/20">
                        <h3 class="font-public-display text-2xl font-semibold text-public-forest dark:text-white">Unhurried</h3>
                        <p class="mt-2 text-sm leading-6 text-public-muted dark:text-zinc-400">Make room for a slower day beside the water.</p>
                    </div>
                    <div class="border-t border-public-forest/20 pt-5 dark:border-white/20">
                        <h3 class="font-public-display text-2xl font-semibold text-public-forest dark:text-white">Together</h3>
                        <p class="mt-2 text-sm leading-6 text-public-muted dark:text-zinc-400">A relaxed setting for family and friends.</p>
                    </div>
                    <div class="border-t border-public-forest/20 pt-5 dark:border-white/20">
                        <h3 class="font-public-display text-2xl font-semibold text-public-forest dark:text-white">Local</h3>
                        <p class="mt-2 text-sm leading-6 text-public-muted dark:text-zinc-400">A refreshing escape right here in GenSan.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="facilities" class="scroll-mt-8 bg-public-forest px-4 py-20 text-white sm:px-6 lg:px-8 lg:py-28">
        <div class="mx-auto max-w-[90rem]">
            <div class="flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-public-sand">Facilities & rates</p>
                    <h2 class="mt-4 font-public-display text-4xl font-semibold leading-tight tracking-tight sm:text-5xl lg:text-6xl">
                        Find the space that fits your day.
                    </h2>
                </div>
                <p class="max-w-xl text-sm leading-7 text-white/65">
                    Rates below come directly from the resort system. Final availability and total depend on your dates, rate type, and selected facility.
                </p>
            </div>

            <div class="mt-12 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                @forelse ($facilityTypes as $facilityType)
                    <article
                        wire:key="public-facility-type-{{ $facilityType['id'] }}"
                        class="flex min-h-80 flex-col rounded-[2rem] border border-white/15 bg-white/7 p-6 shadow-public-card backdrop-blur-sm sm:p-7"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-public-sand">
                                    {{ $facilityType['facility_count'] }} {{ Str::plural('facility', $facilityType['facility_count']) }}
                                </p>
                                <h3 class="mt-3 font-public-display text-3xl font-semibold">
                                    {{ $facilityType['name'] }}
                                </h3>
                            </div>
                            @if ($facilityType['starting_price'])
                                <div class="text-end">
                                    <p class="text-xs text-white/55">From</p>
                                    <p class="mt-1 font-semibold">{{ $facilityType['starting_price'] }}</p>
                                </div>
                            @endif
                        </div>

                        <div class="mt-7 grid gap-3 border-t border-white/15 pt-5 text-sm">
                            @forelse ($facilityType['rates'] as $rate)
                                <div
                                    wire:key="public-facility-rate-{{ $facilityType['id'] }}-{{ Str::slug($rate['name']) }}"
                                    class="flex items-center justify-between gap-4"
                                >
                                    <span class="text-white/65">{{ $rate['name'] }}</span>
                                    <span class="font-semibold text-white">{{ $rate['price'] }}</span>
                                </div>
                            @empty
                                <p class="text-white/60">Ask the resort team for the current rate options.</p>
                            @endforelse
                        </div>

                        <a
                            href="{{ route('guest.reservations.create') }}"
                            class="mt-auto inline-flex items-center justify-between gap-3 border-t border-white/15 pt-6 text-sm font-semibold text-public-sand hover:text-white"
                        >
                            Check availability
                            <span aria-hidden="true">&rarr;</span>
                        </a>
                    </article>
                @empty
                    <div class="rounded-[2rem] border border-white/15 bg-white/7 p-8 md:col-span-2 xl:col-span-3">
                        <h3 class="font-public-display text-3xl font-semibold">Facility rates are being updated.</h3>
                        <p class="mt-3 max-w-2xl text-sm leading-7 text-white/65">
                            You can still start a reservation or contact the resort team for current options.
                        </p>
                    </div>
                @endforelse
            </div>

            <div class="mt-8 flex flex-col gap-4 rounded-[2rem] bg-public-cream p-6 text-public-ink sm:flex-row sm:items-center sm:justify-between sm:p-8">
                <div>
                    <h3 class="font-public-display text-3xl font-semibold text-public-forest">Ready to choose your date?</h3>
                    <p class="mt-2 text-sm leading-6 text-public-muted">Create a temporary facility hold and complete payment verification with the cashier.</p>
                </div>
                <flux:button
                    href="{{ route('guest.reservations.create') }}"
                    variant="primary"
                    class="!rounded-full sm:shrink-0"
                >
                    Reserve a Facility
                </flux:button>
            </div>
        </div>
    </section>

    <section class="px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
        <div class="mx-auto grid max-w-[90rem] gap-8 lg:grid-cols-[1.05fr_0.95fr]">
            <div class="rounded-[2.5rem] bg-white p-7 shadow-public-card sm:p-10 dark:bg-zinc-900">
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-public-terracotta">Entrance fees</p>
                <h2 class="mt-4 font-public-display text-4xl font-semibold text-public-forest sm:text-5xl dark:text-white">
                    Plan the whole visit.
                </h2>
                <p class="mt-4 max-w-2xl text-sm leading-7 text-public-muted dark:text-zinc-300">
                    Current entrance-fee categories are maintained by the resort team and shown here directly from the system.
                </p>

                <div class="mt-8 divide-y divide-public-forest/10 border-y border-public-forest/10 dark:divide-white/10 dark:border-white/10">
                    @forelse ($entranceFees as $entranceFee)
                        <div
                            wire:key="public-entrance-fee-{{ $entranceFee['id'] }}"
                            class="flex items-center justify-between gap-6 py-4"
                        >
                            <span class="font-medium text-public-ink dark:text-white">{{ $entranceFee['name'] }}</span>
                            <span class="font-semibold text-public-forest dark:text-public-spring-light">{{ $entranceFee['price'] }}</span>
                        </div>
                    @empty
                        <p class="py-5 text-sm text-public-muted dark:text-zinc-400">
                            Contact the resort team for the current entrance-fee categories.
                        </p>
                    @endforelse
                </div>
            </div>

            <div class="grid gap-5">
                <article class="rounded-[2.5rem] bg-public-spring-light p-7 sm:p-9 dark:bg-public-forest">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-public-terracotta dark:text-public-sand">Recommended</p>
                    <h2 class="mt-3 font-public-display text-3xl font-semibold text-public-forest dark:text-white">Reserve first, finalize with the cashier.</h2>
                    <p class="mt-4 text-sm leading-7 text-public-muted dark:text-white/70">
                        A reservation places a temporary hold on your selected facility. Payment and final booking verification are handled by the cashier.
                    </p>
                    <a
                        href="{{ route('guest.reservations.create') }}"
                        class="mt-6 inline-flex rounded-full bg-public-forest px-5 py-3 text-sm font-semibold text-white hover:bg-public-forest-deep"
                    >
                        Start a Reservation
                    </a>
                </article>

                <article class="rounded-[2.5rem] border border-public-forest/15 p-7 sm:p-9 dark:border-white/15">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-public-terracotta">Direct booking</p>
                    <h2 class="mt-3 font-public-display text-3xl font-semibold text-public-forest dark:text-white">Already ready to pay in full?</h2>
                    <p class="mt-4 text-sm leading-7 text-public-muted dark:text-zinc-300">
                        Direct booking requires the exact full GCash payment and payment-proof upload for cashier verification.
                    </p>
                    <a
                        href="{{ route('guest.bookings.create') }}"
                        class="mt-6 inline-flex rounded-full border border-public-forest/25 px-5 py-3 text-sm font-semibold text-public-forest hover:bg-public-forest hover:text-white dark:border-white/25 dark:text-white"
                    >
                        Continue to Direct Booking
                    </a>
                </article>
            </div>
        </div>
    </section>

    <section id="gallery" class="scroll-mt-8 bg-public-cream-muted px-4 py-20 sm:px-6 lg:px-8 lg:py-28 dark:bg-zinc-900">
        <div class="mx-auto max-w-[90rem]">
            <div class="max-w-3xl">
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-public-terracotta">Around Olaer</p>
                <h2 class="mt-4 font-public-display text-4xl font-semibold leading-tight text-public-forest sm:text-5xl lg:text-6xl dark:text-white">
                    A glimpse of your next resort day.
                </h2>
            </div>

            <div class="mt-12 grid auto-rows-[14rem] gap-4 sm:grid-cols-2 sm:auto-rows-[20rem] lg:grid-cols-4">
                <figure class="overflow-hidden rounded-[2rem] sm:row-span-2">
                    <img
                        src="{{ asset('images/olaer/aerial-pools.webp') }}"
                        alt="Aerial view of Olaer Spring Resort pools and cottages"
                        class="size-full object-cover motion-safe:transition motion-safe:duration-500 motion-safe:hover:scale-[1.02]"
                        width="1200"
                        height="1492"
                        loading="lazy"
                        decoding="async"
                    >
                </figure>
                <figure class="overflow-hidden rounded-[2rem] lg:col-span-2">
                    <img
                        src="{{ asset('images/olaer/entrance-night.webp') }}"
                        alt="The illuminated Olaer Swimming Resort sign at night"
                        class="size-full object-cover motion-safe:transition motion-safe:duration-500 motion-safe:hover:scale-[1.02]"
                        width="1080"
                        height="1080"
                        loading="lazy"
                        decoding="async"
                    >
                </figure>
                <figure class="overflow-hidden rounded-[2rem] sm:row-span-2">
                    <img
                        src="{{ asset('images/olaer/resort-grounds.webp') }}"
                        alt="A bright view across the spring pools and palm-lined resort grounds"
                        class="size-full object-cover motion-safe:transition motion-safe:duration-500 motion-safe:hover:scale-[1.02]"
                        width="1190"
                        height="1600"
                        loading="lazy"
                        decoding="async"
                    >
                </figure>
                <figure class="overflow-hidden rounded-[2rem]">
                    <img
                        src="{{ asset('images/olaer/olaer-sign.webp') }}"
                        alt="Visitors posing by the colorful Olaer Swimming Resort sign"
                        class="size-full object-cover motion-safe:transition motion-safe:duration-500 motion-safe:hover:scale-[1.02]"
                        width="1200"
                        height="1600"
                        loading="lazy"
                        decoding="async"
                    >
                </figure>
                <figure class="overflow-hidden rounded-[2rem]">
                    <img
                        src="{{ asset('images/olaer/family-spring.webp') }}"
                        alt="Families enjoying the spring pools and landscaped resort grounds"
                        class="size-full object-cover motion-safe:transition motion-safe:duration-500 motion-safe:hover:scale-[1.02]"
                        width="1200"
                        height="1600"
                        loading="lazy"
                        decoding="async"
                    >
                </figure>
            </div>
        </div>
    </section>

    <section id="visit" class="scroll-mt-8 px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
        <div class="mx-auto grid max-w-[90rem] overflow-hidden rounded-[2.5rem] bg-public-forest-deep text-white shadow-public-soft lg:grid-cols-[1.05fr_0.95fr]">
            <div class="p-7 sm:p-10 lg:p-14">
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-public-sand">Plan your visit</p>
                <h2 class="mt-4 max-w-2xl font-public-display text-4xl font-semibold leading-tight sm:text-5xl">
                    Your next refreshing day is closer than you think.
                </h2>

                <div class="mt-10 grid gap-7 sm:grid-cols-2">
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-[0.18em] text-white/45">Address</h3>
                        <p class="mt-3 text-sm leading-7 text-white/75">{{ $resort['address'] }}</p>
                    </div>
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-[0.18em] text-white/45">Opening hours</h3>
                        <p class="mt-3 text-sm font-semibold text-white">{{ $resort['hours'] }}</p>
                    </div>
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-[0.18em] text-white/45">Call us</h3>
                        <div class="mt-3 grid gap-2 text-sm text-white/75">
                            @foreach ($resort['phones'] as $phone)
                                <a
                                    wire:key="public-contact-phone-{{ $loop->index }}"
                                    href="{{ $phone['href'] }}"
                                    class="w-fit hover:text-white"
                                >
                                    {{ $phone['display'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-[0.18em] text-white/45">Email</h3>
                        <a
                            href="mailto:{{ $resort['email'] }}"
                            class="mt-3 block break-all text-sm text-white/75 hover:text-white"
                        >
                            {{ $resort['email'] }}
                        </a>
                    </div>
                </div>

                <div class="mt-10 flex flex-col gap-3 sm:flex-row">
                    <a
                        href="{{ $resort['map_url'] }}"
                        target="_blank"
                        rel="noreferrer"
                        class="inline-flex min-h-11 items-center justify-center rounded-full bg-white px-5 py-3 text-sm font-semibold text-public-forest hover:bg-public-cream"
                    >
                        Open in Google Maps
                    </a>
                    <a
                        href="{{ $resort['facebook_url'] }}"
                        target="_blank"
                        rel="noreferrer"
                        class="inline-flex min-h-11 items-center justify-center rounded-full border border-white/25 px-5 py-3 text-sm font-semibold text-white hover:bg-white/10"
                    >
                        Visit Facebook
                    </a>
                </div>
            </div>

            <div class="min-h-[26rem]">
                <img
                    src="{{ asset('images/olaer/pools-aerial.webp') }}"
                    alt="Aerial view showing the long pools and green surroundings of Olaer Spring Resort"
                    class="size-full object-cover"
                    width="1080"
                    height="1440"
                    loading="lazy"
                    decoding="async"
                >
            </div>
        </div>
    </section>

    <section class="px-4 pb-20 sm:px-6 lg:px-8 lg:pb-28">
        <div class="mx-auto flex max-w-[90rem] flex-col items-center rounded-[2.5rem] border border-public-forest/15 px-6 py-14 text-center sm:px-10 lg:py-20 dark:border-white/15">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-public-terracotta">Make time for Olaer</p>
            <h2 class="mt-4 max-w-4xl font-public-display text-4xl font-semibold leading-tight text-public-forest sm:text-5xl lg:text-6xl dark:text-white">
                Choose your facility, hold your date, and look forward to the water.
            </h2>
            <p class="mt-5 max-w-2xl text-sm leading-7 text-public-muted dark:text-zinc-300">
                Start with a reservation to check real availability for your preferred schedule.
            </p>
            <flux:button
                href="{{ route('guest.reservations.create') }}"
                variant="primary"
                class="mt-8 !rounded-full !px-7 !py-3"
            >
                Reserve a Facility
            </flux:button>
        </div>
    </section>
</div>
