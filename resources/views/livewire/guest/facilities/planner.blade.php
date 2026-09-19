<?php

use App\FacilitySchedulePolicy;
use App\Models\FacilityProduct;
use App\Services\FacilityAssignmentService;
use App\Services\FacilityRequirementService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.public')] #[Title('Plan Facilities - Olaer Spring Resort')] class extends Component
{
    public string $token = '';
    public int $partyCount = 1;

    /** @var array<int, array<string, mixed>> */
    public array $groups = [];

    public string $firstName = '';
    public string $middleName = '';
    public string $lastName = '';
    public string $email = '';
    public string $contactNo = '';
    public string $province = '';
    public string $city = '';
    public string $barangay = '';
    public string $purok = '';

    /** @var array<int, array{first_name: string, middle_name: string, last_name: string}> */
    public array $extraGuests = [];

    public function mount(FacilityRequirementService $requirements): void
    {
        $storedToken = (string) session('guest.facility_plan_token', '');

        try {
            $intent = $storedToken !== ''
                ? $requirements->ownedIntent($storedToken, session()->getId())
                : null;
        } catch (\Throwable) {
            $intent = null;
        }

        if ($intent === null || $intent->status !== 'Draft') {
            $intent = $requirements->createIntent(session()->getId());
            session()->put('guest.facility_plan_token', $intent->token);
        }

        $this->token = $intent->token;
        $this->partyCount = $intent->party_count;
        $this->groups = $intent->groups->map(fn ($group): array => [
            'facility_product_id' => (string) $group->facility_product_id,
            'quantity' => $group->quantity,
            'rate_code' => $group->rate_code->value,
            'check_in_date' => $group->check_in_date->toDateString(),
            'check_out_date' => $group->check_out_date->toDateString(),
            'estimated_users' => $group->estimated_users,
        ])->all();

        if ($this->groups === []) {
            $this->addGroup();
        }
    }

    #[Computed]
    public function products(): Collection
    {
        return FacilityProduct::query()
            ->with(['facilityType', 'productRates' => fn ($query) => $query->where('is_active', true)->orderBy('facility_product_rate_id')])
            ->withCount(['facilities' => fn ($query) => $query->where('facility_status', 'Available')])
            ->where('is_active', true)
            ->orderBy('facility_type_id')
            ->orderBy('display_name')
            ->get();
    }

    public function addGroup(): void
    {
        $this->groups[] = [
            'facility_product_id' => '',
            'quantity' => 1,
            'rate_code' => '',
            'check_in_date' => today()->toDateString(),
            'check_out_date' => today()->addDay()->toDateString(),
            'estimated_users' => max(1, $this->partyCount),
        ];
    }

    public function removeGroup(int $index): void
    {
        if (count($this->groups) === 1) {
            return;
        }

        unset($this->groups[$index]);
        $this->groups = array_values($this->groups);
        $this->syncExtraGuestRows();
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

            $checkInDate = (string) ($group['check_in_date'] ?? '');

            if ($checkInDate === '') {
                continue;
            }

            if ($product->schedule_policy === FacilitySchedulePolicy::Overnight) {
                $checkOutDate = (string) ($group['check_out_date'] ?? '');

                if ($checkOutDate === '' || $checkOutDate <= $checkInDate) {
                    $this->groups[$index]['check_out_date'] = \Carbon\CarbonImmutable::parse($checkInDate)->addDay()->toDateString();
                }
            } else {
                $this->groups[$index]['check_out_date'] = $checkInDate;
            }
        }

        $this->syncExtraGuestRows();
    }

    public function updatedPartyCount(): void
    {
        $this->syncExtraGuestRows();
    }

    public function savePlan(FacilityRequirementService $requirements): void
    {
        try {
            $requirements->replaceGroups(
                $requirements->ownedIntent($this->token, session()->getId()),
                $this->partyCount,
                $this->groups,
            );
            $this->syncExtraGuestRows();
            session()->flash('success', 'Facility plan saved. Availability was checked against current reservations and bookings.');
        } catch (\Throwable $exception) {
            $this->addError('plan', $exception->getMessage());
        }
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
            $this->syncExtraGuestRows();
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
                'extra_guests' => $this->extraGuests,
            ]);

            session()->forget('guest.facility_plan_token');
            session()->put('guest.reservation_confirmation_id', (int) $reservation->reservation_id);
            $this->redirect(route('guest.reservations.success'), navigate: true);
        } catch (\Throwable $exception) {
            $this->addError('plan', $exception->getMessage());
        }
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(): array
    {
        return [
            'partyCount' => ['required', 'integer', 'min:1', 'max:500'],
            'groups' => ['required', 'array', 'min:1', 'max:20'],
            'groups.*.facility_product_id' => ['required', 'integer', 'exists:tbl_facility_product,facility_product_id'],
            'groups.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'groups.*.rate_code' => ['required', 'string', 'max:30'],
            'groups.*.check_in_date' => ['required', 'date', 'after_or_equal:today'],
            'groups.*.check_out_date' => ['required', 'date'],
            'groups.*.estimated_users' => ['required', 'integer', 'min:1'],
            'firstName' => ['required', 'string', 'max:50'],
            'middleName' => ['nullable', 'string', 'max:50'],
            'lastName' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:50'],
            'contactNo' => ['required', 'regex:/^09[0-9]{9}$/'],
            'province' => ['required', 'string', 'max:50'],
            'city' => ['required', 'string', 'max:50'],
            'barangay' => ['nullable', 'string', 'max:50'],
            'purok' => ['nullable', 'string', 'max:50'],
            'extraGuests' => ['array'],
            'extraGuests.*.first_name' => ['required', 'string', 'max:50'],
            'extraGuests.*.middle_name' => ['nullable', 'string', 'max:50'],
            'extraGuests.*.last_name' => ['required', 'string', 'max:50'],
        ];
    }

    public function productFor(int $index): ?FacilityProduct
    {
        $productId = (int) ($this->groups[$index]['facility_product_id'] ?? 0);

        return $this->products->firstWhere('facility_product_id', $productId);
    }

    public function availabilityFor(int $index): ?array
    {
        try {
            return app(FacilityRequirementService::class)->availabilityFor($this->groups[$index]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function expectedExtraGuestCount(): int
    {
        $count = 0;

        foreach ($this->groups as $index => $group) {
            $product = $this->productFor($index);

            if ($product?->included_guest_count === null || $product->strict_maximum === null) {
                continue;
            }

            $quantity = max(1, (int) ($group['quantity'] ?? 1));
            $estimatedUsers = max($quantity, (int) ($group['estimated_users'] ?? $quantity));
            $minimum = intdiv($estimatedUsers, $quantity);
            $remainder = $estimatedUsers % $quantity;

            for ($unit = 0; $unit < $quantity; $unit++) {
                $unitGuests = $minimum + ($unit < $remainder ? 1 : 0);
                $count += max(0, $unitGuests - $product->included_guest_count);
            }
        }

        return $count;
    }

    private function syncExtraGuestRows(): void
    {
        $rows = [];

        for ($index = 0; $index < $this->expectedExtraGuestCount(); $index++) {
            $rows[] = [
                'first_name' => $this->extraGuests[$index]['first_name'] ?? '',
                'middle_name' => $this->extraGuests[$index]['middle_name'] ?? '',
                'last_name' => $this->extraGuests[$index]['last_name'] ?? '',
            ];
        }

        $this->extraGuests = $rows;
    }
};

?>

<section class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    <div class="max-w-3xl">
        <p class="text-sm font-medium text-emerald-700 dark:text-emerald-300">Facility planner</p>
        <h1 class="mt-1 text-3xl font-bold tracking-tight text-zinc-950 dark:text-white">Plan one stay with multiple facilities</h1>
        <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">Choose facility groups and quantities. The system checks live availability and assigns the exact units only when you submit.</p>
    </div>

    @if (session('success'))
        <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">{{ session('success') }}</div>
    @endif

    @error('plan')
        <div class="mt-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">{{ $message }}</div>
    @enderror

    <form wire:submit="submit" class="mt-8 space-y-6">
        <flux:card>
            <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_14rem] sm:items-end">
                <div>
                    <flux:heading size="lg">Facility requirements</flux:heading>
                    <flux:text class="mt-1">Availability is counted by product, date, and canonical schedule slot.</flux:text>
                </div>
                <flux:input wire:model.live="partyCount" type="number" min="1" max="500" label="Total unique guests" />
            </div>

            <div class="mt-6 space-y-4">
                @foreach ($groups as $index => $group)
                    @php($availability = $this->availabilityFor($index))
                    @php($selectedProduct = $this->productFor($index))
                    <div wire:key="facility-group-{{ $index }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="grid gap-4 lg:grid-cols-6">
                            <flux:select wire:model.live="groups.{{ $index }}.facility_product_id" label="Facility type and name" class="lg:col-span-2">
                                <option value="">Choose a facility</option>
                                @foreach ($this->products as $product)
                                    <option value="{{ $product->facility_product_id }}">{{ $product->facilityType?->facility_type }} — {{ $product->display_name }}</option>
                                @endforeach
                            </flux:select>

                            <flux:select wire:model.live="groups.{{ $index }}.rate_code" label="Schedule">
                                <option value="">Choose rate</option>
                                @foreach ($selectedProduct?->productRates ?? [] as $rate)
                                    <option value="{{ $rate->rate_code->value }}">{{ $rate->display_name }} — ₱{{ number_format((float) $rate->amount, 2) }}</option>
                                @endforeach
                            </flux:select>
                            <flux:input wire:model.live="groups.{{ $index }}.quantity" type="number" min="1" max="50" label="Quantity" />
                            <flux:input wire:model.live="groups.{{ $index }}.estimated_users" type="number" min="1" label="Estimated users" />
                            <div class="flex items-end">
                                <flux:button type="button" variant="ghost" wire:click="removeGroup({{ $index }})" class="w-full">Remove</flux:button>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <flux:input wire:model.live="groups.{{ $index }}.check_in_date" type="date" min="{{ today()->toDateString() }}" label="Check-in / use date" />
                            <flux:input wire:model.live="groups.{{ $index }}.check_out_date" type="date" min="{{ today()->toDateString() }}" label="Check-out / end date" />
                        </div>

                        @if ($availability)
                            <div class="mt-4 flex flex-wrap gap-2 text-sm">
                                <flux:badge color="emerald">{{ $availability['available'] }} available</flux:badge>
                                <flux:badge color="zinc">{{ $availability['requested'] }} requested</flux:badge>
                                @if ($selectedProduct?->strict_maximum)
                                    <flux:badge color="blue">Maximum {{ $selectedProduct->strict_maximum }} guests per unit</flux:badge>
                                @else
                                    <flux:badge color="blue">Capacity is a recommendation</flux:badge>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
                <flux:button type="button" variant="ghost" wire:click="addGroup">Add another facility group</flux:button>
                <flux:button type="button" wire:click="savePlan">Check and save availability</flux:button>
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="lg">Primary guest</flux:heading>
            <div class="mt-5 grid gap-4 md:grid-cols-3">
                <flux:input wire:model="firstName" label="First name" />
                <flux:input wire:model="middleName" label="Middle name" />
                <flux:input wire:model="lastName" label="Last name" />
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <flux:input wire:model="email" type="email" label="Email" />
                <flux:input wire:model="contactNo" label="Contact number" placeholder="09XXXXXXXXX" />
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-4">
                <flux:input wire:model="province" label="Province" />
                <flux:input wire:model="city" label="City/Municipality" />
                <flux:input wire:model="barangay" label="Barangay" />
                <flux:input wire:model="purok" label="Purok/Street" />
            </div>
        </flux:card>

        @if ($extraGuests !== [])
            <flux:card>
                <flux:heading size="lg">Paid room extra guests</flux:heading>
                <flux:text class="mt-1">Rooms include four guests. Name each guest charged above the included count.</flux:text>
                <div class="mt-5 space-y-4">
                    @foreach ($extraGuests as $index => $extraGuest)
                        <div wire:key="extra-guest-{{ $index }}" class="grid gap-4 rounded-xl border border-zinc-200 p-4 md:grid-cols-3 dark:border-zinc-700">
                            <flux:input wire:model="extraGuests.{{ $index }}.first_name" label="First name" />
                            <flux:input wire:model="extraGuests.{{ $index }}.middle_name" label="Middle name" />
                            <flux:input wire:model="extraGuests.{{ $index }}.last_name" label="Last name" />
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @endif

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">Submit reservation plan</flux:button>
        </div>
    </form>
</section>
