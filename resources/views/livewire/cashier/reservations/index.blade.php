<?php

use App\Models\Address;
use App\Models\Discount;
use App\Models\Facility;
use App\Models\FacilityPrice;
use App\Models\FacilityType;
use App\Models\Guest;
use App\Models\Reservation;
use App\Services\FacilityAvailabilityService;
use App\Services\ReservationQuoteService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Reservation Management - Olaer Spring Resort')] class extends Component
{
    public string $search = '';
    public string $statusFilter = 'Active';
    public string $sortField = 'reservation_id';
    public string $sortDirection = 'desc';

    public bool $showCreateForm = false;

    public string $guestFirstName = '';
    public string $guestMiddleName = '';
    public string $guestLastName = '';
    public string $guestContactNo = '';
    public string $guestEmail = '';
    public string $addressPurok = '';
    public string $addressBarangay = '';
    public string $addressCity = '';
    public string $addressProvince = '';

    public string $facilityTypeId = '';
    public string $facilityId = '';
    public string $rateType = '';
    public string $checkInDate = '';
    public string $checkOutDate = '';
    public string $discountId = '';

    public array $extraGuests = [];

    public ?int $rescheduleReservationId = null;
    public string $rescheduleFacilityId = '';
    public string $rescheduleRateType = '';
    public string $rescheduleCheckInDate = '';
    public string $rescheduleCheckOutDate = '';
    public string $rescheduleDiscountId = '';

    public ?int $cancelReservationId = null;
    public string $cancellationReason = '';

    public function mount(): void
    {
        $today = now()->toDateString();

        $this->addressCity = 'Tacurong City';
        $this->addressProvince = 'Sultan Kudarat';
        $this->checkInDate = $today;
        $this->checkOutDate = $today;
        $this->rescheduleCheckInDate = $today;
        $this->rescheduleCheckOutDate = $today;
    }

    public function reservations(): Collection
    {
        $query = Reservation::query()
            ->with([
                'guest.address',
                'details.facility.facilityType',
                'details.discount',
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

        return $query->orderBy($sortField, $sortDirection)->get();
    }

    public function facilityTypes(): Collection
    {
        return FacilityType::query()->orderBy('facility_type')->get();
    }

    public function rateTypes(): Collection
    {
        if ($this->facilityTypeId === '') {
            return collect();
        }

        return FacilityPrice::query()
            ->select('tbl_facility_price.rate_type')
            ->join('tbl_facility', 'tbl_facility_price.facility_id', '=', 'tbl_facility.facility_id')
            ->where('tbl_facility.facility_type_id', (int) $this->facilityTypeId)
            ->distinct()
            ->orderBy('tbl_facility_price.rate_type')
            ->pluck('tbl_facility_price.rate_type');
    }

    public function availableFacilities(): Collection
    {
        if ($this->facilityTypeId === '' || $this->rateType === '' || $this->checkInDate === '' || $this->checkOutDate === '') {
            return collect();
        }

        $availability = app(FacilityAvailabilityService::class);

        $facilities = Facility::query()
            ->with(['facilityType', 'prices'])
            ->where('facility_type_id', (int) $this->facilityTypeId)
            ->where('facility_status', 'Available')
            ->whereHas('prices', function ($query): void {
                $query->where('rate_type', $this->rateType);
            })
            ->orderBy('facility_name')
            ->get();

        $available = collect();

        foreach ($facilities as $facility) {
            try {
                if ($availability->isAvailable((int) $facility->facility_id, $this->checkInDate, $this->checkOutDate)) {
                    $available->push($facility);
                }
            } catch (Throwable) {
                return collect();
            }
        }

        return $available;
    }

    public function discounts(): Collection
    {
        if ($this->facilityTypeId === '') {
            return collect();
        }

        $facilityType = FacilityType::query()->find((int) $this->facilityTypeId);

        if (! $facilityType) {
            return collect();
        }

        $field = match ($facilityType->facility_type) {
            'Cottage' => 'app_to_cottage',
            'Room' => 'app_to_room',
            'Function Hall' => 'app_to_function_hall',
            default => null,
        };

        if (! $field) {
            return collect();
        }

        $discounts = Discount::query()
            ->where('status', 'Active')
            ->where($field, true)
            ->orderBy('discount_name')
            ->get();

        $valid = collect();
        $now = now();

        foreach ($discounts as $discount) {
            if ($discount->discount_start && $now->lt($discount->discount_start)) {
                continue;
            }

            if ($discount->discount_end && $now->gt($discount->discount_end)) {
                continue;
            }

            $valid->push($discount);
        }

        return $valid;
    }

    public function quote(): array
    {
        if ($this->facilityId === '' || $this->rateType === '' || $this->checkInDate === '' || $this->checkOutDate === '') {
            return $this->emptyQuote();
        }

        try {
            return app(ReservationQuoteService::class)->quote(
                facilityId: (int) $this->facilityId,
                rateType: $this->rateType,
                checkInDate: $this->checkInDate,
                checkOutDate: $this->checkOutDate,
                discountId: $this->discountId !== '' ? (int) $this->discountId : null,
                extraGuestCount: count($this->extraGuests),
            );
        } catch (Throwable) {
            return $this->emptyQuote();
        }
    }

    public function rescheduleAvailableFacilities(): Collection
    {
        if (! $this->rescheduleReservationId) {
            return collect();
        }

        $reservation = Reservation::query()
            ->with('details.facility')
            ->find($this->rescheduleReservationId);

        $currentDetail = $reservation?->details->first();

        if (! $currentDetail || ! $currentDetail->facility) {
            return collect();
        }

        if ($this->rescheduleRateType === '' || $this->rescheduleCheckInDate === '' || $this->rescheduleCheckOutDate === '') {
            return collect();
        }

        $availability = app(FacilityAvailabilityService::class);

        $facilities = Facility::query()
            ->with(['facilityType', 'prices'])
            ->where('facility_type_id', $currentDetail->facility->facility_type_id)
            ->where('facility_status', 'Available')
            ->whereHas('prices', function ($query): void {
                $query->where('rate_type', $this->rescheduleRateType);
            })
            ->orderBy('facility_name')
            ->get();

        $available = collect();

        foreach ($facilities as $facility) {
            try {
                if ($availability->isAvailable(
                    (int) $facility->facility_id,
                    $this->rescheduleCheckInDate,
                    $this->rescheduleCheckOutDate,
                    $this->rescheduleReservationId,
                )) {
                    $available->push($facility);
                }
            } catch (Throwable) {
                return collect();
            }
        }

        return $available;
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
            return;
        }

        $this->sortField = $field;
        $this->sortDirection = 'asc';
    }

    public function updatedFacilityTypeId(): void
    {
        $this->facilityId = '';
        $this->rateType = '';
        $this->discountId = '';
    }

    public function updatedRateType(): void
    {
        $this->facilityId = '';
    }

    public function addExtraGuest(): void
    {
        $this->extraGuests[] = [
            'first_name' => '',
            'middle_name' => '',
            'last_name' => '',
        ];
    }

    public function removeExtraGuest(int $index): void
    {
        if (! array_key_exists($index, $this->extraGuests)) {
            return;
        }

        unset($this->extraGuests[$index]);
        $this->extraGuests = array_values($this->extraGuests);
    }

    public function createReservation(): void
    {
        $validated = $this->validate([
            'guestFirstName' => ['required', 'string', 'max:50'],
            'guestMiddleName' => ['nullable', 'string', 'max:50'],
            'guestLastName' => ['required', 'string', 'max:50'],
            'guestContactNo' => ['required', 'regex:/^09[0-9]{9}$/'],
            'guestEmail' => ['nullable', 'email', 'max:50'],
            'addressPurok' => ['nullable', 'string', 'max:50'],
            'addressBarangay' => ['nullable', 'string', 'max:50'],
            'addressCity' => ['required', 'string', 'max:50'],
            'addressProvince' => ['required', 'string', 'max:50'],
            'facilityTypeId' => ['required', 'exists:tbl_facility_type,facility_type_id'],
            'facilityId' => ['required', 'exists:tbl_facility,facility_id'],
            'rateType' => ['required', 'string', 'max:20'],
            'checkInDate' => ['required', 'date'],
            'checkOutDate' => ['required', 'date', 'after_or_equal:checkInDate'],
            'discountId' => ['nullable', 'exists:tbl_discount,discount_id'],
            'extraGuests' => ['array'],
            'extraGuests.*.first_name' => ['required_with:extraGuests.*.last_name', 'nullable', 'string', 'max:50'],
            'extraGuests.*.middle_name' => ['nullable', 'string', 'max:50'],
            'extraGuests.*.last_name' => ['required_with:extraGuests.*.first_name', 'nullable', 'string', 'max:50'],
        ], [
            'guestContactNo.regex' => 'Contact number must be an 11-digit Philippine mobile number starting with 09.',
        ]);

        if (! app(FacilityAvailabilityService::class)->isAvailable((int) $validated['facilityId'], $validated['checkInDate'], $validated['checkOutDate'])) {
            $this->addError('facilityId', 'Selected facility is no longer available for the chosen date range.');
            return;
        }

        try {
            $quote = app(ReservationQuoteService::class)->quote(
                facilityId: (int) $validated['facilityId'],
                rateType: $validated['rateType'],
                checkInDate: $validated['checkInDate'],
                checkOutDate: $validated['checkOutDate'],
                discountId: $this->discountId !== '' ? (int) $this->discountId : null,
                extraGuestCount: count($this->extraGuests),
            );
        } catch (Throwable $exception) {
            $this->addError('facilityId', $exception->getMessage());
            return;
        }

        DB::transaction(function () use ($validated, $quote): void {
            $address = Address::query()->firstOrCreate([
                'purok' => filled($validated['addressPurok']) ? trim($validated['addressPurok']) : null,
                'barangay' => filled($validated['addressBarangay']) ? trim($validated['addressBarangay']) : null,
                'city' => trim($validated['addressCity']),
                'province' => trim($validated['addressProvince']),
            ]);

            $guest = Guest::query()->create([
                'first_name' => trim($validated['guestFirstName']),
                'middle_name' => filled($validated['guestMiddleName']) ? trim($validated['guestMiddleName']) : null,
                'last_name' => trim($validated['guestLastName']),
                'contact_no' => trim($validated['guestContactNo']),
                'email' => filled($validated['guestEmail']) ? trim($validated['guestEmail']) : null,
                'address_id' => $address->address_id,
            ]);

            $reservation = Reservation::query()->create([
                'r_ref_no' => $this->generateReservationReference(),
                'guest_id' => $guest->guest_id,
                'reservation_date' => now()->toDateString(),
                'total_price' => $quote['total_price'],
                'amount_due' => $quote['amount_due'],
                'no_of_extra_guests' => count($this->extraGuests),
                'user_id' => auth()->id(),
                'status' => 'Active',
            ]);

            $reservation->details()->create([
                'facility_id' => (int) $validated['facilityId'],
                'rate_type' => $validated['rateType'],
                'check_in_date' => $validated['checkInDate'],
                'check_out_date' => $validated['checkOutDate'],
                'discount_id' => $this->discountId !== '' ? (int) $this->discountId : null,
            ]);

            foreach ($this->extraGuests as $extraGuest) {
                if (! filled($extraGuest['first_name'] ?? null) && ! filled($extraGuest['last_name'] ?? null)) {
                    continue;
                }

                $reservation->extraGuests()->create([
                    'first_name' => trim((string) $extraGuest['first_name']),
                    'middle_name' => filled($extraGuest['middle_name'] ?? null) ? trim((string) $extraGuest['middle_name']) : null,
                    'last_name' => trim((string) $extraGuest['last_name']),
                ]);
            }
        });

        $this->resetCreateForm();
        session()->flash('success', 'Reservation created successfully. The guest can use the reservation reference during check-in or payment.');
    }

    public function beginReschedule(int $reservationId): void
    {
        $reservation = Reservation::query()
            ->with('details.facility.facilityType')
            ->findOrFail($reservationId);

        if ($reservation->status !== 'Active') {
            session()->flash('error', 'Only active reservations can be rescheduled.');
            return;
        }

        $detail = $reservation->details->first();

        if (! $detail) {
            session()->flash('error', 'Reservation has no facility detail to reschedule.');
            return;
        }

        $this->rescheduleReservationId = $reservation->reservation_id;
        $this->rescheduleFacilityId = (string) $detail->facility_id;
        $this->rescheduleRateType = $detail->rate_type;
        $this->rescheduleCheckInDate = $detail->check_in_date->toDateString();
        $this->rescheduleCheckOutDate = $detail->check_out_date->toDateString();
        $this->rescheduleDiscountId = $detail->discount_id ? (string) $detail->discount_id : '';
        $this->cancelReservationId = null;
        $this->cancellationReason = '';
        $this->resetValidation();
    }

    public function saveReschedule(): void
    {
        $validated = $this->validate([
            'rescheduleReservationId' => ['required', 'exists:tbl_reservation,reservation_id'],
            'rescheduleFacilityId' => ['required', 'exists:tbl_facility,facility_id'],
            'rescheduleRateType' => ['required', 'string', 'max:20'],
            'rescheduleCheckInDate' => ['required', 'date'],
            'rescheduleCheckOutDate' => ['required', 'date', 'after_or_equal:rescheduleCheckInDate'],
            'rescheduleDiscountId' => ['nullable', 'exists:tbl_discount,discount_id'],
        ]);

        $reservation = Reservation::query()
            ->with(['details', 'payments'])
            ->findOrFail((int) $validated['rescheduleReservationId']);

        if ($reservation->status !== 'Active') {
            session()->flash('error', 'Only active reservations can be rescheduled.');
            return;
        }

        if (! app(FacilityAvailabilityService::class)->isAvailable(
            (int) $validated['rescheduleFacilityId'],
            $validated['rescheduleCheckInDate'],
            $validated['rescheduleCheckOutDate'],
            $reservation->reservation_id,
        )) {
            $this->addError('rescheduleFacilityId', 'Selected facility is not available for the new date range.');
            return;
        }

        try {
            $quote = app(ReservationQuoteService::class)->quote(
                facilityId: (int) $validated['rescheduleFacilityId'],
                rateType: $validated['rescheduleRateType'],
                checkInDate: $validated['rescheduleCheckInDate'],
                checkOutDate: $validated['rescheduleCheckOutDate'],
                discountId: $this->rescheduleDiscountId !== '' ? (int) $this->rescheduleDiscountId : null,
                extraGuestCount: (int) $reservation->no_of_extra_guests,
            );
        } catch (Throwable $exception) {
            $this->addError('rescheduleFacilityId', $exception->getMessage());
            return;
        }

        DB::transaction(function () use ($reservation, $validated, $quote): void {
            $paidAmount = (float) $reservation->payments
                ->where('payment_status', 'Verified')
                ->sum('amount_paid');

            $reservation->update([
                'total_price' => $quote['total_price'],
                'amount_due' => max($quote['total_price'] - $paidAmount, 0),
            ]);

            $detail = $reservation->details->first();

            if ($detail) {
                $detail->update([
                    'facility_id' => (int) $validated['rescheduleFacilityId'],
                    'rate_type' => $validated['rescheduleRateType'],
                    'check_in_date' => $validated['rescheduleCheckInDate'],
                    'check_out_date' => $validated['rescheduleCheckOutDate'],
                    'discount_id' => $this->rescheduleDiscountId !== '' ? (int) $this->rescheduleDiscountId : null,
                ]);
            }
        });

        $this->cancelReschedule();
        session()->flash('success', 'Reservation rescheduled successfully.');
    }

    public function cancelReschedule(): void
    {
        $this->rescheduleReservationId = null;
        $this->rescheduleFacilityId = '';
        $this->rescheduleRateType = '';
        $this->rescheduleCheckInDate = now()->toDateString();
        $this->rescheduleCheckOutDate = now()->toDateString();
        $this->rescheduleDiscountId = '';
        $this->resetValidation();
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
        $this->rescheduleReservationId = null;
        $this->resetValidation();
    }

    public function cancelReservation(): void
    {
        $validated = $this->validate([
            'cancelReservationId' => ['required', 'exists:tbl_reservation,reservation_id'],
            'cancellationReason' => ['required', 'string', 'max:255'],
        ]);

        $reservation = Reservation::query()->findOrFail((int) $validated['cancelReservationId']);

        if ($reservation->status !== 'Active') {
            session()->flash('error', 'Only active reservations can be cancelled.');
            return;
        }

        $reservation->update([
            'status' => 'Cancelled',
            'amount_due' => 0,
            'cancellation_reason' => trim($validated['cancellationReason']),
            'cancelled_at' => now()->toDateString(),
        ]);

        $this->cancelReservationId = null;
        $this->cancellationReason = '';
        session()->flash('success', 'Reservation cancelled successfully.');
    }

    public function resetCreateForm(): void
    {
        $this->showCreateForm = false;
        $this->guestFirstName = '';
        $this->guestMiddleName = '';
        $this->guestLastName = '';
        $this->guestContactNo = '';
        $this->guestEmail = '';
        $this->addressPurok = '';
        $this->addressBarangay = '';
        $this->addressCity = 'Tacurong City';
        $this->addressProvince = 'Sultan Kudarat';
        $this->facilityTypeId = '';
        $this->facilityId = '';
        $this->rateType = '';
        $this->checkInDate = now()->toDateString();
        $this->checkOutDate = now()->toDateString();
        $this->discountId = '';
        $this->extraGuests = [];
        $this->resetValidation();
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

    public function amountPaid(Reservation $reservation): float
    {
        return (float) $reservation->payments
            ->where('payment_status', 'Verified')
            ->sum('amount_paid');
    }

    private function generateReservationReference(): string
    {
        do {
            $reference = 'R' . now()->format('ymd') . strtoupper(Str::random(5));
        } while (Reservation::query()->where('r_ref_no', $reference)->exists());

        return $reference;
    }

    private function emptyQuote(): array
    {
        return [
            'base_units' => 0,
            'base_price' => 0.00,
            'extra_guest_charge' => 0.00,
            'discount_amount' => 0.00,
            'total_price' => 0.00,
            'amount_due' => 0.00,
        ];
    }
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Reservation Management</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Cashier creates, views, reschedules, and cancels facility reservations.
            </p>
        </div>

        <div class="flex items-center gap-3">
            <flux:button type="button" variant="primary" wire:click="$set('showCreateForm', true)">
                New reservation
            </flux:button>
            <a href="{{ route('cashier.dashboard') }}" class="text-sm font-medium text-zinc-600 hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-white">
                Back to dashboard
            </a>
        </div>
    </div>

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

    @if ($showCreateForm)
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="font-semibold">Create reservation</h2>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        This records a temporary facility hold. Payment recording stays in the Payment module.
                    </p>
                </div>
                <flux:button type="button" variant="ghost" wire:click="resetCreateForm">Close</flux:button>
            </div>

            <form wire:submit="createReservation" class="mt-6 space-y-6">
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Guest representative</h3>
                    <div class="mt-3 grid gap-4 md:grid-cols-3">
                        <flux:input wire:model="guestFirstName" label="First name" />
                        <flux:input wire:model="guestMiddleName" label="Middle name" />
                        <flux:input wire:model="guestLastName" label="Last name" />
                    </div>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="guestContactNo" label="Contact no." placeholder="09XXXXXXXXX" />
                        <flux:input wire:model="guestEmail" label="Email (optional)" type="email" />
                    </div>
                    <div class="mt-4 grid gap-4 md:grid-cols-4">
                        <flux:input wire:model="addressPurok" label="Purok" />
                        <flux:input wire:model="addressBarangay" label="Barangay" />
                        <flux:input wire:model="addressCity" label="City" />
                        <flux:input wire:model="addressProvince" label="Province" />
                    </div>
                </div>

                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Facility and schedule</h3>
                    <div class="mt-3 grid gap-4 md:grid-cols-3">
                        <div class="space-y-2">
                            <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Facility type</label>
                            <select wire:model.live="facilityTypeId" class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950">
                                <option value="">Select type</option>
                                @foreach ($this->facilityTypes() as $type)
                                    <option value="{{ $type->facility_type_id }}">{{ $type->facility_type }}</option>
                                @endforeach
                            </select>
                            @error('facilityTypeId') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Rate type</label>
                            <select wire:model.live="rateType" class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950">
                                <option value="">Select rate</option>
                                @foreach ($this->rateTypes() as $rate)
                                    <option value="{{ $rate }}">{{ $rate }}</option>
                                @endforeach
                            </select>
                            @error('rateType') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Facility</label>
                            <select wire:model.live="facilityId" class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950">
                                <option value="">Select available facility</option>
                                @foreach ($this->availableFacilities() as $facility)
                                    <option value="{{ $facility->facility_id }}">{{ $facility->facility_name }} — {{ $facility->facility_size }} / {{ $facility->capacity }} pax</option>
                                @endforeach
                            </select>
                            @error('facilityId') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-3">
                        <flux:input wire:model.live="checkInDate" type="date" label="Check-in date" />
                        <flux:input wire:model.live="checkOutDate" type="date" label="Check-out date" />
                        <div class="space-y-2">
                            <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Discount</label>
                            <select wire:model.live="discountId" class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950">
                                <option value="">No discount</option>
                                @foreach ($this->discounts() as $discount)
                                    <option value="{{ $discount->discount_id }}">{{ $discount->discount_name }} ({{ number_format((float) $discount->discount_amount * 100, 0) }}%)</option>
                                @endforeach
                            </select>
                            @error('discountId') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="font-medium">Extra guests</h3>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Room extra guests are charged ₱100 each. Cottage/function hall extra guest billing is not added here.</p>
                        </div>
                        <flux:button type="button" wire:click="addExtraGuest">Add extra guest</flux:button>
                    </div>

                    <div class="mt-4 space-y-3">
                        @forelse ($extraGuests as $index => $extraGuest)
                            <div class="grid gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-800 md:grid-cols-4">
                                <flux:input wire:model="extraGuests.{{ $index }}.first_name" label="First name" />
                                <flux:input wire:model="extraGuests.{{ $index }}.middle_name" label="Middle name" />
                                <flux:input wire:model="extraGuests.{{ $index }}.last_name" label="Last name" />
                                <div class="flex items-end">
                                    <flux:button type="button" variant="danger" wire:click="removeExtraGuest({{ $index }})">Remove</flux:button>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">No extra guests added.</p>
                        @endforelse
                    </div>
                </div>

                @php($quote = $this->quote())
                <div class="grid gap-4 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950 md:grid-cols-4">
                    <div>
                        <p class="text-xs uppercase text-zinc-500">Base price</p>
                        <p class="font-semibold">₱{{ number_format((float) $quote['base_price'], 2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase text-zinc-500">Extra guest charge</p>
                        <p class="font-semibold">₱{{ number_format((float) $quote['extra_guest_charge'], 2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase text-zinc-500">Discount</p>
                        <p class="font-semibold">-₱{{ number_format((float) $quote['discount_amount'], 2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase text-zinc-500">Total / Amount due</p>
                        <p class="font-semibold">₱{{ number_format((float) $quote['total_price'], 2) }}</p>
                    </div>
                </div>

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="resetCreateForm">Cancel</flux:button>
                    <flux:button type="submit" variant="primary">Save reservation</flux:button>
                </div>
            </form>
        </section>
    @endif

    @if ($rescheduleReservationId)
        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm dark:border-amber-900/60 dark:bg-amber-950/30">
            <h2 class="font-semibold">Reschedule reservation #{{ $rescheduleReservationId }}</h2>
            <form wire:submit="saveReschedule" class="mt-4 grid gap-4 md:grid-cols-5">
                <flux:input wire:model.live="rescheduleCheckInDate" type="date" label="New check-in" />
                <flux:input wire:model.live="rescheduleCheckOutDate" type="date" label="New check-out" />
                <flux:input wire:model.live="rescheduleRateType" label="Rate type" />
                <div class="space-y-2 md:col-span-2">
                    <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">New facility</label>
                    <select wire:model.live="rescheduleFacilityId" class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950">
                        <option value="">Select available facility</option>
                        @foreach ($this->rescheduleAvailableFacilities() as $facility)
                            <option value="{{ $facility->facility_id }}">{{ $facility->facility_name }} — {{ $facility->facility_size }} / {{ $facility->capacity }} pax</option>
                        @endforeach
                    </select>
                    @error('rescheduleFacilityId') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-end gap-3 md:col-span-5">
                    <flux:button type="submit" variant="primary">Save reschedule</flux:button>
                    <flux:button type="button" variant="ghost" wire:click="cancelReschedule">Cancel</flux:button>
                </div>
            </form>
        </section>
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

    <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-col gap-4 border-b border-zinc-200 px-5 py-4 dark:border-zinc-800 md:flex-row md:items-end md:justify-between">
            <div>
                <h2 class="font-semibold">Reservation list</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Search by reference, guest name, contact number, or email.</p>
            </div>
            <div class="grid gap-3 md:grid-cols-2">
                <flux:input wire:model.live.debounce.300ms="search" label="Search" placeholder="R260618ABCDE / Guest name" />
                <div class="space-y-2">
                    <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Status</label>
                    <select wire:model.live="statusFilter" class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950">
                        <option value="Active">Active</option>
                        <option value="Cancelled">Cancelled</option>
                        <option value="Converted">Converted</option>
                        <option value="No-show">No-show</option>
                        <option value="All">All</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                <thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-950">
                    <tr>
                        <th class="px-4 py-3"><button wire:click="sortBy('r_ref_no')">Ref {{ $this->getSortIcon('r_ref_no') }}</button></th>
                        <th class="px-4 py-3">Guest</th>
                        <th class="px-4 py-3">Facility</th>
                        <th class="px-4 py-3">Schedule</th>
                        <th class="px-4 py-3"><button wire:click="sortBy('total_price')">Total {{ $this->getSortIcon('total_price') }}</button></th>
                        <th class="px-4 py-3">Paid</th>
                        <th class="px-4 py-3"><button wire:click="sortBy('amount_due')">Due {{ $this->getSortIcon('amount_due') }}</button></th>
                        <th class="px-4 py-3"><button wire:click="sortBy('status')">Status {{ $this->getSortIcon('status') }}</button></th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($this->reservations() as $reservation)
                        @php($detail = $reservation->details->first())
                        <tr>
                            <td class="px-4 py-3 font-medium">{{ $reservation->r_ref_no }}</td>
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ $this->fullName($reservation->guest) }}</div>
                                <div class="text-xs text-zinc-500">{{ $reservation->guest->contact_no }} {{ $reservation->guest->email ? '• '.$reservation->guest->email : '' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                @if ($detail && $detail->facility)
                                    <div class="font-medium">{{ $detail->facility->facility_name }}</div>
                                    <div class="text-xs text-zinc-500">{{ $detail->facility->facilityType?->facility_type }} • {{ $detail->rate_type }}</div>
                                @else
                                    <span class="text-zinc-500">No facility detail</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if ($detail)
                                    {{ $detail->check_in_date->format('M d, Y') }} → {{ $detail->check_out_date->format('M d, Y') }}
                                @endif
                            </td>
                            <td class="px-4 py-3">₱{{ number_format((float) $reservation->total_price, 2) }}</td>
                            <td class="px-4 py-3">₱{{ number_format($this->amountPaid($reservation), 2) }}</td>
                            <td class="px-4 py-3">₱{{ number_format((float) $reservation->amount_due, 2) }}</td>
                            <td class="px-4 py-3">{{ $reservation->status }}</td>
                            <td class="px-4 py-3 text-right">
                                @if ($reservation->status === 'Active')
                                    <div class="flex justify-end gap-2">
                                        <flux:button size="sm" type="button" wire:click="beginReschedule({{ $reservation->reservation_id }})">Reschedule</flux:button>
                                        <flux:button size="sm" variant="danger" type="button" wire:click="beginCancellation({{ $reservation->reservation_id }})">Cancel</flux:button>
                                    </div>
                                @else
                                    <span class="text-xs text-zinc-500">No action</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-zinc-500">No reservations found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
