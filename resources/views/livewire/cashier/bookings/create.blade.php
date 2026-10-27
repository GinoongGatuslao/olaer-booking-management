<?php

use App\FacilitySchedulePolicy;
use App\Models\FacilityProduct;
use App\Models\Amenity;
use App\Models\ModeOfPayment;
use App\Services\FacilityAssignmentService;
use App\Services\FacilityRequirementService;
use App\Services\EntranceSlipWorkflowService;
use App\Services\DecimalMoneyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Create Booking - Olaer Spring Resort')] class extends Component
{

    /**
     * Messages for the error summary. An empty required field is only highlighted
     * on the field itself, so it is left out of the summary.
     *
     * @return array<int, string>
     */
    public function bannerErrors(): array
    {
        $requiredFields = [
            'city',
            'contactNo',
            'email',
            'firstName',
            'groups.*.check_in_date',
            'groups.*.check_out_date',
            'groups.*.estimated_users',
            'groups.*.facility_product_id',
            'groups.*.quantity',
            'groups.*.rate_code',
            'lastName',
            'modeOfPaymentId',
            'partyCount',
            'province',
            'roomOccupants.*.*.first_name',
            'roomOccupants.*.*.last_name',
        ];

        $messages = [];

        foreach ($this->getErrorBag()->messages() as $key => $list) {
            $value = data_get($this, $key);
            $blank = $value === null || $value === '' || $value === [];
            $isRequiredField = collect($requiredFields)->contains(
                fn (string $pattern): bool => \Illuminate\Support\Str::is($pattern, $key),
            );

            if ($isRequiredField && $blank) {
                continue;
            }

            foreach ($list as $message) {
                $messages[] = $message;
            }
        }

        return array_values(array_unique($messages));
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
    public string $token = '';
    public int $partyCount = 1;
    public array $groups = [];
    public array $roomOccupants = [];
    public string $planTotal = '';
    public string $paymentAmount = '';
    public string $modeOfPaymentId = '';
    public string $referenceNumber = '';
    public bool $walkIn = false;
    public array $amenities = [];
    public int $adultCount = 1;
    public int $childrenCount = 0;
    public int $pwdScCount = 0;
    public int $maleCount = 1;
    public int $femaleCount = 0;

    public string $firstName = '';
    public string $middleName = '';
    public string $lastName = '';
    public string $email = '';
    public string $contactNo = '';
    public string $province = '';
    public string $city = '';
    public string $barangay = '';
    public string $purok = '';

    public function mount(FacilityRequirementService $requirements): void
    {
        $sessionKey = 'cashier.booking_plan_token';
        $storedToken = (string) session($sessionKey, '');

        try {
            $intent = $storedToken !== ''
                ? $requirements->ownedIntent($storedToken, session()->getId())
                : null;
        } catch (\Throwable) {
            $intent = null;
        }

        if ($intent === null || $intent->status !== 'Draft') {
            $intent = $requirements->createIntent(session()->getId());
            session()->put($sessionKey, $intent->token);
        }

        $this->token = $intent->token;
        $this->groups = $intent->groups->map(fn ($group): array => [
            'facility_product_id' => (string) $group->facility_product_id,
            'quantity' => $group->quantity,
            'rate_code' => $group->rate_code->value,
            'check_in_date' => $group->check_in_date->toDateString(),
            'check_out_date' => $group->check_out_date->toDateString(),
            'estimated_users' => $group->estimated_users,
        ])->all();
        $this->updatePartyCount();
        if ($this->groups === []) {
            $this->addGroup();
        }

        $this->syncRoomOccupantRows();
    }

    private function updatePartyCount(): void
    {
        $this->partyCount = max(1, (int) collect($this->groups)
            ->sum(fn (array $group): int => (int) ($group['estimated_users'] ?? 0)));
    }

    #[Computed]
    public function products(): Collection
    {
        return FacilityProduct::query()
            ->with([
                'facilityType',
                'productRates' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('facility_product_rate_id'),
            ])
            ->where('is_active', true)
            ->orderBy('facility_type_id')
            ->orderBy('display_name')
            ->get();
    }

    public function addGroup(): void
    {
        if (count($this->groups) >= 20) {
            $this->addError('groups', 'You can add up to 20 facility groups.');

            return;
        }

        $this->resetErrorBag('groups');
        $this->groups[] = [
            'facility_product_id' => '',
            'quantity' => 1,
            'rate_code' => '',
            'check_in_date' => today()->toDateString(),
            'check_out_date' => today()->addDay()->toDateString(),
            'estimated_users' => 1,
        ];
        $this->syncRoomOccupantRows();
        $this->planTotal = '';
        $this->paymentAmount = '';
        $this->updatePartyCount();
    }

    public function removeGroup(int $index): void
    {
        if (count($this->groups) <= 1) {
            return;
        }

        unset($this->groups[$index]);
        $this->groups = array_values($this->groups);
        $this->resetErrorBag('groups');
        $this->syncRoomOccupantRows();
        $this->planTotal = '';
        $this->paymentAmount = '';
        $this->updatePartyCount();
    }

    public function updatedGroups(): void
    {
        foreach ($this->groups as $index => $group) {
            $product = $this->productFor($index);

            if ($product === null) {
                continue;
            }

            $activeRateCodes = $product->productRates->pluck('rate_code')->map->value;

            if (! $activeRateCodes->contains($group['rate_code'] ?? '')) {
                $this->groups[$index]['rate_code'] = '';
            }

            $checkIn = (string) ($group['check_in_date'] ?? '');

            if ($checkIn === '') {
                continue;
            }

            if (! validator(['date' => $checkIn], ['date' => 'date_format:Y-m-d'])->passes()) {
                continue;
            }

            if ($product->schedule_policy === FacilitySchedulePolicy::Overnight) {
                $checkOut = (string) ($group['check_out_date'] ?? '');
                if ($checkOut === '' || $checkOut <= $checkIn) {
                    $this->groups[$index]['check_out_date'] = \Carbon\CarbonImmutable::parse($checkIn)
                        ->addDay()
                        ->toDateString();
                }
            } else {
                $this->groups[$index]['check_out_date'] = $checkIn;
            }
        }

        $this->syncRoomOccupantRows();
        $this->planTotal = '';
        $this->paymentAmount = '';
        $this->updatePartyCount();
    }

    public function addAmenity(): void
    {
        $this->amenities[] = ['amenity_id' => '', 'quantity' => 1];
    }

    public function removeAmenity(int $index): void
    {
        unset($this->amenities[$index]);
        $this->amenities = array_values($this->amenities);
    }

    public function updatedPartyCount(): void
    {
        $this->syncRoomOccupantRows();
        $this->planTotal = '';
        $this->paymentAmount = '';

        if ($this->walkIn) {
            $this->adultCount = max(1, $this->partyCount);
            $this->childrenCount = 0;
            $this->pwdScCount = 0;
            $this->maleCount = max(1, $this->partyCount);
            $this->femaleCount = 0;
        }
    }

    public function updatedWalkIn(): void
    {
        if ($this->walkIn) {
            $this->adultCount = max(1, $this->partyCount);
            $this->maleCount = max(1, $this->partyCount);
        }

        $this->refreshRequiredPayment();
    }

    public function updatedAdultCount(): void { $this->refreshRequiredPayment(); }
    public function updatedChildrenCount(): void { $this->refreshRequiredPayment(); }
    public function updatedPwdScCount(): void { $this->refreshRequiredPayment(); }

    public function admissionTotal(): string
    {
        if (! $this->walkIn) {
            return '0.00';
        }

        try {
            return app(DecimalMoneyService::class)->normalize((string) app(EntranceSlipWorkflowService::class)
                ->quote($this->entrancePayload())['amount_due']);
        } catch (\Throwable) {
            return '0.00';
        }
    }

    public function requiredCoreTotal(): string
    {
        return app(DecimalMoneyService::class)->add(
            $this->planTotal !== '' ? $this->planTotal : '0.00',
            $this->admissionTotal(),
        );
    }

    private function refreshRequiredPayment(): void
    {
        if ($this->planTotal !== '') {
            $this->paymentAmount = $this->requiredCoreTotal();
        }
    }

    public function savePlan(
        FacilityRequirementService $requirements,
        FacilityAssignmentService $assignments,
    ): void {
        $this->resetErrorBag();
        $this->validate($this->planRules());

        try {
            $intent = $requirements->replaceGroups(
                $requirements->ownedIntent($this->token, session()->getId()),
                $this->partyCount,
                $this->groups,
            );
            $this->syncRoomOccupantRows();
            $this->planTotal = $assignments->quoteIntentTotal($intent);
            $this->paymentAmount = $this->requiredCoreTotal();
        } catch (\InvalidArgumentException $exception) {
            $this->addError('plan', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('plan', 'The facility plan could not be saved. Please try again.');
        }
    }

    /** @return array<string, string> */
protected function messages(): array
{
    return [
        'groups.*.estimated_users.required' => 'Please enter the number of guests.',
        'groups.*.estimated_users.integer'  => 'Guests must be a whole number.',
        'groups.*.estimated_users.min'      => 'There must be at least 1 guest.',
        'groups.*.estimated_users.max'      => 'You can book up to 500 guests.',
        'groups.*.quantity.required'        => 'Please enter a quantity.',
    ];
}

/** @return array<string, string> */
protected function validationAttributes(): array
{
    return [
        'groups.*.estimated_users'    => 'estimated guests',
        'groups.*.quantity'           => 'quantity',
        'groups.*.facility_product_id' => 'facility',
        'groups.*.rate_code'          => 'schedule',
        'groups.*.check_in_date'      => 'check-in date',
        'groups.*.check_out_date'     => 'check-out date',
        'contactNo'                   => 'contact number',
        'referenceNumber'             => 'GCash reference number',
    ];
}
    public function submit(
        FacilityRequirementService $requirements,
        FacilityAssignmentService $assignments,
    ): void {
        $this->validate($this->rules());

        try {
            $intent = $requirements->replaceGroups(
                $requirements->ownedIntent($this->token, session()->getId()),
                $this->partyCount,
                $this->groups,
            );
            $this->syncRoomOccupantRows();

            $this->planTotal = $assignments->quoteIntentTotal($intent);
            $this->paymentAmount = $this->requiredCoreTotal();

            $booking = $assignments->createStaffBooking($intent, [
                'first_name' => $this->firstName,
                'middle_name' => $this->middleName,
                'last_name' => $this->lastName,
                'email' => $this->email,
                'contact_no' => $this->contactNo,
                'province' => $this->province,
                'city' => $this->city,
                'barangay' => $this->barangay,
                'purok' => $this->purok,
                'room_occupants' => $this->roomOccupants,
                'user_id' => (int) Auth::id(),
                'payment_amount' => $this->paymentAmount,
                'mode_of_payment_id' => (int) $this->modeOfPaymentId,
                'reference_number' => $this->referenceNumber,
                'walk_in' => $this->walkIn,
                'entrance' => $this->entrancePayload(),
                'amenities' => $this->amenities,
            ]);

            session()->forget('cashier.booking_plan_token');
            session()->flash(
                'success',
                "Booking {$booking->b_ref_no} created".($this->walkIn ? ' and checked in.' : '.'),
            );
            $this->redirect(route('cashier.bookings.index'), navigate: true);
        } catch (\InvalidArgumentException $exception) {
            $this->addError('plan', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('plan', 'The booking could not be created. Please try again.');
        }
    }

    private function planRules(): array
    {
        return [
            'partyCount' => ['required', 'integer', 'min:1', 'max:500'],
            'groups' => ['required', 'array', 'min:1', 'max:20'],
            'groups.*.facility_product_id' => ['required', 'integer', 'exists:tbl_facility_product,facility_product_id'],
            'groups.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'groups.*.rate_code' => ['required', 'string', 'max:30'],
            'groups.*.check_in_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'groups.*.check_out_date' => ['required', 'date_format:Y-m-d'],
            'groups.*.estimated_users' => ['required', 'integer', 'min:1', 'max:500'],
        ];
    }

    protected function rules(): array
    {
        return [
            ...$this->planRules(),
            'firstName' => ['required', 'string', 'max:50'],
            'middleName' => ['nullable', 'string', 'max:50'],
            'lastName' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:100', 'regex:/^[A-Za-z0-9]+(?:[._%+-][A-Za-z0-9]+)*@(?:[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*\.)+[A-Za-z]{2,}$/'],
            'contactNo' => ['required', 'string', 'max:11'],
            'province' => ['required', 'string', 'max:50'],
            'city' => ['required', 'string', 'max:50'],
            'barangay' => ['nullable', 'string', 'max:50'],
            'purok' => ['nullable', 'string', 'max:50'],
            'roomOccupants' => ['array'],
            'roomOccupants.*' => ['array'],
            'roomOccupants.*.*.first_name' => ['required', 'string', 'max:50'],
            'roomOccupants.*.*.middle_name' => ['nullable', 'string', 'max:50'],
            'roomOccupants.*.*.last_name' => ['required', 'string', 'max:50'],
            'modeOfPaymentId' => ['required', 'integer', 'exists:tbl_mode_of_payment,mode_of_payment_id'],
            'referenceNumber' => ['nullable', 'string', 'max:100'],
            'walkIn' => ['boolean'],
            'adultCount' => [$this->walkIn ? 'required' : 'nullable', 'integer', 'min:0', 'max:5000'],
            'childrenCount' => [$this->walkIn ? 'required' : 'nullable', 'integer', 'min:0', 'max:5000'],
            'pwdScCount' => [$this->walkIn ? 'required' : 'nullable', 'integer', 'min:0', 'max:5000'],
            'maleCount' => [$this->walkIn ? 'required' : 'nullable', 'integer', 'min:0', 'max:5000'],
            'femaleCount' => [$this->walkIn ? 'required' : 'nullable', 'integer', 'min:0', 'max:5000'],
            'amenities' => ['array'],
            'amenities.*.amenity_id' => ['nullable', 'integer', 'exists:tbl_amenity,amenity_id'],
            'amenities.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function productFor(int $index): ?FacilityProduct
    {
        return $this->products->firstWhere(
            'facility_product_id',
            (int) ($this->groups[$index]['facility_product_id'] ?? 0),
        );
    }

    public function availabilityFor(int $index): ?array
    {
        try {
            return app(FacilityRequirementService::class)->availabilityFor($this->groups[$index]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function entrancePayload(): array
    {
        return [
            'user_id' => (int) Auth::id(),
            'adult_count' => $this->adultCount,
            'children_count' => $this->childrenCount,
            'pwd_sc_count' => $this->pwdScCount,
            'male_count' => $this->maleCount,
            'female_count' => $this->femaleCount,
            'tourist_count' => 0,
            'adult_discount_id' => null,
            'children_discount_id' => null,
            'pwd_sc_discount_id' => null,
            'adult_discounted_quantity' => 0,
            'children_discounted_quantity' => 0,
            'pwd_sc_discounted_quantity' => 0,
        ];
    }

    private function expectedRoomGuestCounts(): array
    {
        $counts = [];

        foreach ($this->groups as $index => $group) {
            $product = $this->productFor($index);

            if ($product?->included_guest_count === null || $product->strict_maximum === null) {
                continue;
            }

            $quantity = (int) ($group['quantity'] ?? 0);
            $estimatedUsers = (int) ($group['estimated_users'] ?? 0);

            if ($quantity < 1 || $quantity > 50 || $estimatedUsers < $quantity || $estimatedUsers > 500 || array_sum($counts) + $estimatedUsers > 500) {
                continue;
            }

            $minimum = intdiv($estimatedUsers, $quantity);
            $remainder = $estimatedUsers % $quantity;

            for ($unit = 0; $unit < $quantity; $unit++) {
                $counts[] = $minimum + ($unit < $remainder ? 1 : 0);
            }
        }

        return $counts;
    }

    private function syncRoomOccupantRows(): void
    {
        $rooms = [];

        foreach ($this->expectedRoomGuestCounts() as $roomIndex => $guestCount) {
            $occupants = [];

            for ($occupantIndex = 0; $occupantIndex < $guestCount; $occupantIndex++) {
                $occupants[] = [
                    'first_name' => $this->roomOccupants[$roomIndex][$occupantIndex]['first_name'] ?? '',
                    'middle_name' => $this->roomOccupants[$roomIndex][$occupantIndex]['middle_name'] ?? '',
                    'last_name' => $this->roomOccupants[$roomIndex][$occupantIndex]['last_name'] ?? '',
                ];
            }

            $rooms[] = $occupants;
        }

        $this->roomOccupants = $rooms;
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-medium text-zinc-500">Cashier · Booking</p>
            <h1 class="text-2xl font-semibold text-zinc-950 dark:text-white">Create booking</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">Use the shared planner; exact units are assigned automatically. Walk-In can be activated immediately after full core payment.</p>
        </div>
        <flux:button href="{{ route('cashier.bookings.index') }}" variant="ghost" wire:navigate>Back to bookings</flux:button>
    </div>

    @error('plan')
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">{{ $message }}</div>
    @enderror

    <form wire:submit="submit" novalidate class="space-y-6">
        <flux:card>
            <flux:heading size="lg">Guest information</flux:heading>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <flux:field>
                    <flux:label>First name <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model="firstName" :invalid="$errors->has('firstName')" />
                    @if ($msg = $this->visibleError('firstName'))
                        <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                    @endif
                </flux:field>
                <flux:input wire:model="middleName" label="Middle name" />
                <flux:field>
                    <flux:label>Last name <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model="lastName" :invalid="$errors->has('lastName')" />
                    @if ($msg = $this->visibleError('lastName'))
                        <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                    @endif
                </flux:field>
                <flux:field>
                    <flux:label>Email <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model="email" type="email" :invalid="$errors->has('email')" />
                    @if ($msg = $this->visibleError('email'))
                        <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                    @endif
                </flux:field>
                <flux:field>
                    <flux:label>Contact number <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model="contactNo" maxlength="11" :invalid="$errors->has('contactNo')" />
                    @if ($msg = $this->visibleError('contactNo'))
                        <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                    @endif
                </flux:field>
                <flux:field>
                    <flux:label>Province <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model="province" :invalid="$errors->has('province')" />
                    @if ($msg = $this->visibleError('province'))
                        <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                    @endif
                </flux:field>
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <flux:field>
                    <flux:label>City/Municipality <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model="city" :invalid="$errors->has('city')" />
                    @if ($msg = $this->visibleError('city'))
                        <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                    @endif
                </flux:field>
                <flux:input wire:model="barangay" label="Barangay" />
                <flux:input wire:model="purok" label="Purok/Street" />
            </div>
        </flux:card>

        <flux:card>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <flux:heading size="lg">Facility requirements</flux:heading>
                    <flux:text>One row is one schedule. Add another row when the schedule differs.</flux:text>
                </div>
                <flux:button type="button" variant="ghost" wire:click="addGroup">Add facility group</flux:button>
            </div>

            <div class="mt-5 space-y-4">
                @error('groups')
                    <p class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
                @enderror
                @foreach ($groups as $index => $group)
                    @php($product = $this->productFor($index))
                    @php($availability = $this->availabilityFor($index))
                    <div wire:key="cashier-booking-group-{{ $index }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="grid gap-4 lg:grid-cols-5">
                            <flux:field >
                                <flux:label>Facility name <span class="text-red-500">*</span></flux:label>
                                <flux:select wire:model.live="groups.{{ $index }}.facility_product_id" :invalid="$errors->has('groups.'.$index.'.facility_product_id')">
                                <option value="">Choose name</option>
                                @foreach ($this->products as $candidate)
                                    <option value="{{ $candidate->facility_product_id }}"> {{ $candidate->display_name }}</option>
                                @endforeach
                            </flux:select>
                                @if ($msg = $this->visibleError('groups.'.$index.'.facility_product_id'))
                                    <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                                @endif
                            </flux:field>
                            <flux:field>
                                <flux:label>Schedule <span class="text-red-500">*</span></flux:label>
                                <flux:select wire:model.live="groups.{{ $index }}.rate_code" :invalid="$errors->has('groups.'.$index.'.rate_code')">
                                <option value="">Choose schedule</option>
                                @foreach ($product?->productRates ?? [] as $rate)
                                    <option value="{{ $rate->rate_code->value }}">{{ $rate->display_name }} — ₱{{ number_format((float) $rate->amount, 2) }}</option>
                                @endforeach
                            </flux:select>
                                @if ($msg = $this->visibleError('groups.'.$index.'.rate_code'))
                                    <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                                @endif
                            </flux:field>
                            <flux:field>
                                <flux:label>Quantity <span class="text-red-500">*</span></flux:label>
                                <flux:input wire:model.live="groups.{{ $index }}.quantity" type="number" min="1" max="50" :invalid="$errors->has('groups.'.$index.'.quantity')" />
                                @if ($msg = $this->visibleError('groups.'.$index.'.quantity'))
                                    <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                                @endif
                            </flux:field>
                            <flux:field>
                                <flux:label>Estimated guests <span class="text-red-500">*</span></flux:label>
                                <flux:input wire:model.live="groups.{{ $index }}.estimated_users" type="number" min="1" max="500" :invalid="$errors->has('groups.'.$index.'.estimated_users')" />
                                @if ($msg = $this->visibleError('groups.'.$index.'.estimated_users'))
                                    <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                                @endif
                            </flux:field>
                            <div class="flex items-end">
                                <flux:button type="button" variant="ghost" class="w-full" wire:click="removeGroup({{ $index }})">Remove</flux:button>
                            </div>
                        </div>
                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            <flux:field>
                                <flux:label>Check-in/use date <span class="text-red-500">*</span></flux:label>
                                <flux:input wire:model.live="groups.{{ $index }}.check_in_date" type="date" min="{{ today()->toDateString() }}" :invalid="$errors->has('groups.'.$index.'.check_in_date')" />
                                @if ($msg = $this->visibleError('groups.'.$index.'.check_in_date'))
                                    <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                                @endif
                            </flux:field>
                            <flux:field>
                                <flux:label>Check-out/end date <span class="text-red-500">*</span></flux:label>
                                <flux:input wire:model.live="groups.{{ $index }}.check_out_date" type="date" min="{{ today()->toDateString() }}" :invalid="$errors->has('groups.'.$index.'.check_out_date')" />
                                @if ($msg = $this->visibleError('groups.'.$index.'.check_out_date'))
                                    <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                                @endif
                            </flux:field>
                        </div>
                        @if ($availability)
                            <div class="mt-3 flex flex-wrap gap-2">
                                <flux:badge color="emerald">{{ $availability['available'] }} available</flux:badge>
                                <flux:badge color="zinc">{{ $availability['requested'] }} requested</flux:badge>
                                @if ($product?->strict_maximum)
                                    <flux:badge color="blue">Maximum {{ $product->strict_maximum }} per room</flux:badge>
                                @else
                                    <flux:badge color="blue">Capacity is informational</flux:badge>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="mt-5 flex items-center gap-3">
                <flux:button type="button" wire:click="savePlan">Check availability & total</flux:button>
                @if ($planTotal !== '')
                    <span class="font-semibold">Total: ₱{{ number_format((float) $planTotal, 2) }}</span>
                @endif
            </div>
        </flux:card>

        @if ($roomOccupants !== [])
            <flux:card>
                <flux:heading size="lg">Room occupants</flux:heading>
                <flux:text>All occupants are required and will be attached to the exact assigned room.</flux:text>
                <div class="mt-4 space-y-5">
                    @foreach ($roomOccupants as $roomIndex => $occupants)
                        <div wire:key="cashier-room-{{ $roomIndex }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                            <p class="mb-3 font-semibold">Room {{ $roomIndex + 1 }} · {{ count($occupants) }} occupant(s)</p>
                            <div class="space-y-3">
                                @foreach ($occupants as $occupantIndex => $occupant)
                                    <div class="grid gap-3 md:grid-cols-3">
                                        <flux:field>
                                            <flux:label>First name <span class="text-red-500">*</span></flux:label>
                                            <flux:input wire:model="roomOccupants.{{ $roomIndex }}.{{ $occupantIndex }}.first_name" :invalid="$errors->has('roomOccupants.'.$roomIndex.'.'.$occupantIndex.'.first_name')" />
                                            @if ($msg = $this->visibleError('roomOccupants.'.$roomIndex.'.'.$occupantIndex.'.first_name'))
                                                <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                                            @endif
                                        </flux:field>
                                        <flux:input wire:model="roomOccupants.{{ $roomIndex }}.{{ $occupantIndex }}.middle_name" label="Middle name" />
                                        <flux:field>
                                            <flux:label>Last name <span class="text-red-500">*</span></flux:label>
                                            <flux:input wire:model="roomOccupants.{{ $roomIndex }}.{{ $occupantIndex }}.last_name" :invalid="$errors->has('roomOccupants.'.$roomIndex.'.'.$occupantIndex.'.last_name')" />
                                            @if ($msg = $this->visibleError('roomOccupants.'.$roomIndex.'.'.$occupantIndex.'.last_name'))
                                                <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                                            @endif
                                        </flux:field>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @endif

        <flux:card>
            <flux:heading size="lg">Payment & admission</flux:heading>
            <flux:text>Core facility charges—and Walk-In admission charges when applicable—must be fully paid before admission. Amenities may remain outstanding.</flux:text>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <flux:field>
                    <flux:label>Mode of payment <span class="text-red-500">*</span></flux:label>
                    <flux:select wire:model.live="modeOfPaymentId" :invalid="$errors->has('modeOfPaymentId')">
                    <option value="">Choose payment mode</option>
                    @foreach (ModeOfPayment::query()->orderBy('mode_of_payment')->get() as $mode)
                        <option value="{{ $mode->mode_of_payment_id }}">{{ $mode->mode_of_payment }}</option>
                    @endforeach
                </flux:select>
                    @if ($msg = $this->visibleError('modeOfPaymentId'))
                        <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                    @endif
                </flux:field>
                @if($this->modeOfPaymentId === '2')
                    <flux:input
                        wire:model="referenceNumber"
                        label="GCash reference number"
                        placeholder="Required for GCash" 
                    />
                @endif
                <flux:input wire:model="paymentAmount" type="number" step="0.01" label="Required core payment" readonly />
            </div>

        </flux:card>

        @if ($this->bannerErrors() !== [])
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($this->bannerErrors() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <div class="flex justify-end gap-3">
            <flux:button href="{{ route('cashier.bookings.index') }}" variant="ghost" wire:navigate>Cancel</flux:button>
            <flux:button type="submit" variant="primary">Create booking</flux:button>
        </div>
    </form>
</div>
