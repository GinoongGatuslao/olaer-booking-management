<?php

use App\FacilitySchedulePolicy;
use App\Models\FacilityProduct;
use App\Models\ModeOfPayment;
use App\Services\FacilityAssignmentService;
use App\Services\FacilityRequirementService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Create Booking - Olaer Spring Resort')] class extends Component
{
    public string $token = '';
    public int $partyCount = 1;
    public array $groups = [];
    public array $roomOccupants = [];
    public string $planTotal = '';
    public string $paymentAmount = '';
    public string $modeOfPaymentId = '';
    public string $referenceNumber = '';
    public bool $walkIn = false;

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

        $this->syncRoomOccupantRows();
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
        $this->groups[] = [
            'facility_product_id' => '',
            'quantity' => 1,
            'rate_code' => '',
            'check_in_date' => today()->toDateString(),
            'check_out_date' => today()->addDay()->toDateString(),
            'estimated_users' => max(1, $this->partyCount),
        ];
        $this->syncRoomOccupantRows();
    }

    public function removeGroup(int $index): void
    {
        if (count($this->groups) <= 1) {
            return;
        }

        unset($this->groups[$index]);
        $this->groups = array_values($this->groups);
        $this->syncRoomOccupantRows();
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
    }

    public function updatedPartyCount(): void
    {
        $this->syncRoomOccupantRows();
        $this->planTotal = '';
    }

    public function savePlan(
        FacilityRequirementService $requirements,
        FacilityAssignmentService $assignments,
    ): void {
        $this->resetErrorBag();

        try {
            $intent = $requirements->replaceGroups(
                $requirements->ownedIntent($this->token, session()->getId()),
                $this->partyCount,
                $this->groups,
            );
            $this->syncRoomOccupantRows();
            $this->planTotal = $assignments->quoteIntentTotal($intent);
            $this->paymentAmount = $this->planTotal;
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
            $this->syncRoomOccupantRows();

            $this->planTotal = $assignments->quoteIntentTotal($intent);
            $this->paymentAmount = $this->planTotal;

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
            ]);

            session()->forget('cashier.booking_plan_token');
            session()->flash(
                'success',
                "Booking {$booking->b_ref_no} created".($this->walkIn ? ' and checked in.' : '.'),
            );
            $this->redirect(route('cashier.bookings.index'), navigate: true);
        } catch (\Throwable $exception) {
            $this->addError('plan', $exception->getMessage());
        }
    }

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
            'email' => ['required', 'email', 'max:100'],
            'contactNo' => ['required', 'string', 'max:20'],
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

    private function expectedRoomGuestCounts(): array
    {
        $counts = [];

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

    <form wire:submit="submit" class="space-y-6">
        <flux:card>
            <flux:heading size="lg">Guest</flux:heading>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <flux:input wire:model="firstName" label="First name *" />
                <flux:input wire:model="middleName" label="Middle name" />
                <flux:input wire:model="lastName" label="Last name *" />
                <flux:input wire:model="email" type="email" label="Email *" />
                <flux:input wire:model="contactNo" label="Contact number *" />
                <flux:input wire:model.live="partyCount" type="number" min="1" max="500" label="Total unique guests *" />
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-4">
                <flux:input wire:model="province" label="Province *" />
                <flux:input wire:model="city" label="City/Municipality *" />
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
                @foreach ($groups as $index => $group)
                    @php($product = $this->productFor($index))
                    @php($availability = $this->availabilityFor($index))
                    <div wire:key="cashier-booking-group-{{ $index }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="grid gap-4 lg:grid-cols-6">
                            <flux:select wire:model.live="groups.{{ $index }}.facility_product_id" label="Facility type / product *" class="lg:col-span-2">
                                <option value="">Choose product</option>
                                @foreach ($this->products as $candidate)
                                    <option value="{{ $candidate->facility_product_id }}">{{ $candidate->facilityType?->facility_type }} — {{ $candidate->display_name }}</option>
                                @endforeach
                            </flux:select>
                            <flux:select wire:model.live="groups.{{ $index }}.rate_code" label="Schedule *">
                                <option value="">Choose schedule</option>
                                @foreach ($product?->productRates ?? [] as $rate)
                                    <option value="{{ $rate->rate_code->value }}">{{ $rate->display_name }} — ₱{{ number_format((float) $rate->amount, 2) }}</option>
                                @endforeach
                            </flux:select>
                            <flux:input wire:model.live="groups.{{ $index }}.quantity" type="number" min="1" max="50" label="Quantity *" />
                            <flux:input wire:model.live="groups.{{ $index }}.estimated_users" type="number" min="1" label="Estimated users *" />
                            <div class="flex items-end">
                                <flux:button type="button" variant="ghost" class="w-full" wire:click="removeGroup({{ $index }})">Remove</flux:button>
                            </div>
                        </div>
                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            <flux:input wire:model.live="groups.{{ $index }}.check_in_date" type="date" min="{{ today()->toDateString() }}" label="Check-in/use date *" />
                            <flux:input wire:model.live="groups.{{ $index }}.check_out_date" type="date" min="{{ today()->toDateString() }}" label="Check-out/end date *" />
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
                                        <flux:input wire:model="roomOccupants.{{ $roomIndex }}.{{ $occupantIndex }}.first_name" label="First name *" />
                                        <flux:input wire:model="roomOccupants.{{ $roomIndex }}.{{ $occupantIndex }}.middle_name" label="Middle name" />
                                        <flux:input wire:model="roomOccupants.{{ $roomIndex }}.{{ $occupantIndex }}.last_name" label="Last name *" />
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
            <flux:text>Core facility charges must be fully paid to create the booking. GCash reference is required when GCash is selected.</flux:text>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <flux:select wire:model="modeOfPaymentId" label="Mode of payment *">
                    <option value="">Choose payment mode</option>
                    @foreach (ModeOfPayment::query()->orderBy('mode_of_payment')->get() as $mode)
                        <option value="{{ $mode->mode_of_payment_id }}">{{ $mode->mode_of_payment }}</option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="referenceNumber" label="Reference number" />
                <flux:input wire:model="paymentAmount" type="number" step="0.01" label="Core payment" readonly />
            </div>
            <label class="mt-4 flex items-start gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <input wire:model="walkIn" type="checkbox" class="mt-1 rounded border-zinc-300" />
                <span>
                    <span class="block font-medium">Walk-In booking</span>
                    <span class="block text-sm text-zinc-500">Create as immediately active/checked-in after the core payment succeeds.</span>
                </span>
            </label>
        </flux:card>

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <div class="flex justify-end gap-3">
            <flux:button href="{{ route('cashier.bookings.index') }}" variant="ghost" wire:navigate>Cancel</flux:button>
            <flux:button type="submit" variant="primary">Create booking</flux:button>
        </div>
    </form>
</div>
