<?php

use App\FacilitySchedulePolicy;
use App\Models\FacilityProduct;
use App\Services\FacilityAssignmentService;
use App\Services\FacilityRequirementService;
use App\Support\GroupCapacityCheck;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Create Reservation - Olaer Spring Resort')] class extends Component
{

    use \App\Concerns\HasPhilippineLocations;
    use \App\Concerns\ChecksFacilityGroups;

    public function updated(string $property): void
    {
        // Clear the red highlight of a field as soon as the user edits it.
        $this->resetValidation($property);

        // Capitalise first/last names and lower-case emails.
        \App\Support\PersonInput::normalize($this, $property);
    }

    public string $token = '';
    public int $partyCount = 1;
    public array $groups = [];
    public array $roomOccupants = [];
    public string $planTotal = '';

    /** Bumped on every action that can raise an error, so the toast shows again even if the messages are unchanged. */
    public int $errorToastTick = 0;

    /** Facility picked for each unit: group index => unit position => facility id. */
    public array $facilitySelection = [];

    /** Assigned facilities with their available alternatives (display only). */
    #[Locked]
    public array $facilityPlan = [];

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
        $sessionKey = 'cashier.reservation_plan_token';
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

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            ...$this->facilityGroupMessages(),
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
        $this->errorToastTick++;
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
        $this->clearFacilityPlan();
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
        $this->clearFacilityPlan();
        $this->updatePartyCount();
    }

    public function updatedGroups(): void
    {
        $this->sanitizeWholeNumbers();

        foreach ($this->groups as $index => $group) {
            $product = $this->productFor($index);

            if ($product === null) {
                continue;
            }

            $activeRateCodes = $product->productRates->pluck('rate_code')->map->value;

            if (! $activeRateCodes->contains($group['rate_code'] ?? '')) {
                $this->groups[$index]['rate_code'] = '';
            }

            if (\App\Support\DayRateCutoff::blocks($product->schedule_policy, (string) ($group['rate_code'] ?? ''), (string) ($group['check_in_date'] ?? ''))) {
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
        $this->clearFacilityPlan();
        $this->updatePartyCount();
    }

    public function updatedPartyCount(): void
    {
        $this->syncRoomOccupantRows();
        $this->planTotal = '';
        $this->clearFacilityPlan();
    }

    public function savePlan(
        FacilityRequirementService $requirements,
        FacilityAssignmentService $assignments,
    ): void {
        $this->errorToastTick++;
        $this->resetErrorBag();
        $this->sanitizeWholeNumbers(fillBlanks: true);
        $this->validate($this->planRules());

        if ($this->groupIssues() !== []) {
            return; // the toast lists them and the fields are red
        }

        try {
            $intent = $requirements->replaceGroups(
                $requirements->ownedIntent($this->token, session()->getId()),
                $this->partyCount,
                $this->groups,
            );
            $this->syncRoomOccupantRows();
            $this->planTotal = $assignments->quoteIntentTotal($intent, $this->facilitySelection);
            $this->loadFacilityPlan($intent, $assignments);
        } catch (\InvalidArgumentException $exception) {
            $this->addError('plan', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('plan', 'The facility plan could not be saved. Please try again.');
        }
    }

    private function clearFacilityPlan(): void
    {
        $this->facilityPlan = [];
        $this->facilitySelection = [];
    }

    private function loadFacilityPlan(
        \App\Models\FacilityRequirementIntent $intent,
        FacilityAssignmentService $assignments,
    ): void {
        $rows = $assignments->previewAssignments($intent, $this->facilitySelection);
        $selection = [];

        foreach ($rows as $row) {
            $selection[$row['group_index']][$row['position']] = (string) $row['facility_id'];
        }

        $this->facilityPlan = $rows;
        $this->facilitySelection = $selection;
    }

    /** Re-quote when the cashier picks a different facility from a dropdown. */
    public function updatedFacilitySelection(): void
    {
        $this->errorToastTick++;
        $this->resetErrorBag('plan');

        try {
            $assignments = app(FacilityAssignmentService::class);
            $intent = app(FacilityRequirementService::class)->ownedIntent($this->token, session()->getId());

            $this->planTotal = $assignments->quoteIntentTotal($intent, $this->facilitySelection);
            
            $this->loadFacilityPlan($intent, $assignments);
        } catch (\InvalidArgumentException $exception) {
            $this->addError('plan', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('plan', 'The facility choice could not be applied. Check availability again.');
        }
    }

    public function submit(
        FacilityRequirementService $requirements,
        FacilityAssignmentService $assignments,
    ): void {
        $this->errorToastTick++;
        $this->sanitizeWholeNumbers(fillBlanks: true);
        $this->validate($this->rules());

        if ($this->groupIssues() !== []) {
            return;
        }

        try {
            $intent = $requirements->replaceGroups(
                $requirements->ownedIntent($this->token, session()->getId()),
                $this->partyCount,
                $this->groups,
            );
            $this->syncRoomOccupantRows();

            $reservation = $assignments->createReservation($intent, [
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
                'facility_selection' => $this->facilitySelection,
                'user_id' => (int) Auth::id(),
            ]);

            session()->forget('cashier.reservation_plan_token');
            session()->flash(
                'success',
                "Reservation {$reservation->r_ref_no} created. Collect/verify at least 50% of the final total before treating the deposit requirement as satisfied.",
            );
            $this->redirect(route('cashier.reservations.index'), navigate: true);
        } catch (\InvalidArgumentException $exception) {
            $this->addError('plan', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('plan', 'The reservation could not be created. Please try again.');
        }
    }

    private function planRules(): array
    {
        return [
            'partyCount' => ['required', 'integer', 'min:1'],
            'groups' => ['required', 'array', 'min:1', 'max:20'],
            'groups.*.facility_product_id' => ['required', 'integer', 'exists:tbl_facility_product,facility_product_id'],
            'groups.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'groups.*.rate_code' => ['required', 'string', 'max:30'],
            'groups.*.check_in_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'groups.*.check_out_date' => ['required', 'date_format:Y-m-d'],
            'groups.*.estimated_users' => ['required', 'integer', 'min:1'],
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
        ];
    }

    public function productFor(int $index): ?FacilityProduct
    {
        return $this->products->firstWhere(
            'facility_product_id',
            (int) ($this->groups[$index]['facility_product_id'] ?? 0),
        );
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

            // A room never holds more than its strict maximum; a wrong row gets no occupant rows.
            if ($quantity < 1 || $quantity > 50 || GroupCapacityCheck::message($product, $quantity, $estimatedUsers) !== null) {
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
        // While a room row is wrong, keep what was typed; the rows rebuild once it is fixed.
        foreach ($this->groups as $index => $group) {
            if (GroupCapacityCheck::message($this->productFor($index), (int) ($group['quantity'] ?? 0), (int) ($group['estimated_users'] ?? 0)) !== null) {
                return;
            }
        }

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
            <p class="text-sm font-medium text-zinc-500">Cashier · Reservation</p>
            <h1 class="text-2xl font-semibold text-zinc-950 dark:text-white">Create reservation</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">Select facility products and quantities; exact physical units are assigned automatically.</p>
        </div>
        <flux:button href="{{ route('cashier.reservations.index') }}" variant="ghost" wire:navigate>Back to reservations</flux:button>
    </div>


    <form wire:submit="submit" novalidate class="space-y-6">
        <flux:card>
            <flux:heading size="lg">Guest information</flux:heading>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <flux:field class="relative">
                    <flux:label>First name <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model="firstName" :invalid="$errors->has('firstName')" />
                </flux:field>
                <flux:field class="relative">
                    <flux:label>Middle name</flux:label>
                    <flux:input wire:model="middleName" />
                </flux:field>
                <flux:field class="relative">
                    <flux:label>Last name <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model="lastName" :invalid="$errors->has('lastName')" />
                </flux:field>
                <flux:field class="relative md:col-span-2">
                    <flux:label>Email <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model="email" type="email" :invalid="$errors->has('email')" />
                </flux:field>
                <flux:field class="relative">
                    <flux:label>Contact number <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model="contactNo" maxlength="11" :invalid="$errors->has('contactNo')" />
                </flux:field>
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-3 md:items-start">
                <x-ph-region-select :options="$this->regionOptions()" />
                <x-ph-province-select :options="$this->provinceOptions()" />
                <x-ph-city-select :options="$this->cityOptions()" :enabled="$province !== ''" :listed="count($this->provinceOptions()) > 0" label="City/Municipality" />
                <x-ph-barangay-select :options="$this->barangayOptions()" :enabled="$city !== ''" :listed="count($this->provinceOptions()) > 0" />
                <flux:field class="relative md:col-span-2">
                    <flux:label>Purok/Street</flux:label>
                    <flux:input wire:model="purok" />
                </flux:field>
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
                @php($issues = $this->groupIssues())
                @foreach ($groups as $index => $group)
                    @php($product = $this->productFor($index))
                    @php($availability = $this->availabilityFor($index))
                    <div wire:key="cashier-reservation-group-{{ $index }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="grid gap-4 lg:grid-cols-5 lg:items-start">
                            <flux:field class="relative" >
                                <flux:label>Facility name <span class="text-red-500">*</span></flux:label>
                                <flux:select wire:model.live="groups.{{ $index }}.facility_product_id" :invalid="$errors->has('groups.'.$index.'.facility_product_id')">
                                <option value="">Choose a facility</option>
                                @foreach ($this->products as $candidate)
                                    <option value="{{ $candidate->facility_product_id }}"> {{ $candidate->display_name }}</option>
                                @endforeach
                            </flux:select>
                            </flux:field>
                            <flux:field class="relative">
                                <flux:label>Schedule <span class="text-red-500">*</span></flux:label>
                                <flux:select wire:model.live="groups.{{ $index }}.rate_code" :invalid="$errors->has('groups.'.$index.'.rate_code')">
                                <option value="">Choose schedule</option>
                                @foreach ($product?->productRates ?? [] as $rate)
                                    @continue(\App\Support\DayRateCutoff::blocks($product->schedule_policy, $rate->rate_code->value, (string) ($group['check_in_date'] ?? '')))
                                    <option value="{{ $rate->rate_code->value }}">{{ $rate->display_name }} — ₱{{ number_format((float) $rate->amount, 2) }}</option>
                                @endforeach
                            </flux:select>
                                @if ($product && \App\Support\DayRateCutoff::closedFor($product->schedule_policy, (string) ($group['check_in_date'] ?? '')))
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Only Night rate is available currently.</p>
                                @endif
                            </flux:field>
                            <flux:field class="relative">
                                <flux:label>Quantity <span class="text-red-500">*</span></flux:label>
                                <flux:input
                                    wire:model.live.debounce.400ms="groups.{{ $index }}.quantity"
                                    type="text"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    x-on:keydown="['e','E','+','-','.',','].includes($event.key) && $event.preventDefault()"
                                    x-on:input="$el.value = $el.value.replace(/\D/g, '')"
                                    x-on:blur="if ($el.value === '' || Number($el.value) < 1) { $el.value = 1; $el.dispatchEvent(new Event('input', { bubbles: true })) }"
                                    :invalid="$errors->has('groups.'.$index.'.quantity') || isset($issues[$index]['quantity'])"
                                />
                            </flux:field>
                            <flux:field class="relative">
                                <flux:label>Estimated guests <span class="text-red-500">*</span></flux:label>
                                <flux:input
                                    wire:model.live.debounce.400ms="groups.{{ $index }}.estimated_users"
                                    type="text"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    x-on:keydown="['e','E','+','-','.',','].includes($event.key) && $event.preventDefault()"
                                    x-on:input="$el.value = $el.value.replace(/\D/g, '')"
                                    x-on:blur="if ($el.value === '' || Number($el.value) < 1) { $el.value = 1; $el.dispatchEvent(new Event('input', { bubbles: true })) }"
                                    :invalid="$errors->has('groups.'.$index.'.estimated_users') || isset($issues[$index]['estimated_users'])"
                                />
                            </flux:field>
                            {{-- An invisible label keeps the button level with the inputs, with or without a hint under Schedule. --}}
                            <flux:field class="relative">
                                <flux:label class="invisible" aria-hidden="true">Remove</flux:label>
                                <flux:button type="button" variant="ghost" class="w-full" wire:click="removeGroup({{ $index }})">Remove</flux:button>
                            </flux:field>
                        </div>
                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            <flux:field class="relative">
                                <flux:label>Check-in/use date <span class="text-red-500">*</span></flux:label>
                                <flux:input wire:model.live="groups.{{ $index }}.check_in_date" type="date" min="{{ today()->toDateString() }}" :invalid="$errors->has('groups.'.$index.'.check_in_date')" />
                            </flux:field>
                            <flux:field class="relative">
                                <flux:label>Check-out/end date <span class="text-red-500">*</span></flux:label>
                                <flux:input wire:model.live="groups.{{ $index }}.check_out_date" type="date" min="{{ today()->toDateString() }}" :invalid="$errors->has('groups.'.$index.'.check_out_date')" />
                            </flux:field>
                        </div>
                        @php($rowSummary = \App\Support\GroupRowPricing::summarize($product, $group))
                        @if ($rowSummary)
                            <div class="mt-3 flex flex-wrap gap-2">
                                @if ($rowSummary['is_room'])
                                    <flux:badge color="amber">{{ $rowSummary['days'] }} {{ \Illuminate\Support\Str::plural('day', $rowSummary['days']) }}</flux:badge>
                                @endif
                                <flux:badge color="green" title="Before any discount">Subtotal: ₱{{ number_format($rowSummary['subtotal'], 2) }}</flux:badge>
                            </div>
                        @endif
                        {{-- Shown as soon as a facility and a schedule are chosen. --}}
                        @if ($availability)
                            <div class="mt-3 flex flex-wrap gap-2">
                                <flux:badge color="emerald">{{ $availability['available'] ?? '—' }} available</flux:badge>
                                <flux:badge :color="$availability['available'] !== null && $availability['requested'] > $availability['available'] ? 'red' : 'zinc'">{{ $availability['requested'] }} requested</flux:badge>
                                @if ($capacityText = $this->capacityLabel($product))
                                    <flux:badge color="blue">{{ $capacityText }}</flux:badge>
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

            @if ($facilityPlan !== [])
                <div class="mt-6 space-y-4">
                    <div>
                        <h3 class="font-semibold">Assigned facilities</h3>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            Facilities were assigned automatically. Choose a different one from the list if the guest asks for a specific facility.
                        </p>
                    </div>

                    @foreach (collect($facilityPlan)->groupBy('group_index') as $groupIndex => $planRows)
                        @php($firstRow = $planRows->first())

                        <div wire:key="facility-plan-{{ $groupIndex }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                            <p class="mb-3 text-sm font-medium">
                                {{ $firstRow['product'] }} · {{ $firstRow['rate_code'] }} · {{ $firstRow['check_in_date'] }}@if ($firstRow['check_out_date'] !== $firstRow['check_in_date']) to {{ $firstRow['check_out_date'] }}@endif
                            </p>

                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                @foreach ($planRows as $planRow)
                                    <flux:field class="relative" wire:key="facility-plan-{{ $groupIndex }}-{{ $planRow['position'] }}">
                                        <flux:label>Unit {{ $planRow['position'] + 1 }}</flux:label>
                                        <flux:select wire:model.live="facilitySelection.{{ $planRow['group_index'] }}.{{ $planRow['position'] }}">
                                            @foreach ($planRow['options'] as $option)
                                                <option value="{{ $option['facility_id'] }}">{{ $option['facility_name'] }}</option>
                                            @endforeach
                                        </flux:select>
                                    </flux:field>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </flux:card>

        @if ($this->showRoomOccupants())
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
                                        <flux:field class="relative">
                                            <flux:label>First name <span class="text-red-500">*</span></flux:label>
                                            <flux:input wire:model="roomOccupants.{{ $roomIndex }}.{{ $occupantIndex }}.first_name" :invalid="$errors->has('roomOccupants.'.$roomIndex.'.'.$occupantIndex.'.first_name')" />
                                        </flux:field>
                                        <flux:input wire:model="roomOccupants.{{ $roomIndex }}.{{ $occupantIndex }}.middle_name" label="Middle name" />
                                        <flux:field class="relative">
                                            <flux:label>Last name <span class="text-red-500">*</span></flux:label>
                                            <flux:input wire:model="roomOccupants.{{ $roomIndex }}.{{ $occupantIndex }}.last_name" :invalid="$errors->has('roomOccupants.'.$roomIndex.'.'.$occupantIndex.'.last_name')" />
                                        </flux:field>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @endif

        {{-- Every error text shows here (fields only turn red). It stays until the errors are fixed or it is dismissed. --}}
        @php($toastErrors = $this->bannerErrors())
        @if ($toastErrors !== [])
            <div
                wire:key="error-toast-{{ $errorToastTick }}-{{ md5(json_encode($toastErrors)) }}"
                x-data="{ show: true }"
                x-show="show"
                x-transition.opacity.duration.200ms
                role="alert"
                class="fixed right-4 top-20 z-[60] max-h-[80vh] w-[calc(100vw-2rem)] max-w-sm overflow-y-auto rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 shadow-lg dark:border-red-900 dark:bg-red-950 dark:text-red-100"
            >
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">Please fix the following:</p>
                        <ul class="mt-1 list-disc space-y-1 pl-5">
                            @foreach ($toastErrors as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <button type="button" x-on:click="show = false" class="shrink-0 text-red-700 hover:text-red-900 dark:text-red-200 dark:hover:text-white" aria-label="Dismiss">&times;</button>
                </div>
            </div>
        @endif

        <div class="flex justify-end gap-3">
            <flux:button href="{{ route('cashier.reservations.index') }}" variant="ghost" wire:navigate>Cancel</flux:button>
            <flux:button type="submit" variant="primary">Create reservation</flux:button>
        </div>
    </form>
</div>
