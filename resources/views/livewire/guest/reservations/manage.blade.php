<?php

use App\Models\Reservation;
use App\Services\GuestReservationManagementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.public')] #[Title('Manage Reservation - Olaer Spring Resort')] class extends Component
{
    public string $referenceNumber = '';
    public string $email = '';
    public string $otp = '';
    #[Locked]
    public ?int $reservationId = null;
    #[Locked]
    public bool $otpRequested = false;
    #[Locked]
    public bool $verified = false;
    #[Locked]
    public ?string $debugOtp = null;
    public ?string $successMessage = null;
    public ?string $errorMessage = null;

    public bool $showUpdateForm = false;
    public bool $showCancelForm = false;

    public array $details = [];
    public string $cancellationReason = '';

    public function mount(): void
    {
        //
    }

    public function requestOtp(GuestReservationManagementService $service): void
    {
        $this->resetVerification();
        $this->resetMessages();

        $this->validate([
            'referenceNumber' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:100'],
        ]);

        $rateLimitKey = $this->otpRequestRateLimitKey();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $this->errorMessage =
                'We could not send a one-time code. Check your details or try again later.';

            return;
        }

        RateLimiter::hit($rateLimitKey, 600);

        try {
            $result = $service->requestOtp($this->referenceNumber, $this->email);
            $this->reservationId = $result['reservation_id'];
            $this->debugOtp = app()->environment('local')
                ? $result['debug_otp']
                : null;
            $this->otpRequested = true;
            $this->successMessage = 'OTP sent to the reservation email.';
        } catch (Throwable) {
            $this->errorMessage =
                'We could not send a one-time code. Check your details or try again later.';
        }
    }

    public function verifyOtp(GuestReservationManagementService $service): void
    {
        $this->resetMessages();

        $this->validate([
            'otp' => ['required', 'digits:6'],
        ]);

        try {
            $reservation = $service->verifyOtp((int) $this->reservationId, $this->email, $this->otp);
            $this->reservationId = (int) $reservation->reservation_id;
            $this->verified = true;
            $this->otpRequested = false;
            $this->successMessage = 'Reservation verified. You can now update or cancel it.';
        } catch (\InvalidArgumentException $exception) {
            $this->errorMessage = $exception->getMessage();
        } catch (Throwable $exception) {
            report($exception);
            $this->errorMessage = 'We could not verify the code. Please try again or request a new one.';
        }
    }

    public function prepareUpdate(GuestReservationManagementService $service): void
    {
        if (! $this->ensureVerifiedReservation()) {
            return;
        }

        $reservation = $this->reservation();

        if (! $reservation) {
            return;
        }

        $editable = $service->editableDetails($reservation);

        if ($editable->isEmpty()) {
            $this->errorMessage = 'Reservation has no facility detail to update.';
            return;
        }

        $occupancy = app(\App\Services\FacilityOccupancyService::class);
        $details = [];

        foreach ($editable as $detail) {
            $id = (int) $detail->reservation_details_id;
            $single = $editable->count() === 1;
            $rows = $reservation->extraGuests->filter(
                fn ($guest): bool => (int) $guest->reservation_details_id === $id
                    || ($single && $guest->reservation_details_id === null),
            );
            $guestCount = (int) $detail->guest_count;

            if ($guestCount < 1) {
                $extraCount = $single ? (int) $reservation->no_of_extra_guests : $rows->count();
                $guestCount = $single && (int) $reservation->total_guest_count > 0
                    ? (int) $reservation->total_guest_count
                    : (int) (
                        $detail->facility
                            ? $occupancy->legacyTotalGuestCount($detail->facility, $extraCount)
                            : max(1, $extraCount + 1)
                    );
            }

            $details[$id] = [
                'facility_type_id' => (int) $detail->facility?->facility_type_id,
                'rate_type' => (string) $detail->rate_type,
                'facility_id' => (int) $detail->facility_id,
                'check_in_date' => optional($detail->check_in_date)->format('Y-m-d') ?: Carbon::tomorrow()->toDateString(),
                'check_out_date' => optional($detail->check_out_date)->format('Y-m-d') ?: Carbon::tomorrow()->addDay()->toDateString(),
                'total_guest_count' => $guestCount,
                'discount_id' => $detail->discount_id ? (int) $detail->discount_id : null,
                'extra_guests' => $rows
                    ->map(fn ($guest): array => [
                        'first_name' => (string) $guest->first_name,
                        'middle_name' => (string) ($guest->middle_name ?? ''),
                        'last_name' => (string) $guest->last_name,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        $this->details = $details;
        $this->showUpdateForm = true;
        $this->showCancelForm = false;

        foreach (array_keys($this->details) as $id) {
            $this->syncPaidExtraGuestRows($id);
        }
    }

    public function prepareCancel(): void
    {
        if (! $this->ensureVerifiedReservation()) {
            return;
        }

        $this->showCancelForm = true;
        $this->showUpdateForm = false;
        $this->cancellationReason = '';
    }

    public function updatedDetails(mixed $value, string $key): void
    {
        [$id, $field] = array_pad(explode('.', $key, 2), 2, '');

        if (! isset($this->details[$id])) {
            return;
        }

        $field = explode('.', $field)[0];

        if ($field === 'facility_type_id') {
            $this->details[$id]['rate_type'] = '';
            $this->details[$id]['facility_id'] = null;
            $this->details[$id]['total_guest_count'] = 1;
            $this->syncPaidExtraGuestRows($id);
        } elseif ($field === 'rate_type') {
            $this->details[$id]['facility_id'] = null;
        } elseif (in_array($field, ['facility_id', 'total_guest_count'], true)) {
            $this->syncPaidExtraGuestRows($id);
        }
    }

    public function updateReservation(GuestReservationManagementService $service): void
    {
        if (! $this->ensureVerifiedReservation()) {
            return;
        }

        $this->resetMessages();

        if ($this->details === []) {
            $this->errorMessage = 'Open the update form before saving.';
            return;
        }

        $rules = [];
        $attributes = [];

        foreach (array_keys($this->details) as $id) {
            $guestRules = ['required', 'integer', 'min:1'];
            $maximum = $this->strictMaximumFor($id);

            if ($maximum !== null) {
                $guestRules[] = 'max:'.$maximum;
            }

            $key = "details.{$id}";
            $rules["{$key}.facility_type_id"] = ['required', 'integer', 'exists:tbl_facility_type,facility_type_id'];
            $rules["{$key}.rate_type"] = ['required', 'string', 'max:20'];
            $rules["{$key}.facility_id"] = ['required', 'integer', 'exists:tbl_facility,facility_id'];
            $rules["{$key}.check_in_date"] = ['required', 'date_format:Y-m-d', 'after_or_equal:today'];
            $rules["{$key}.check_out_date"] = ['required', 'date_format:Y-m-d', "after:{$key}.check_in_date"];
            $rules["{$key}.total_guest_count"] = $guestRules;
            $rules["{$key}.extra_guests.*.first_name"] = ['required', 'string', 'max:50'];
            $rules["{$key}.extra_guests.*.middle_name"] = ['nullable', 'string', 'max:50'];
            $rules["{$key}.extra_guests.*.last_name"] = ['required', 'string', 'max:50'];

            $attributes["{$key}.facility_type_id"] = 'facility type';
            $attributes["{$key}.rate_type"] = 'rate type';
            $attributes["{$key}.facility_id"] = 'facility';
            $attributes["{$key}.check_in_date"] = 'check-in date';
            $attributes["{$key}.check_out_date"] = 'check-out date';
            $attributes["{$key}.total_guest_count"] = 'total guests';
            $attributes["{$key}.extra_guests.*.first_name"] = 'first name';
            $attributes["{$key}.extra_guests.*.middle_name"] = 'middle name';
            $attributes["{$key}.extra_guests.*.last_name"] = 'last name';
        }

        $this->validate($rules, [], $attributes);

        foreach (array_keys($this->details) as $id) {
            $this->syncPaidExtraGuestRows($id);
        }

        $overlap = $this->overlappingSelection();

        if ($overlap !== null) {
            $this->errorMessage = $overlap;
            return;
        }

        try {
            $service->updateReservation((int) $this->reservationId, [
                'details' => collect($this->details)
                    ->map(fn (array $row, int|string $id): array => [
                        'reservation_details_id' => (int) $id,
                        'facility_id' => (int) $row['facility_id'],
                        'rate_type' => (string) $row['rate_type'],
                        'check_in_date' => (string) $row['check_in_date'],
                        'check_out_date' => (string) $row['check_out_date'],
                        'total_guest_count' => (int) $row['total_guest_count'],
                        'extra_guests' => (array) ($row['extra_guests'] ?? []),
                    ])
                    ->values()
                    ->all(),
            ]);

            $this->showUpdateForm = false;
            $this->details = [];
            $this->successMessage = 'Reservation updated successfully.';
        } catch (\InvalidArgumentException $exception) {
            $this->errorMessage = $exception->getMessage();
        } catch (Throwable $exception) {
            report($exception);
            $this->errorMessage = 'The reservation could not be updated. Refresh the page and try again.';
        }
    }

    public function cancelReservation(GuestReservationManagementService $service): void
    {
        if (! $this->ensureVerifiedReservation()) {
            return;
        }

        $this->resetMessages();

        $this->validate([
            'cancellationReason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $service->cancelReservation((int) $this->reservationId, $this->cancellationReason);
            $this->verified = false;
            $this->showCancelForm = false;
            $this->successMessage = 'Reservation cancelled successfully.';
        } catch (\InvalidArgumentException $exception) {
            $this->errorMessage = $exception->getMessage();
        } catch (Throwable $exception) {
            report($exception);
            $this->errorMessage = 'The reservation could not be cancelled. Refresh the page and try again.';
        }
    }

    public function reservation(): ?Reservation
    {
        if (! $this->verified || ! $this->reservationId) {
            return null;
        }

        return Reservation::query()
            ->with(['guest.address', 'details.facility.facilityType', 'details.discount', 'extraGuests', 'payments.modeOfPayment'])
            ->find($this->reservationId);
    }

    public function facilityTypes(GuestReservationManagementService $service)
    {
        return $service->facilityTypes();
    }

    public function rateTypesFor(int|string $id)
    {
        return app(GuestReservationManagementService::class)
            ->rateTypesForFacilityType($this->detailInt($id, 'facility_type_id'));
    }

    public function availableFacilitiesFor(int|string $id)
    {
        return app(GuestReservationManagementService::class)->availableFacilities(
            $this->detailInt($id, 'facility_type_id'),
            (string) ($this->details[$id]['rate_type'] ?? ''),
            (string) ($this->details[$id]['check_in_date'] ?? ''),
            (string) ($this->details[$id]['check_out_date'] ?? ''),
            (int) $id,
        );
    }

    public function quoteFor(int|string $id): ?array
    {
        try {
            return app(GuestReservationManagementService::class)->quotePreview(
                $this->detailInt($id, 'facility_id'),
                (string) ($this->details[$id]['rate_type'] ?? ''),
                (string) ($this->details[$id]['check_in_date'] ?? ''),
                (string) ($this->details[$id]['check_out_date'] ?? ''),
                0,
                $this->detailInt($id, 'discount_id'),
                (int) ($this->details[$id]['total_guest_count'] ?? 1),
            );
        } catch (Throwable) {
            return null;
        }
    }

    public function strictMaximumFor(int|string $id): ?int
    {
        $facilityId = $this->detailInt($id, 'facility_id');

        if ($facilityId === null) {
            return null;
        }

        try {
            return app(\App\Services\FacilityOccupancyService::class)->strictMaximum($facilityId);
        } catch (Throwable) {
            return null;
        }
    }

    public function occupancyPreviewFor(int|string $id): ?array
    {
        $facilityId = $this->detailInt($id, 'facility_id');

        if ($facilityId === null) {
            return null;
        }

        try {
            return app(\App\Services\FacilityOccupancyService::class)->forFacilityId(
                $facilityId,
                (int) ($this->details[$id]['total_guest_count'] ?? 1),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function detailInt(int|string $id, string $field): ?int
    {
        $value = $this->details[$id][$field] ?? null;

        return filled($value) ? (int) $value : null;
    }

    private function syncPaidExtraGuestRows(int|string $id): void
    {
        if (! isset($this->details[$id])) {
            return;
        }

        $rows = array_values((array) ($this->details[$id]['extra_guests'] ?? []));
        $occupancy = $this->occupancyPreviewFor($id);

        if ($this->detailInt($id, 'facility_id') !== null && $occupancy === null) {
            $this->details[$id]['extra_guests'] = $rows;
            return;
        }

        $required = (int) ($occupancy['paid_extra_guest_count'] ?? 0);

        for ($i = count($rows); $i < $required; $i++) {
            $rows[$i] = ['first_name' => '', 'middle_name' => '', 'last_name' => ''];
        }

        $this->details[$id]['extra_guests'] = array_slice($rows, 0, $required);
    }

    private function overlappingSelection(): ?string
    {
        $rows = array_values($this->details);

        for ($i = 0; $i < count($rows); $i++) {
            for ($j = $i + 1; $j < count($rows); $j++) {
                if (
                    (int) $rows[$i]['facility_id'] === (int) $rows[$j]['facility_id']
                    && $rows[$i]['check_in_date'] < $rows[$j]['check_out_date']
                    && $rows[$j]['check_in_date'] < $rows[$i]['check_out_date']
                ) {
                    return 'The same facility is selected more than once for overlapping dates. Choose a different facility for one of them.';
                }
            }
        }

        return null;
    }

    private function resetMessages(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
    }

    private function otpRequestRateLimitKey(): string
    {
        $identity = implode('|', [
            request()->ip(),
            Str::upper(Str::squish($this->referenceNumber)),
            Str::lower(trim($this->email)),
        ]);

        return 'reservation-management-otp:'.hash('sha256', $identity);
    }

    private function resetVerification(): void
    {
        $this->reservationId = null;
        $this->otpRequested = false;
        $this->verified = false;
        $this->debugOtp = null;
        $this->otp = '';
        $this->showUpdateForm = false;
        $this->showCancelForm = false;
    }

    private function ensureVerifiedReservation(): bool
    {
        if ($this->verified && $this->reservationId) {
            return true;
        }

        $this->errorMessage = 'Verify your reservation before making changes.';

        return false;
    }
};
?>

<section class="bg-public-cream text-public-ink">
    <header class="relative overflow-hidden bg-public-forest-deep py-12 text-white sm:py-16">
        <div class="absolute inset-0 opacity-20">
            <img
                src="{{ asset('images/olaer/resort-grounds.webp') }}"
                alt=""
                class="h-full w-full object-cover"
            >
        </div>
        <div class="absolute inset-0 bg-linear-to-r from-public-forest-deep via-public-forest-deep/90 to-public-forest/70"></div>

        <div class="relative mx-auto max-w-[90rem] px-4 sm:px-6 lg:px-8">
            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-public-sand">Guest self-service</p>
            <h1 class="mt-4 max-w-3xl font-public-display text-4xl leading-tight sm:text-5xl">Manage your reservation securely</h1>
            <p class="mt-4 max-w-2xl text-sm leading-7 text-white/70 sm:text-base">
                Verify ownership with the reference number, reservation email, and one-time code before changing an active unpaid reservation.
            </p>
        </div>
    </header>

    <div class="mx-auto max-w-6xl space-y-6 px-4 py-10 sm:px-6 sm:py-14 lg:px-8">
        <ol aria-label="Reservation management progress" class="grid gap-3 sm:grid-cols-3">
            @foreach ([
                ['label' => 'Find reservation', 'active' => ! $otpRequested && ! $verified, 'complete' => $otpRequested || $verified],
                ['label' => 'Verify one-time code', 'active' => $otpRequested && ! $verified, 'complete' => $verified],
                ['label' => 'Review and manage', 'active' => $verified, 'complete' => false],
            ] as $step)
                <li
                    wire:key="reservation-manage-step-{{ Str::slug($step['label']) }}"
                    class="rounded-2xl border px-4 py-3 text-sm font-semibold {{ $step['active'] ? 'border-public-spring bg-public-spring-light text-public-forest' : ($step['complete'] ? 'border-public-forest/10 bg-white text-public-forest' : 'border-public-forest/10 bg-white/60 text-public-muted') }}"
                    @if ($step['active']) aria-current="step" @endif
                >
                    {{ $step['complete'] ? '✓ ' : '' }}{{ $step['label'] }}
                </li>
            @endforeach
        </ol>

        @if ($successMessage)
            <div role="status" aria-live="polite" class="rounded-2xl border border-green-200 bg-green-50 px-5 py-4 text-sm text-green-800 dark:border-green-900/50 dark:bg-green-950/30 dark:text-green-200">{{ $successMessage }}</div>
        @endif

        @if ($errorMessage)
            <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-200">{{ $errorMessage }}</div>
        @endif

        @if (! $verified)
            <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div class="rounded-3xl bg-white p-6 shadow-public-card dark:bg-zinc-900 sm:p-8">
                    @if (! $otpRequested)
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-public-terracotta">Step 1 of 3</p>
                            <h2 class="mt-2 font-public-display text-3xl text-public-forest dark:text-emerald-200">Find your reservation</h2>
                            <p class="mt-2 text-sm leading-6 text-public-muted dark:text-zinc-300">
                                Enter the exact reference number and email from your reservation confirmation.
                            </p>
                        </div>

                        <form wire:submit="requestOtp" class="mt-6 space-y-5">
                            <div class="grid gap-5 md:grid-cols-2">
                                <flux:field>
                                    <flux:label>Reservation reference</flux:label>
                                    <flux:input
                                        wire:model.live="referenceNumber"
                                        autocomplete="off"
                                        placeholder="Enter your reservation reference"
                                    />
                                    <flux:error name="referenceNumber" />
                                </flux:field>

                                <flux:field>
                                    <flux:label>Reservation email</flux:label>
                                    <flux:input
                                        type="email"
                                        wire:model.live="email"
                                        autocomplete="email"
                                        placeholder="guest@example.com"
                                    />
                                    <flux:error name="email" />
                                </flux:field>
                            </div>

                            <flux:button
                                type="submit"
                                variant="primary"
                                wire:loading.attr="disabled"
                                wire:target="requestOtp"
                            >
                                <span wire:loading.remove wire:target="requestOtp">Send one-time code</span>
                                <span wire:loading wire:target="requestOtp">Sending…</span>
                            </flux:button>
                        </form>
                    @else
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-public-terracotta">Step 2 of 3</p>
                            <h2 class="mt-2 font-public-display text-3xl text-public-forest dark:text-emerald-200">Enter your one-time code</h2>
                            <p class="mt-2 text-sm leading-6 text-public-muted dark:text-zinc-300">
                                We sent a six-digit code to {{ Str::mask($email, '*', 2, max(0, Str::length(Str::before($email, '@')) - 2)) }}. It expires in 10 minutes.
                            </p>
                        </div>

                        @if ($debugOtp)
                            <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-100">
                                Local testing code: <strong>{{ $debugOtp }}</strong>
                            </div>
                        @endif

                        <form wire:submit="verifyOtp" class="mt-6 space-y-5">
                            <flux:field>
                                <flux:label>Six-digit code</flux:label>
                                <flux:input
                                    wire:model.live="otp"
                                    inputmode="numeric"
                                    autocomplete="one-time-code"
                                    maxlength="6"
                                    class="max-w-xs"
                                    placeholder="000000"
                                />
                                <flux:error name="otp" />
                            </flux:field>

                            <div class="flex flex-wrap gap-3">
                                <flux:button
                                    type="submit"
                                    variant="primary"
                                    wire:loading.attr="disabled"
                                    wire:target="verifyOtp"
                                >
                                    <span wire:loading.remove wire:target="verifyOtp">Verify reservation</span>
                                    <span wire:loading wire:target="verifyOtp">Verifying…</span>
                                </flux:button>
                                <flux:button
                                    type="button"
                                    variant="ghost"
                                    wire:click="requestOtp"
                                    wire:loading.attr="disabled"
                                    wire:target="requestOtp"
                                >
                                    Send a new code
                                </flux:button>
                            </div>
                        </form>
                    @endif
                </div>

                <aside class="rounded-3xl bg-public-forest p-6 text-white shadow-public-card">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-public-sand">Before you continue</p>
                    <h2 class="mt-3 font-public-display text-2xl">Online changes have limits</h2>
                    <ul class="mt-5 space-y-4 text-sm leading-6 text-white/70">
                        <li>Only active reservations without verified payment can be changed online.</li>
                        <li>Availability and pricing are checked again when you save an update.</li>
                        <li>For paid or converted reservations, contact the resort cashier for assistance.</li>
                    </ul>
                    <a
                        href="{{ route('guest.confirmations.lookup') }}"
                        wire:navigate
                        class="mt-6 inline-flex text-sm font-semibold text-public-sand hover:text-white"
                    >
                        Only need a confirmation? Find it here
                    </a>
                </aside>
            </div>
        @endif

    @if ($verified)
        @php($reservation = $this->reservation())

        @if ($reservation)
            <article class="rounded-3xl bg-white p-6 shadow-public-card dark:bg-zinc-900 sm:p-8">
                <div class="flex flex-col justify-between gap-5 md:flex-row md:items-start">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-public-terracotta">Verified reservation</p>
                        <h2 class="mt-2 break-all font-public-display text-3xl text-public-forest dark:text-emerald-200">{{ $reservation->r_ref_no }}</h2>
                        <p class="mt-2 text-sm text-public-muted dark:text-zinc-300">
                            {{ $reservation->guest?->first_name }} {{ $reservation->guest?->last_name }} · {{ $reservation->guest?->email }}
                        </p>
                        <div class="mt-3"><x-status-badge :status="$reservation->status" /></div>
                    </div>
                    <div class="text-left md:text-right">
                        <p class="text-sm text-public-muted dark:text-zinc-400">Estimated total</p>
                        <p class="mt-1 text-3xl font-bold text-public-forest dark:text-emerald-200">₱{{ number_format((float) $reservation->total_price, 2) }}</p>
                        <p class="mt-1 text-sm text-public-muted dark:text-zinc-400"> Balance: ₱{{ number_format((float) $reservation->amount_due, 2) }}</p>
                    </div>
                </div>

                <div class="mt-7 overflow-x-auto rounded-2xl border border-public-forest/10">
                    <table class="min-w-[46rem] w-full text-left text-sm">
                        <thead class="bg-public-cream-muted text-public-muted dark:bg-zinc-800 dark:text-zinc-300">
                            <tr>
                                <th class="px-4 py-3 font-semibold">Facility</th>
                                <th class="px-4 py-3 font-semibold">Type</th>
                                <th class="px-4 py-3 font-semibold">Rate</th>
                                <th class="px-4 py-3 font-semibold">Check-in</th>
                                <th class="px-4 py-3 font-semibold">Check-out</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reservation->details as $detail)
                                <tr wire:key="managed-reservation-detail-{{ $detail->reservation_details_id }}" class="border-t border-public-forest/10">
                                    <td class="px-4 py-3 font-semibold">{{ $detail->facility?->facility_name }}</td>
                                    <td class="px-4 py-3">{{ $detail->facility?->facilityType?->facility_type }}</td>
                                    <td class="px-4 py-3">{{ $detail->rate_type }}</td>
                                    <td class="px-4 py-3">{{ optional($detail->check_in_date)->format('M d, Y') }}</td>
                                    <td class="px-4 py-3">{{ optional($detail->check_out_date)->format('M d, Y') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex flex-wrap gap-3">
                    <flux:button
                        variant="primary"
                        wire:click="prepareUpdate"
                        wire:loading.attr="disabled"
                        wire:target="prepareUpdate"
                    >
                        Update reservation
                    </flux:button>
                    <flux:button
                        variant="danger"
                        wire:click="prepareCancel"
                        wire:loading.attr="disabled"
                        wire:target="prepareCancel"
                    >
                        Cancel reservation
                    </flux:button>
                </div>
            </article>
        @endif

        @if ($showUpdateForm)
            @php($managedReservation = $reservation ?? $this->reservation())
            @php($facilityTypes = $this->facilityTypes(app(\App\Services\GuestReservationManagementService::class)))
            @php($quotes = collect($details)->map(fn ($row, $detailId) => $this->quoteFor($detailId))->all())
            @php($allQuoted = $quotes !== [] && collect($quotes)->every(fn ($quote) => $quote !== null))

            <form wire:submit="updateReservation" novalidate class="rounded-3xl bg-white p-6 shadow-public-card dark:bg-zinc-900 sm:p-8">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-public-terracotta">Reservation update</p>
                <h2 class="mt-2 font-public-display text-3xl text-public-forest dark:text-emerald-200">Choose new visit details</h2>
                <p class="mt-2 text-sm leading-6 text-public-muted dark:text-zinc-300">
                    @if (count($details) > 1)
                        This reservation has {{ count($details) }} facilities. Change any of them below; facilities you leave as they are keep their current details. Availability, occupancy, discounts, and the estimated total will be checked again before the update is saved.
                    @else
                        Availability, occupancy, discounts, and the estimated total will be checked again before the update is saved.
                    @endif
                </p>

                @foreach ($details as $detailId => $row)
                    @php($original = $managedReservation?->details->firstWhere('reservation_details_id', $detailId))
                    @php($rateTypes = $this->rateTypesFor($detailId))
                    @php($availableFacilities = $this->availableFacilitiesFor($detailId))
                    @php($occupancy = $this->occupancyPreviewFor($detailId))
                    @php($quote = $quotes[$detailId] ?? null)

                    <div wire:key="manage-detail-{{ $detailId }}" class="mt-6 rounded-3xl border border-public-forest/10 p-5 sm:p-6">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h3 class="font-public-display text-xl text-public-forest dark:text-emerald-200">
                                @if (count($details) > 1)
                                    Facility {{ $loop->iteration }} of {{ $loop->count }}
                                @else
                                    Facility
                                @endif
                            </h3>
                            <p class="text-sm text-public-muted dark:text-zinc-300">Currently {{ $original?->facility?->facility_name }} ({{ $original?->facility?->facilityType?->facility_type }})</p>
                        </div>

                        <div class="mt-5 grid gap-5 md:grid-cols-2">
                            <flux:field>
                                <flux:label>Check-in date</flux:label>
                                <flux:input type="date" wire:model.live="details.{{ $detailId }}.check_in_date" />
                                <flux:error name="details.{{ $detailId }}.check_in_date" />
                            </flux:field>
                            <flux:field>
                                <flux:label>Check-out date</flux:label>
                                <flux:input type="date" wire:model.live="details.{{ $detailId }}.check_out_date" />
                                <flux:error name="details.{{ $detailId }}.check_out_date" />
                            </flux:field>
                            <flux:field>
                                <flux:label>Facility type</flux:label>
                                <flux:select wire:model.live="details.{{ $detailId }}.facility_type_id">
                                    <option value="">Select type</option>
                                    @foreach ($facilityTypes as $type)
                                        <option wire:key="manage-{{ $detailId }}-facility-type-{{ $type->facility_type_id }}" value="{{ $type->facility_type_id }}">{{ $type->facility_type }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="details.{{ $detailId }}.facility_type_id" />
                            </flux:field>
                            <flux:field>
                                <flux:label>Rate type</flux:label>
                                <flux:select wire:model.live="details.{{ $detailId }}.rate_type">
                                    <option value="">Select rate</option>
                                    @foreach ($rateTypes as $rate)
                                        <option wire:key="manage-{{ $detailId }}-rate-type-{{ Str::slug($rate) }}" value="{{ $rate }}">{{ $rate }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="details.{{ $detailId }}.rate_type" />
                            </flux:field>
                        </div>

                        <fieldset class="mt-6">
                            <legend class="text-sm font-semibold text-public-forest dark:text-white">Available facility</legend>
                            <div class="mt-3 grid gap-3 md:grid-cols-3">
                                @forelse ($availableFacilities as $facility)
                                    <label wire:key="manage-available-facility-{{ $detailId }}-{{ $facility->facility_id }}" class="cursor-pointer rounded-2xl border p-4 transition {{ (int) ($row['facility_id'] ?? 0) === (int) $facility->facility_id ? 'border-public-spring bg-public-spring-light ring-1 ring-public-spring' : 'border-public-forest/10 hover:border-public-spring' }}">
                                        <input type="radio" name="manage-facility-{{ $detailId }}" wire:model.live="details.{{ $detailId }}.facility_id" value="{{ $facility->facility_id }}" class="sr-only">
                                        <p class="font-semibold text-public-forest dark:text-white">{{ $facility->facility_name }}</p>
                                        <p class="mt-1 text-sm text-public-muted dark:text-zinc-300">{{ $facility->facilityProduct?->strict_maximum ? 'Maximum' : 'Recommended' }} {{ $facility->facilityProduct?->strict_maximum ?? $facility->facilityProduct?->suggested_maximum ?? $facility->capacity }} guests</p>
                                    </label>
                                @empty
                                    <p class="text-sm text-public-muted dark:text-zinc-300">No available facilities match the current date and rate selection.</p>
                                @endforelse
                            </div>
                            @error('details.'.$detailId.'.facility_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </fieldset>

                        <div class="mt-6 max-w-xl">
                            <flux:field>
                                <flux:label>Total guests</flux:label>
                                <flux:input type="number" min="1" max="{{ $this->strictMaximumFor($detailId) }}" wire:model.live="details.{{ $detailId }}.total_guest_count" />
                                <flux:error name="details.{{ $detailId }}.total_guest_count" />
                            </flux:field>
                            <p class="mt-2 text-xs leading-5 text-public-muted dark:text-zinc-400">
                                Total guests includes the primary guest. Rooms include 4 guests and enforce the maximum shown below. Cottage and function-hall capacities are recommendations only and never create extra-guest charges.
                            </p>
                            @if ($occupancy)
                                <p class="mt-2 text-xs font-medium text-public-forest dark:text-emerald-200">
                                    @if ($occupancy['strict_maximum'])
                                        Maximum {{ $occupancy['strict_maximum'] }} guests · Included {{ $occupancy['included_guest_count'] }} · Paid room extras {{ $occupancy['paid_extra_guest_count'] }}
                                    @else
                                        Recommended capacity: {{ $occupancy['suggested_minimum'] ? $occupancy['suggested_minimum'].'–' : '' }}{{ $occupancy['suggested_maximum'] }} guests (informational)
                                    @endif
                                </p>
                            @endif
                        </div>

                        @if (($row['extra_guests'] ?? []) !== [])
                            <div class="mt-6 space-y-3">
                                <h4 class="font-semibold text-public-forest dark:text-white">Paid extra guest names</h4>
                                @foreach ($row['extra_guests'] as $index => $guest)
                                    <div wire:key="manage-extra-guest-{{ $detailId }}-{{ $index }}" class="grid gap-3 rounded-2xl border border-public-forest/10 p-4 md:grid-cols-3">
                                        <flux:input wire:model.live="details.{{ $detailId }}.extra_guests.{{ $index }}.first_name" aria-label="Extra guest {{ $index + 1 }} first name" placeholder="First name" />
                                        <flux:input wire:model.live="details.{{ $detailId }}.extra_guests.{{ $index }}.middle_name" aria-label="Extra guest {{ $index + 1 }} middle name" placeholder="Middle name" />
                                        <flux:input wire:model.live="details.{{ $detailId }}.extra_guests.{{ $index }}.last_name" aria-label="Extra guest {{ $index + 1 }} last name" placeholder="Last name" />
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($quote && count($details) > 1)
                            <p class="mt-5 text-sm font-medium text-public-forest dark:text-emerald-200">This facility: ₱{{ number_format((float) $quote['total_price'], 2) }}</p>
                        @endif
                    </div>
                @endforeach

                @if ($allQuoted)
                    <div class="mt-6 rounded-2xl border border-public-spring/30 bg-public-spring-light p-5 text-public-forest">
                        <p class="text-sm font-medium">Updated estimated total</p>
                        <p class="mt-1 text-3xl font-bold">₱{{ number_format((float) app(\App\Services\DecimalMoneyService::class)->add(...collect($quotes)->map(fn ($quote) => (string) $quote['total_price'])->values()->all()), 2) }}</p>
                        @if (count($details) > 1)
                            <p class="mt-1 text-xs">Covers all {{ count($details) }} facilities in this reservation.</p>
                        @endif
                    </div>
                @endif

                <div class="mt-6 flex flex-wrap gap-3">
                    <flux:button
                        type="submit"
                        variant="primary"
                        wire:loading.attr="disabled"
                        wire:target="updateReservation"
                    >
                        <span wire:loading.remove wire:target="updateReservation">Save update</span>
                        <span wire:loading wire:target="updateReservation">Saving…</span>
                    </flux:button>
                    <flux:button type="button" variant="ghost" wire:click="$set('showUpdateForm', false)">Cancel edit</flux:button>
                </div>
            </form>
        @endif

        @if ($showCancelForm)
            <form wire:submit="cancelReservation" class="rounded-3xl border border-red-200 bg-white p-6 shadow-public-card dark:border-red-900/50 dark:bg-zinc-900 sm:p-8">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-red-700 dark:text-red-300">Permanent action</p>
                <h2 class="mt-2 font-public-display text-3xl text-public-forest dark:text-white">Cancel this reservation?</h2>
                <p class="mt-2 text-sm leading-6 text-public-muted dark:text-zinc-300">Cancellation releases the held facility. It is allowed only for an active reservation without verified payment.</p>

                <div class="mt-6">
                    <flux:field>
                        <flux:label>Reason for cancellation</flux:label>
                        <flux:textarea wire:model.live="cancellationReason" rows="3" placeholder="Tell us why you need to cancel" />
                        <flux:error name="cancellationReason" />
                    </flux:field>
                </div>

                <div class="mt-6 flex flex-wrap gap-3">
                    <flux:button
                        type="submit"
                        variant="danger"
                        wire:loading.attr="disabled"
                        wire:target="cancelReservation"
                    >
                        <span wire:loading.remove wire:target="cancelReservation">Confirm cancellation</span>
                        <span wire:loading wire:target="cancelReservation">Cancelling…</span>
                    </flux:button>
                    <flux:button type="button" variant="ghost" wire:click="$set('showCancelForm', false)">Keep reservation</flux:button>
                </div>
            </form>
        @endif
    @endif
    </div>
</section>