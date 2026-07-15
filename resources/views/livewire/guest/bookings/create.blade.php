<?php

use App\Models\Booking;
use App\Models\Facility;
use App\Services\PublicBookingSearchService;
use App\Services\PublicBookingWorkflowService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.public')] #[Title('Book a Facility - Olaer Spring Resort')] class extends Component {
    use WithFileUploads;

    public ?int $facility_type_id = null;
    public string $rate_type = '';
    public string $check_in_date = '';
    public string $check_out_date = '';
    public string $check_in_time = '12:00';
    public ?int $facility_id = null;

    public int $total_guest_count = 1;
    public array $extra_guests = [];

    public string $first_name = '';
    public string $middle_name = '';
    public string $last_name = '';
    public string $contact_no = '';
    public string $email = '';
    public string $province = 'Sultan Kudarat';
    public string $city = 'Tacurong City';
    public string $barangay = '';
    public string $purok = '';

    public string $payment_amount = '';
    public string $reference_number = '';
    public $proof_of_payment = null;

    public ?int $created_booking_id = null;
    public ?string $success_message = null;
    public ?string $error_message = null;

    public function mount(): void
    {
        $this->check_in_date = Carbon::today()->toDateString();
        $this->check_out_date = Carbon::tomorrow()->toDateString();
    }

    public function updatedFacilityTypeId(): void
    {
        $this->rate_type = '';
        $this->facility_id = null;
        $this->total_guest_count = 1;
        $this->extra_guests = [];
        $this->payment_amount = '';
    }

    public function updatedRateType(): void
    {
        $this->facility_id = null;
        $this->total_guest_count = 1;
        $this->extra_guests = [];
        $this->payment_amount = '';
    }

    public function updatedCheckInDate(): void
    {
        if ($this->check_out_date <= $this->check_in_date) {
            $this->check_out_date = Carbon::parse($this->check_in_date)->addDay()->toDateString();
        }

        $this->facility_id = null;
        $this->payment_amount = '';
    }

    public function updatedCheckOutDate(): void
    {
        if ($this->check_out_date <= $this->check_in_date) {
            $this->check_out_date = Carbon::parse($this->check_in_date)->addDay()->toDateString();
        }

        $this->facility_id = null;
        $this->payment_amount = '';
    }

    public function updatedFacilityId(): void
    {
        $this->clampTotalGuests();
        $this->syncPaidExtraGuestRows();
        $this->setExactPaymentAmountFromQuote();
    }

    public function updatedTotalGuestCount(): void
    {
        $this->clampTotalGuests();
        $this->syncPaidExtraGuestRows();
        $this->setExactPaymentAmountFromQuote();
    }

    public function save(): void
    {
        $this->success_message = null;
        $this->error_message = null;
        $this->clampTotalGuests();
        $this->syncPaidExtraGuestRows();
        $maxTotalGuests = $this->maxTotalGuests();

        $validated = $this->validate([
            'facility_type_id' => ['required', 'integer', 'exists:tbl_facility_type,facility_type_id'],
            'rate_type' => ['required', 'string', 'max:50'],
            'check_in_date' => ['required', 'date', 'after_or_equal:today'],
            'check_out_date' => ['required', 'date', 'after:check_in_date'],
            'check_in_time' => ['required', 'date_format:H:i'],
            'facility_id' => ['required', 'integer', 'exists:tbl_facility,facility_id'],
            'total_guest_count' => ['required', 'integer', 'min:1', 'max:' . $maxTotalGuests],
            'extra_guests' => ['array'],
            'extra_guests.*.first_name' => ['required', 'string', 'max:50'],
            'extra_guests.*.middle_name' => ['nullable', 'string', 'max:50'],
            'extra_guests.*.last_name' => ['required', 'string', 'max:50'],
            'first_name' => ['required', 'string', 'max:50'],
            'middle_name' => ['nullable', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:50'],
            'contact_no' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:100'],
            'province' => ['required', 'string', 'max:50'],
            'city' => ['required', 'string', 'max:50'],
            'barangay' => ['nullable', 'string', 'max:50'],
            'purok' => ['nullable', 'string', 'max:50'],
            'payment_amount' => ['required', 'numeric', 'min:1'],
            'reference_number' => ['required', 'string', 'max:50'],
            'proof_of_payment' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
        ]);

        $quote = $this->quotePreview();

        if (! $quote) {
            throw ValidationException::withMessages([
                'facility_id' => 'Please select an available facility first.',
            ]);
        }

        if (round((float) $this->payment_amount, 2) !== round((float) $quote['total'], 2)) {
            throw ValidationException::withMessages([
                'payment_amount' => 'Online booking requires exact full GCash payment of ₱' . number_format((float) $quote['total'], 2) . '.',
            ]);
        }

        try {
            $proofPath = $this->proof_of_payment->store('gcash-proofs', 'public');

            $booking = app(PublicBookingWorkflowService::class)
                ->createGuestBookingWithPendingGcash([
                    ...$validated,
                    'proof_of_payment_path' => $proofPath,
                ]);

            $this->created_booking_id = (int) $booking->booking_id;
            $this->success_message = 'Booking submitted. Your payment proof is pending cashier verification.';
        } catch (InvalidArgumentException $exception) {
            $this->error_message = $exception->getMessage();
        }
    }

    public function selectedFacility(): ?Facility
    {
        if (! $this->facility_id) {
            return null;
        }

        return Facility::query()
            ->with(['facilityType', 'prices'])
            ->find($this->facility_id);
    }

    public function maxTotalGuests(): int
    {
        return app(PublicBookingSearchService::class)
            ->maxTotalGuests($this->facility_id);
    }

    public function occupancyPreview(): ?array
    {
        return app(PublicBookingSearchService::class)
            ->occupancyPreview(
                $this->facility_id,
                $this->total_guest_count,
            );
    }

    public function quotePreview(): ?array
    {
        return app(PublicBookingSearchService::class)->quotePreview(
            facilityId: $this->facility_id,
            rateType: $this->rate_type,
            totalGuestCount: $this->total_guest_count,
        );
    }

    public function createdBooking(): ?Booking
    {
        if (! $this->created_booking_id) {
            return null;
        }

        return Booking::query()
            ->with(['guest.address', 'details.facility.facilityType', 'extraGuests', 'payments.modeOfPayment'])
            ->find($this->created_booking_id);
    }

    public function with(): array
    {
        $search = app(PublicBookingSearchService::class);

        return [
            'facilityTypes' => $search->facilityTypes(),
            'rateTypes' => $search->rateTypesForFacilityType($this->facility_type_id),
            'availableFacilities' => $search->availableFacilities(
                $this->facility_type_id,
                $this->rate_type,
                $this->check_in_date,
                $this->check_out_date,
            ),
            'selectedFacility' => $this->selectedFacility(),
            'maxTotalGuests' => $this->maxTotalGuests(),
            'occupancy' => $this->occupancyPreview(),
            'quote' => $this->quotePreview(),
            'createdBooking' => $this->createdBooking(),
        ];
    }

    private function clampTotalGuests(): void
    {
        $max = $this->maxTotalGuests();
        $this->total_guest_count = max(
            1,
            min($max, (int) $this->total_guest_count),
        );
    }

    private function syncPaidExtraGuestRows(): void
    {
        $count = (int) (
            $this->occupancyPreview()['paid_extra_guest_count']
            ?? 0
        );
        $current = $this->extra_guests;
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[$i] = $current[$i] ?? [
                'first_name' => '',
                'middle_name' => '',
                'last_name' => '',
            ];
        }

        $this->extra_guests = $rows;
    }

    private function setExactPaymentAmountFromQuote(): void
    {
        $quote = $this->quotePreview();

        if ($quote) {
            $this->payment_amount = number_format((float) $quote['total'], 2, '.', '');
        }
    }
};
?>

    <section class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-emerald-600">Olaer Spring Resort</p>
                <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-950 dark:text-white">Book a facility</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                    Booking requires full GCash payment. The submitted proof will be reviewed by the cashier before the booking is treated as fully paid.
                </p>
            </div>
            <a href="{{ route('guest.home') }}" class="text-sm font-medium text-emerald-700 hover:underline dark:text-emerald-400">Back to homepage</a>
        </div>

        @if ($success_message && $createdBooking)
            <div class="mb-8 rounded-2xl border border-emerald-200 bg-emerald-50 p-6 dark:border-emerald-900 dark:bg-emerald-950/40">
                <h2 class="text-xl font-semibold text-emerald-950 dark:text-emerald-100">Booking submitted</h2>
                <p class="mt-2 text-sm text-emerald-800 dark:text-emerald-200">{{ $success_message }}</p>

                <div class="mt-6 grid gap-4 rounded-xl bg-white p-4 text-sm dark:bg-zinc-950 md:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <p class="text-zinc-500">Booking reference</p>
                        <p class="font-semibold text-zinc-950 dark:text-white">{{ $createdBooking->b_ref_no }}</p>
                    </div>
                    <div>
                        <p class="text-zinc-500">Guest</p>
                        <p class="font-semibold text-zinc-950 dark:text-white">{{ $createdBooking->guest->first_name }} {{ $createdBooking->guest->last_name }}</p>
                    </div>
                    <div>
                        <p class="text-zinc-500">Facility</p>
                        <p class="font-semibold text-zinc-950 dark:text-white">{{ optional($createdBooking->details->first()?->facility)->facility_name }}</p>
                    </div>
                    <div>
                        <p class="text-zinc-500">Status</p>
                        <p class="font-semibold text-zinc-950 dark:text-white">{{ $createdBooking->status }}</p>
                    </div>
                    <div>
                        <p class="text-zinc-500">Total</p>
                        <p class="font-semibold text-zinc-950 dark:text-white">₱{{ number_format((float) $createdBooking->total_price, 2) }}</p>
                    </div>
                    <div>
                        <p class="text-zinc-500">Amount due until verified</p>
                        <p class="font-semibold text-zinc-950 dark:text-white">₱{{ number_format((float) $createdBooking->amount_due, 2) }}</p>
                    </div>
                    <div>
                        <p class="text-zinc-500">GCash reference</p>
                        <p class="font-semibold text-zinc-950 dark:text-white">{{ optional($createdBooking->payments->first())->reference_number }}</p>
                    </div>
                    <div>
                        <p class="text-zinc-500">Payment status</p>
                        <p class="font-semibold text-zinc-950 dark:text-white">{{ optional($createdBooking->payments->first())->payment_status }}</p>
                    </div>
                </div>

                <p class="mt-4 text-sm text-emerald-800 dark:text-emerald-200">
                    Save this reference number. The cashier will use it to verify your payment and booking.
                </p>
            </div>
        @endif

        @if ($error_message)
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                {{ $error_message }}
            </div>
        @endif

        <form wire:submit="save" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px]">
            <div class="space-y-6">
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <h2 class="text-lg font-semibold text-zinc-950 dark:text-white">1. Choose schedule and facility</h2>

                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        <flux:input wire:model.live="check_in_date" type="date" label="Check-in date" />
                        <flux:input wire:model.live="check_out_date" type="date" label="Check-out date" />
                        <flux:input wire:model="check_in_time" type="time" label="Expected check-in time" />

                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Facility type</label>
                            <select wire:model.live="facility_type_id" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white">
                                <option value="">Select type</option>
                                @foreach ($facilityTypes as $type)
                                    <option value="{{ $type->facility_type_id }}">{{ $type->facility_type }}</option>
                                @endforeach
                            </select>
                            @error('facility_type_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Rate type</label>
                            <select wire:model.live="rate_type" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white">
                                <option value="">Select rate</option>
                                @foreach ($rateTypes as $rate)
                                    <option value="{{ $rate }}">{{ $rate }}</option>
                                @endforeach
                            </select>
                            @error('rate_type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Available facility</label>
                            <select wire:model.live="facility_id" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white">
                                <option value="">Select facility</option>
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
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <h2 class="text-lg font-semibold text-zinc-950 dark:text-white">2. Extra guests</h2>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                        Paid extra guests are only supported for rooms. Cottages and function halls use their selected facility capacity.
                    </p>

                    <div class="mt-5 max-w-sm">
                        <flux:input wire:model.live="total_guest_count" type="number" min="1" max="{{ $maxTotalGuests }}" label="Total guests, including primary guest" />
                        <p class="mt-1 text-xs text-zinc-500">Selected facility capacity: {{ $maxTotalGuests }}</p>
                        @error('total_guest_count') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

                        @if ($occupancy)
                            <p class="mt-2 text-xs text-zinc-500">
                                Included guests: {{ $occupancy['included_guest_count'] }} · Paid room extras: {{ $occupancy['paid_extra_guest_count'] }}
                            </p>
                        @endif
                    </div>

                    @if ($extra_guests !== [])
                        <div class="mt-5 grid gap-4">
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

                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <h2 class="text-lg font-semibold text-zinc-950 dark:text-white">3. Guest details</h2>

                    <div class="mt-5 grid gap-4 md:grid-cols-3">
                        <flux:input wire:model="first_name" label="First name" />
                        <flux:input wire:model="middle_name" label="Middle name" />
                        <flux:input wire:model="last_name" label="Last name" />
                    </div>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="contact_no" label="Contact number" />
                        <flux:input wire:model="email" type="email" label="Email" />
                    </div>
                    <div class="mt-4 grid gap-4 md:grid-cols-4">
                        <flux:input wire:model="province" label="Province" />
                        <flux:input wire:model="city" label="City" />
                        <flux:input wire:model="barangay" label="Barangay" />
                        <flux:input wire:model="purok" label="Purok / Street" />
                    </div>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <h2 class="text-lg font-semibold text-zinc-950 dark:text-white">4. GCash payment</h2>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                        Enter the exact amount and upload your payment proof. The cashier will verify it before check-in.
                    </p>

                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="payment_amount" type="number" step="0.01" min="1" label="GCash amount paid" />
                        <flux:input wire:model="reference_number" label="GCash reference number" />
                    </div>

                    <div class="mt-4">
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Proof of payment</label>
                        <input wire:model="proof_of_payment" type="file" accept="image/jpeg,image/png,application/pdf" class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white" />
                        <p class="mt-1 text-xs text-zinc-500">Accepted: JPG, PNG, PDF. Maximum 4 MB.</p>
                        <div wire:loading wire:target="proof_of_payment" class="mt-2 text-sm text-zinc-500">Uploading...</div>
                        @error('proof_of_payment') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="sticky top-6 h-fit rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <h2 class="text-lg font-semibold text-zinc-950 dark:text-white">Booking summary</h2>

                @if ($selectedFacility)
                    <div class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-4">
                            <span class="text-zinc-500">Facility</span>
                            <span class="font-medium text-zinc-950 dark:text-white">{{ $selectedFacility->facility_name }}</span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-zinc-500">Type</span>
                            <span class="font-medium text-zinc-950 dark:text-white">{{ optional($selectedFacility->facilityType)->facility_type }}</span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-zinc-500">Capacity</span>
                            <span class="font-medium text-zinc-950 dark:text-white">{{ $selectedFacility->capacity }}</span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-zinc-500">Schedule</span>
                            <span class="font-medium text-zinc-950 dark:text-white">{{ $check_in_date }} to {{ $check_out_date }}</span>
                        </div>
                    </div>
                @else
                    <p class="mt-4 text-sm text-zinc-500">Select a facility to see the quote.</p>
                @endif

                @if ($quote)
                    <div class="mt-6 rounded-xl bg-zinc-50 p-4 text-sm text-zinc-700 dark:bg-zinc-950 dark:text-zinc-300">
                        <div class="flex justify-between">
                            <span>Base price</span>
                            <span>₱{{ number_format((float) $quote['base_price'], 2) }}</span>
                        </div>
                        <div class="mt-2 flex justify-between">
                            <span>Extra guest fee</span>
                            <span>₱{{ number_format((float) $quote['extra_guest_fee'], 2) }}</span>
                        </div>
                        <div class="mt-3 border-t border-zinc-200 pt-3 text-base font-semibold dark:border-zinc-800">
                            <div class="flex justify-between">
                                <span>Total</span>
                                <span>₱{{ number_format((float) $quote['total'], 2) }}</span>
                            </div>
                        </div>
                    </div>
                @endif

                <flux:button type="submit" variant="primary" class="mt-6 w-full" wire:loading.attr="disabled">
                    Submit booking
                </flux:button>

                <p class="mt-3 text-xs leading-5 text-zinc-500">
                    Submission creates a pending booking. It blocks the selected facility from conflicting bookings while the cashier reviews your proof.
                </p>
            </div>
        </form>
    </section>
