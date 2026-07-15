<?php

use App\Models\Reservation;
use App\Services\PublicFacilitySearchService;
use App\Services\PublicReservationWorkflowService;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.public')] #[Title('Create Reservation - Olaer Spring Resort')] class extends Component
{
    public string $first_name = '';
    public string $middle_name = '';
    public string $last_name = '';
    public string $email = '';
    public string $contact_no = '';
    public string $province = 'Sultan Kudarat';
    public string $city = 'Tacurong City';
    public string $barangay = '';
    public string $purok = '';

    public ?int $facility_type_id = null;
    public string $rate_type = '';
    public string $check_in_date = '';
    public string $check_out_date = '';
    public ?int $facility_id = null;

    public int $total_guest_count = 1;
    public array $extra_guests = [];

    public ?int $created_reservation_id = null;

    public function mount(): void
    {
        $this->check_in_date = now()->toDateString();
        $this->check_out_date = now()->toDateString();
        $this->syncPaidExtraGuestRows();
    }

    public function updatedFacilityTypeId(): void
    {
        $this->rate_type = '';
        $this->facility_id = null;
    }

    public function updatedRateType(): void
    {
        $this->facility_id = null;
    }

    public function updatedCheckInDate(): void
    {
        $this->facility_id = null;
    }

    public function updatedCheckOutDate(): void
    {
        $this->facility_id = null;
    }

    public function updatedFacilityId(): void
    {
        $this->clampTotalGuests();
        $this->syncPaidExtraGuestRows();
    }

    public function updatedTotalGuestCount(): void
    {
        $this->clampTotalGuests();
        $this->syncPaidExtraGuestRows();
    }

    public function save(PublicReservationWorkflowService $service): void
    {
        $this->clampTotalGuests();
        $this->syncPaidExtraGuestRows();

        $validated = $this->validate($this->rules(), $this->messages());

        $reservation = $service->createGuestReservation($validated);

        $this->created_reservation_id = (int) $reservation->reservation_id;

        $this->resetFormButKeepConfirmation();
    }

    public function createAnother(): void
    {
        $this->created_reservation_id = null;
        $this->resetFormButKeepConfirmation();
    }

    public function with(): array
    {
        $search = app(PublicFacilitySearchService::class);
        $createdReservation = null;

        if ($this->created_reservation_id) {
            $createdReservation = Reservation::query()
                ->with(['guest.address', 'details.facility.facilityType', 'details.discount', 'extraGuests'])
                ->find($this->created_reservation_id);
        }

        return [
            'facilityTypes' => $search->facilityTypes(),
            'rateTypes' => $search->rateTypesForFacilityType($this->facility_type_id),
            'availableFacilities' => $this->loadAvailableFacilities($search),
            'quote' => $this->loadQuotePreview($search),
            'createdReservation' => $createdReservation,
        ];
    }

    protected function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:50'],
            'middle_name' => ['nullable', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:50'],
            'contact_no' => ['required', 'string', 'max:20'],
            'province' => ['required', 'string', 'max:50'],
            'city' => ['required', 'string', 'max:50'],
            'barangay' => ['nullable', 'string', 'max:50'],
            'purok' => ['nullable', 'string', 'max:50'],
            'facility_type_id' => ['required', 'integer', 'exists:tbl_facility_type,facility_type_id'],
            'rate_type' => ['required', 'string', 'max:20'],
            'check_in_date' => ['required', 'date', 'after_or_equal:today'],
            'check_out_date' => ['required', 'date', 'after_or_equal:check_in_date'],
            'facility_id' => [
                'required',
                'integer',
                Rule::exists('tbl_facility', 'facility_id'),
            ],
            'total_guest_count' => [
                'required',
                'integer',
                'min:1',
                'max:'.$this->maxTotalGuests(),
            ],
            'extra_guests' => ['array'],
            'extra_guests.*.first_name' => ['required', 'string', 'max:50'],
            'extra_guests.*.middle_name' => ['nullable', 'string', 'max:50'],
            'extra_guests.*.last_name' => ['required', 'string', 'max:50'],
        ];
    }

    protected function messages(): array
    {
        return [
            'facility_type_id.required' => 'Choose a facility type.',
            'facility_id.required' => 'Choose an available facility.',
            'check_out_date.after_or_equal' => 'Check-out date cannot be before check-in date.',
        ];
    }

    public function maxTotalGuests(): int
    {
        return app(PublicFacilitySearchService::class)
            ->maxTotalGuests($this->facility_id);
    }

    public function occupancyPreview(): ?array
    {
        return app(PublicFacilitySearchService::class)
            ->occupancyPreview(
                $this->facility_id,
                $this->total_guest_count,
            );
    }

    private function clampTotalGuests(): void
    {
        $this->total_guest_count = max(
            1,
            min(
                $this->maxTotalGuests(),
                (int) $this->total_guest_count,
            ),
        );
    }

    private function syncPaidExtraGuestRows(): void
    {
        $rows = [];
        $paidExtraGuestCount = (int) (
            $this->occupancyPreview()['paid_extra_guest_count']
            ?? 0
        );

        for ($index = 0; $index < $paidExtraGuestCount; $index++) {
            $existing = $this->extra_guests[$index] ?? [];

            $rows[$index] = [
                'first_name' => $existing['first_name'] ?? '',
                'middle_name' => $existing['middle_name'] ?? '',
                'last_name' => $existing['last_name'] ?? '',
            ];
        }

        $this->extra_guests = $rows;
    }

    private function loadAvailableFacilities(PublicFacilitySearchService $search): Collection
    {
        try {
            return $search->availableFacilities(
                $this->facility_type_id,
                $this->rate_type !== '' ? $this->rate_type : null,
                $this->check_in_date !== '' ? $this->check_in_date : null,
                $this->check_out_date !== '' ? $this->check_out_date : null,
            );
        } catch (Throwable) {
            return collect();
        }
    }

    private function loadQuotePreview(PublicFacilitySearchService $search): ?array
    {
        try {
            return $search->quotePreview(
                $this->facility_id,
                $this->rate_type !== '' ? $this->rate_type : null,
                $this->check_in_date !== '' ? $this->check_in_date : null,
                $this->check_out_date !== '' ? $this->check_out_date : null,
                0,
                $this->total_guest_count,
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function resetFormButKeepConfirmation(): void
    {
        $this->first_name = '';
        $this->middle_name = '';
        $this->last_name = '';
        $this->email = '';
        $this->contact_no = '';
        $this->province = 'Sultan Kudarat';
        $this->city = 'Tacurong City';
        $this->barangay = '';
        $this->purok = '';
        $this->facility_type_id = null;
        $this->rate_type = '';
        $this->check_in_date = now()->toDateString();
        $this->check_out_date = now()->toDateString();
        $this->facility_id = null;
        $this->total_guest_count = 1;
        $this->extra_guests = [];
        $this->syncPaidExtraGuestRows();
        $this->resetValidation();
    }
};

?>

<section class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    <div class="mb-8">
        <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Guest Reservation</p>
        <h1 class="mt-1 text-3xl font-bold tracking-tight text-zinc-950 dark:text-white">Create a facility reservation</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-600 dark:text-zinc-300">
            This creates a temporary hold for the selected facility. Full booking/payment verification is still handled by the cashier.
        </p>
    </div>

    @if ($createdReservation)
        @php($detail = $createdReservation->details->first())

        <div class="mb-8 rounded-2xl border border-emerald-200 bg-emerald-50 p-6 dark:border-emerald-900 dark:bg-emerald-950/40">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">Reservation created</p>
                    <h2 class="mt-1 text-2xl font-bold text-emerald-950 dark:text-emerald-50">Reference: {{ $createdReservation->r_ref_no }}</h2>
                    <p class="mt-2 text-sm text-emerald-800 dark:text-emerald-200">
                        Save this reference number. Present it to the cashier when verifying or paying for the reservation.
                    </p>
                </div>

                <div class="flex gap-2 print:hidden">
                    <flux:button type="button" variant="primary" onclick="window.print()">Print slip</flux:button>
                    <flux:button type="button" variant="subtle" wire:click="createAnother">Create another</flux:button>
                </div>
            </div>

            <div class="mt-6 grid gap-4 rounded-xl bg-white p-4 text-sm dark:bg-zinc-950 md:grid-cols-2 lg:grid-cols-4">
                <div>
                    <div class="text-zinc-500 dark:text-zinc-400">Guest</div>
                    <div class="font-medium">{{ $createdReservation->guest?->full_name }}</div>
                    <div class="text-zinc-500 dark:text-zinc-400">{{ $createdReservation->guest?->email }}</div>
                </div>
                <div>
                    <div class="text-zinc-500 dark:text-zinc-400">Facility</div>
                    <div class="font-medium">{{ $detail?->facility?->facility_name }}</div>
                    <div class="text-zinc-500 dark:text-zinc-400">{{ $detail?->facility?->facilityType?->facility_type }} / {{ $detail?->rate_type }}</div>
                </div>
                <div>
                    <div class="text-zinc-500 dark:text-zinc-400">Schedule</div>
                    <div class="font-medium">{{ optional($detail?->check_in_date)->format('M d, Y') }}</div>
                    <div class="text-zinc-500 dark:text-zinc-400">to {{ optional($detail?->check_out_date)->format('M d, Y') }}</div>
                </div>
                <div>
                    <div class="text-zinc-500 dark:text-zinc-400">Estimated total</div>
                    <div class="font-medium">₱{{ number_format((float) $createdReservation->total_price, 2) }}</div>
                    <div class="text-zinc-500 dark:text-zinc-400">Status: {{ $createdReservation->status }}</div>
                </div>
            </div>
        </div>
    @endif

    <form wire:submit="save" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px]">
        <div class="space-y-6">
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <h2 class="text-lg font-semibold">Guest information</h2>
                <div class="mt-5 grid gap-4 md:grid-cols-3">
                    <flux:input wire:model="first_name" label="First name" />
                    <flux:input wire:model="middle_name" label="Middle name" />
                    <flux:input wire:model="last_name" label="Last name" />
                </div>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <flux:input wire:model="email" type="email" label="Email" />
                    <flux:input wire:model="contact_no" label="Contact number" />
                </div>
                <div class="mt-4 grid gap-4 md:grid-cols-4">
                    <flux:input wire:model="province" label="Province" />
                    <flux:input wire:model="city" label="City/Municipality" />
                    <flux:input wire:model="barangay" label="Barangay" />
                    <flux:input wire:model="purok" label="Purok/Street" />
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <h2 class="text-lg font-semibold">Reservation details</h2>

                <div class="mt-5 grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Facility type</label>
                        <select wire:model.live="facility_type_id" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-zinc-700 dark:bg-zinc-950">
                            <option value="">Choose type</option>
                            @foreach ($facilityTypes as $type)
                                <option value="{{ $type->facility_type_id }}">{{ $type->facility_type }}</option>
                            @endforeach
                        </select>
                        @error('facility_type_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">Rate type</label>
                        <select wire:model.live="rate_type" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-zinc-700 dark:bg-zinc-950">
                            <option value="">Choose rate</option>
                            @foreach ($rateTypes as $rate)
                                <option value="{{ $rate }}">{{ $rate }}</option>
                            @endforeach
                        </select>
                        @error('rate_type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <flux:input wire:model.live="check_in_date" type="date" label="Check-in / use date" />
                    <flux:input wire:model.live="check_out_date" type="date" label="Check-out / end date" />
                </div>

                <div class="mt-4">
                    <label class="mb-1 block text-sm font-medium">Available facility</label>
                    <select wire:model.live="facility_id" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-zinc-700 dark:bg-zinc-950">
                        <option value="">Choose available facility</option>
                        @foreach ($availableFacilities as $facility)
                            <option value="{{ $facility->facility_id }}">
                                {{ $facility->facility_name }} — {{ $facility->facility_size }} — capacity {{ $facility->capacity }}
                            </option>
                        @endforeach
                    </select>
                    @error('facility_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    @if ($facility_type_id && $rate_type && $check_in_date && $check_out_date && $availableFacilities->isEmpty())
                        <p class="mt-2 text-sm text-amber-600">No available facility matches this date and rate. Try another date or rate type.</p>
                    @endif
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <h2 class="text-lg font-semibold">Guest capacity</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Enter the complete party size. Rooms include 4 guests and charge ₱100 only for guests above 4. Cottages and function halls have no extra-guest charge but cannot exceed capacity.
                </p>

                <div class="mt-4 max-w-xs">
                    <flux:input wire:model.live="total_guest_count" type="number" min="1" max="{{ $this->maxTotalGuests() }}" label="Total guests, including primary guest" />
                </div>

                @if ($this->occupancyPreview())
                    @php($occupancy = $this->occupancyPreview())
                    <p class="mt-2 text-xs text-zinc-500">
                        Capacity: {{ $occupancy['capacity'] }} · Included: {{ $occupancy['included_guest_count'] }} · Paid room extras: {{ $occupancy['paid_extra_guest_count'] }}
                    </p>
                @endif

                @if ($extra_guests !== [])
                    <div class="mt-5 space-y-3">
                        @foreach ($extra_guests as $index => $extraGuest)
                            <div class="grid gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-800 md:grid-cols-3">
                                <flux:input wire:model="extra_guests.{{ $index }}.first_name" label="Extra guest {{ $index + 1 }} first name" />
                                <flux:input wire:model="extra_guests.{{ $index }}.middle_name" label="Middle name" />
                                <flux:input wire:model="extra_guests.{{ $index }}.last_name" label="Last name" />
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <aside class="space-y-4">
            <div class="sticky top-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <h2 class="text-lg font-semibold">Reservation summary</h2>

                @if ($quote)
                    <dl class="mt-5 space-y-3 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-zinc-500 dark:text-zinc-400">Facility</dt>
                            <dd class="font-medium text-right">{{ $quote['facility_name'] }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-zinc-500 dark:text-zinc-400">Rate type</dt>
                            <dd class="font-medium">{{ $quote['rate_type'] }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-zinc-500 dark:text-zinc-400">Base price</dt>
                            <dd class="font-medium">₱{{ number_format((float) $quote['base_price'], 2) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-zinc-500 dark:text-zinc-400">Extra guest charge</dt>
                            <dd class="font-medium">₱{{ number_format((float) $quote['extra_guest_charge'], 2) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 border-t border-zinc-200 pt-3 text-base dark:border-zinc-800">
                            <dt class="font-semibold">Estimated total</dt>
                            <dd class="font-bold">₱{{ number_format((float) $quote['total_price'], 2) }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">Complete the reservation details to see the estimated amount.</p>
                @endif

                <div class="mt-6 rounded-xl bg-zinc-50 p-4 text-sm text-zinc-600 dark:bg-zinc-950 dark:text-zinc-300">
                    <p class="font-medium text-zinc-900 dark:text-white">Before submitting</p>
                    <p class="mt-1">This is a reservation hold only. The cashier still verifies and handles payment/booking confirmation.</p>
                </div>

                <flux:button type="submit" variant="primary" class="mt-6 w-full">Submit reservation</flux:button>
            </div>
        </aside>
    </form>
</section>
