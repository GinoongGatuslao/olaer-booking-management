<?php

use App\Models\BookingDetail;
use App\Models\Discount;
use App\Models\Facility;
use App\Models\FacilityPrice;
use App\Models\ModeOfPayment;
use App\Services\BookingQuoteService;
use App\Services\BookingWorkflowService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $sortField = 'booking_id';
    public string $sortDirection = 'desc';
    public bool $showCreateForm = false;

    public array $form = [
        'first_name' => '',
        'middle_name' => '',
        'last_name' => '',
        'contact_no' => '',
        'email' => '',
        'province' => 'Sultan Kudarat',
        'city' => 'Tacurong City',
        'barangay' => '',
        'purok' => '',
        'facility_id' => '',
        'rate_type' => '',
        'discount_id' => '',
        'check_in_date' => '',
        'check_out_date' => '',
        'check_in_time' => '12:00',
        'mode_of_payment_id' => '',
        'reference_number' => '',
        'payment_amount' => '',
    ];

    public array $extraGuests = [];

    public array $rescheduleForm = [
        'booking_details_id' => '',
        'label' => '',
        'new_check_in_date' => '',
    ];

    public array $transferForm = [
        'booking_details_id' => '',
        'label' => '',
        'new_facility_id' => '',
    ];

    public function mount(): void
    {
        $this->form['check_in_date'] = now()->toDateString();
        $this->form['check_out_date'] = now()->addDay()->toDateString();
        $this->form['mode_of_payment_id'] = (string) (ModeOfPayment::query()->where('mode_of_payment', 'Cash')->value('mode_of_payment_id') ?? '');
    }

    public function with(): array
    {
        return [
            'bookings' => $this->bookings(),
            'facilities' => $this->facilities(),
            'rateTypes' => $this->rateTypes(),
            'discounts' => $this->discounts(),
            'paymentModes' => ModeOfPayment::query()->orderBy('mode_of_payment')->get(),
            'transferFacilities' => $this->transferFacilities(),
            'currentQuote' => $this->currentQuote(),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFormFacilityId(): void
    {
        $this->form['rate_type'] = '';
        $this->form['discount_id'] = '';
        $this->form['payment_amount'] = '';
    }

    public function updatedFormRateType(): void
    {
        $this->form['payment_amount'] = '';
    }

    public function updatedFormDiscountId(): void
    {
        $this->form['payment_amount'] = '';
    }

    public function sortBy(string $field): void
    {
        $allowed = ['booking_id', 'b_ref_no', 'booking_date', 'guest_name', 'facility_name', 'total_price', 'amount_due', 'status'];

        if (! in_array($field, $allowed, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function toggleCreateForm(): void
    {
        $this->showCreateForm = ! $this->showCreateForm;
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
        unset($this->extraGuests[$index]);
        $this->extraGuests = array_values($this->extraGuests);
        $this->form['payment_amount'] = '';
    }

    public function useQuotedAmount(): void
    {
        $quote = $this->currentQuote();

        if ($quote !== null) {
            $this->form['payment_amount'] = number_format((float) $quote['total'], 2, '.', '');
        }
    }

    public function createBooking(BookingWorkflowService $bookingWorkflow): void
    {
        $validated = $this->validate($this->createRules());

        try {
            $payload = $validated['form'];
            $payload['extra_guests'] = $this->extraGuests;
            $payload['user_id'] = Auth::id();

            $bookingWorkflow->createBooking($payload);

            $this->resetCreateForm();
            $this->showCreateForm = false;
            $this->resetPage();
            session()->flash('success', 'Booking created and payment recorded successfully.');
        } catch (Throwable $exception) {
            $this->addError('booking', $exception->getMessage());
        }
    }

    public function openReschedule(int $bookingDetailsId): void
    {
        $detail = BookingDetail::query()->with(['booking.guest', 'facility'])->findOrFail($bookingDetailsId);

        $this->rescheduleForm = [
            'booking_details_id' => (string) $bookingDetailsId,
            'label' => $detail->booking->b_ref_no . ' - ' . $detail->facility->facility_name,
            'new_check_in_date' => (string) $detail->check_in_date,
        ];
    }

    public function cancelReschedule(): void
    {
        $this->rescheduleForm = [
            'booking_details_id' => '',
            'label' => '',
            'new_check_in_date' => '',
        ];
    }

    public function saveReschedule(BookingWorkflowService $bookingWorkflow): void
    {
        $validated = $this->validate([
            'rescheduleForm.booking_details_id' => ['required', 'integer', 'exists:tbl_booking_details,booking_details_id'],
            'rescheduleForm.new_check_in_date' => ['required', 'date', 'after_or_equal:today'],
        ]);

        try {
            $bookingWorkflow->rescheduleBookingDetail(
                (int) $validated['rescheduleForm']['booking_details_id'],
                $validated['rescheduleForm']['new_check_in_date']
            );

            $this->cancelReschedule();
            $this->resetPage();
            session()->flash('success', 'Booking rescheduled successfully.');
        } catch (Throwable $exception) {
            $this->addError('reschedule', $exception->getMessage());
        }
    }

    public function openTransfer(int $bookingDetailsId): void
    {
        $detail = BookingDetail::query()->with(['booking.guest', 'facility'])->findOrFail($bookingDetailsId);

        $this->transferForm = [
            'booking_details_id' => (string) $bookingDetailsId,
            'label' => $detail->booking->b_ref_no . ' - ' . $detail->facility->facility_name,
            'new_facility_id' => '',
        ];
    }

    public function cancelTransfer(): void
    {
        $this->transferForm = [
            'booking_details_id' => '',
            'label' => '',
            'new_facility_id' => '',
        ];
    }

    public function saveTransfer(BookingWorkflowService $bookingWorkflow): void
    {
        $validated = $this->validate([
            'transferForm.booking_details_id' => ['required', 'integer', 'exists:tbl_booking_details,booking_details_id'],
            'transferForm.new_facility_id' => ['required', 'integer', 'exists:tbl_facility,facility_id'],
        ]);

        try {
            $bookingWorkflow->transferBookingDetail(
                (int) $validated['transferForm']['booking_details_id'],
                (int) $validated['transferForm']['new_facility_id']
            );

            $this->cancelTransfer();
            $this->resetPage();
            session()->flash('success', 'Facility transfer saved successfully. If there was an upgrade charge, it was added to amount due.');
        } catch (Throwable $exception) {
            $this->addError('transfer', $exception->getMessage());
        }
    }

    public function extendBooking(int $bookingDetailsId, BookingWorkflowService $bookingWorkflow): void
    {
        try {
            $bookingWorkflow->extendCottageDayRate($bookingDetailsId);
            $this->resetPage();
            session()->flash('success', 'Cottage extension recorded. The extension charge was added to amount due.');
        } catch (Throwable $exception) {
            $this->addError('extend', $exception->getMessage());
        }
    }

    private function createRules(): array
    {
        return [
            'form.first_name' => ['required', 'string', 'max:50'],
            'form.middle_name' => ['nullable', 'string', 'max:50'],
            'form.last_name' => ['required', 'string', 'max:50'],
            'form.contact_no' => ['required', 'string', 'max:20'],
            'form.email' => ['nullable', 'email', 'max:100'],
            'form.province' => ['required', 'string', 'max:50'],
            'form.city' => ['required', 'string', 'max:50'],
            'form.barangay' => ['nullable', 'string', 'max:50'],
            'form.purok' => ['nullable', 'string', 'max:50'],
            'form.facility_id' => ['required', 'integer', 'exists:tbl_facility,facility_id'],
            'form.rate_type' => ['required', 'string', 'max:50'],
            'form.discount_id' => ['nullable', 'integer', 'exists:tbl_discount,discount_id'],
            'form.check_in_date' => ['required', 'date', 'after_or_equal:today'],
            'form.check_out_date' => ['required', 'date', 'after:form.check_in_date'],
            'form.check_in_time' => ['required', 'date_format:H:i'],
            'form.mode_of_payment_id' => ['required', 'integer', 'exists:tbl_mode_of_payment,mode_of_payment_id'],
            'form.reference_number' => ['nullable', 'string', 'max:100'],
            'form.payment_amount' => ['required', 'numeric', 'min:0'],
            'extraGuests.*.first_name' => ['nullable', 'string', 'max:50'],
            'extraGuests.*.middle_name' => ['nullable', 'string', 'max:50'],
            'extraGuests.*.last_name' => ['nullable', 'string', 'max:50'],
        ];
    }

    private function resetCreateForm(): void
    {
        $this->form = [
            'first_name' => '',
            'middle_name' => '',
            'last_name' => '',
            'contact_no' => '',
            'email' => '',
            'province' => 'Sultan Kudarat',
            'city' => 'Tacurong City',
            'barangay' => '',
            'purok' => '',
            'facility_id' => '',
            'rate_type' => '',
            'discount_id' => '',
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDay()->toDateString(),
            'check_in_time' => '12:00',
            'mode_of_payment_id' => (string) (ModeOfPayment::query()->where('mode_of_payment', 'Cash')->value('mode_of_payment_id') ?? ''),
            'reference_number' => '',
            'payment_amount' => '',
        ];

        $this->extraGuests = [];
        $this->resetErrorBag();
    }

    private function bookings()
    {
        $query = DB::table('tbl_booking')
            ->join('tbl_guest', 'tbl_guest.guest_id', '=', 'tbl_booking.guest_id')
            ->leftJoin('tbl_booking_details', 'tbl_booking_details.booking_id', '=', 'tbl_booking.booking_id')
            ->leftJoin('tbl_facility', 'tbl_facility.facility_id', '=', 'tbl_booking_details.facility_id')
            ->select([
                'tbl_booking.booking_id',
                'tbl_booking.b_ref_no',
                'tbl_booking.booking_date',
                'tbl_booking.total_price',
                'tbl_booking.amount_due',
                'tbl_booking.no_of_extra_guests',
                'tbl_booking_details.booking_details_id',
                'tbl_booking_details.rate_type',
                'tbl_booking_details.check_in_date',
                'tbl_booking_details.check_out_date',
                'tbl_booking_details.status',
                'tbl_facility.facility_name',
                DB::raw("CONCAT(tbl_guest.first_name, ' ', COALESCE(tbl_guest.middle_name, ''), ' ', tbl_guest.last_name) as guest_name"),
            ]);

        if ($this->search !== '') {
            $search = '%' . $this->search . '%';
            $query->where(function ($subQuery) use ($search): void {
                $subQuery->where('tbl_booking.b_ref_no', 'like', $search)
                    ->orWhere('tbl_guest.first_name', 'like', $search)
                    ->orWhere('tbl_guest.last_name', 'like', $search)
                    ->orWhere('tbl_facility.facility_name', 'like', $search);
            });
        }

        $sortMap = [
            'booking_id' => 'tbl_booking.booking_id',
            'b_ref_no' => 'tbl_booking.b_ref_no',
            'booking_date' => 'tbl_booking.booking_date',
            'guest_name' => 'guest_name',
            'facility_name' => 'tbl_facility.facility_name',
            'total_price' => 'tbl_booking.total_price',
            'amount_due' => 'tbl_booking.amount_due',
            'status' => 'tbl_booking_details.status',
        ];

        $query->orderBy($sortMap[$this->sortField] ?? 'tbl_booking.booking_id', $this->sortDirection);

        return $query->paginate(10);
    }

    private function facilities()
    {
        return Facility::query()
            ->with('facilityType')
            ->whereIn('facility_status', ['Available', 'available'])
            ->orderBy('facility_name')
            ->get();
    }

    private function transferFacilities()
    {
        if ($this->transferForm['booking_details_id'] === '') {
            return collect();
        }

        $detail = BookingDetail::query()->with('facility')->find((int) $this->transferForm['booking_details_id']);

        if (! $detail || ! $detail->facility) {
            return collect();
        }

        return Facility::query()
            ->where('facility_type_id', $detail->facility->facility_type_id)
            ->where('facility_id', '!=', $detail->facility_id)
            ->whereIn('facility_status', ['Available', 'available'])
            ->orderBy('facility_name')
            ->get();
    }

    private function rateTypes()
    {
        if ($this->form['facility_id'] === '') {
            return collect();
        }

        return FacilityPrice::query()
            ->where('facility_id', (int) $this->form['facility_id'])
            ->orderBy('rate_type')
            ->get();
    }

    private function discounts()
    {
        return Discount::query()
            ->where('status', 'Active')
            ->orderBy('discount_name')
            ->get();
    }

    private function currentQuote(): ?array
    {
        if ($this->form['facility_id'] === '' || $this->form['rate_type'] === '') {
            return null;
        }

        try {
            return app(BookingQuoteService::class)->quote(
                (int) $this->form['facility_id'],
                (string) $this->form['rate_type'],
                count($this->extraGuests),
                $this->form['discount_id'] !== '' ? (int) $this->form['discount_id'] : null
            );
        } catch (Throwable) {
            return null;
        }
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">Booking Management</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">Create paid facility bookings, reschedule bookings, transfer facilities, and extend cottage day-rate bookings.</p>
        </div>

        <flux:button variant="primary" wire:click="toggleCreateForm">
            {{ $showCreateForm ? 'Hide Form' : 'Add Booking' }}
        </flux:button>
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @error('booking')
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ $message }}</div>
    @enderror

    @error('reschedule')
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ $message }}</div>
    @enderror

    @error('transfer')
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ $message }}</div>
    @enderror

    @error('extend')
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ $message }}</div>
    @enderror

    @if ($showCreateForm)
        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Create Paid Booking</h2>
            <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">A booking is confirmed only after exact full payment is recorded.</p>

            <form wire:submit.prevent="createBooking" class="space-y-6">
                <div class="grid gap-4 md:grid-cols-3">
                    <flux:input label="First name" wire:model="form.first_name" />
                    <flux:input label="Middle name" wire:model="form.middle_name" />
                    <flux:input label="Last name" wire:model="form.last_name" />
                    <flux:input label="Contact number" wire:model="form.contact_no" />
                    <flux:input label="Email" type="email" wire:model="form.email" />
                    <flux:input label="Province" wire:model="form.province" />
                    <flux:input label="City" wire:model="form.city" />
                    <flux:input label="Barangay" wire:model="form.barangay" />
                    <flux:input label="Purok" wire:model="form.purok" />
                </div>

                <div class="grid gap-4 md:grid-cols-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Facility</label>
                        <select wire:model.live="form.facility_id" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100">
                            <option value="">Select facility</option>
                            @foreach ($facilities as $facility)
                                <option value="{{ $facility->facility_id }}">
                                    {{ $facility->facility_name }} - {{ optional($facility->facilityType)->facility_type }} / {{ $facility->capacity }} pax
                                </option>
                            @endforeach
                        </select>
                        @error('form.facility_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Rate type</label>
                        <select wire:model.live="form.rate_type" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100">
                            <option value="">Select rate</option>
                            @foreach ($rateTypes as $rate)
                                <option value="{{ $rate->rate_type }}">{{ $rate->rate_type }} - ₱{{ number_format((float) $rate->facility_price, 2) }}</option>
                            @endforeach
                        </select>
                        @error('form.rate_type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Discount</label>
                        <select wire:model.live="form.discount_id" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100">
                            <option value="">No discount</option>
                            @foreach ($discounts as $discount)
                                <option value="{{ $discount->discount_id }}">{{ $discount->discount_name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <flux:input label="Check-in time" type="time" wire:model="form.check_in_time" />

                    <flux:input label="Check-in date" type="date" wire:model.live="form.check_in_date" />
                    <flux:input label="Check-out date" type="date" wire:model.live="form.check_out_date" />

                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Mode of payment</label>
                        <select wire:model="form.mode_of_payment_id" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100">
                            <option value="">Select payment mode</option>
                            @foreach ($paymentModes as $mode)
                                <option value="{{ $mode->mode_of_payment_id }}">{{ $mode->mode_of_payment }}</option>
                            @endforeach
                        </select>
                        @error('form.mode_of_payment_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <flux:input label="GCash reference number" wire:model="form.reference_number" />
                    <flux:input label="Payment amount" type="number" step="0.01" wire:model="form.payment_amount" />
                </div>

                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                    <div class="mb-3 flex items-center justify-between">
                        <div>
                            <h3 class="font-medium text-zinc-900 dark:text-zinc-100">Extra guests</h3>
                            <p class="text-sm text-zinc-600 dark:text-zinc-400">Extra guest fee currently applies to room bookings at ₱100 per person.</p>
                        </div>
                        <flux:button type="button" size="sm" wire:click="addExtraGuest">Add Extra Guest</flux:button>
                    </div>

                    <div class="space-y-3">
                        @forelse ($extraGuests as $index => $extraGuest)
                            <div wire:key="extra-guest-{{ $index }}" class="grid gap-3 md:grid-cols-4">
                                <flux:input placeholder="First name" wire:model.live="extraGuests.{{ $index }}.first_name" />
                                <flux:input placeholder="Middle name" wire:model.live="extraGuests.{{ $index }}.middle_name" />
                                <flux:input placeholder="Last name" wire:model.live="extraGuests.{{ $index }}.last_name" />
                                <flux:button type="button" variant="danger" wire:click="removeExtraGuest({{ $index }})">Remove</flux:button>
                            </div>
                        @empty
                            <p class="text-sm text-zinc-500">No extra guests added.</p>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950">
                    <h3 class="font-medium text-zinc-900 dark:text-zinc-100">Current quote</h3>
                    @if ($currentQuote)
                        <div class="mt-2 grid gap-2 text-sm md:grid-cols-4">
                            <div>Base: <strong>₱{{ number_format((float) $currentQuote['base_price'], 2) }}</strong></div>
                            <div>Discount: <strong>₱{{ number_format((float) $currentQuote['discount_amount'], 2) }}</strong></div>
                            <div>Extra guest fee: <strong>₱{{ number_format((float) $currentQuote['extra_guest_fee'], 2) }}</strong></div>
                            <div>Total: <strong>₱{{ number_format((float) $currentQuote['total'], 2) }}</strong></div>
                        </div>
                        <div class="mt-3">
                            <flux:button type="button" size="sm" wire:click="useQuotedAmount">Use exact amount</flux:button>
                        </div>
                    @else
                        <p class="mt-1 text-sm text-zinc-500">Select facility and rate type to compute total.</p>
                    @endif
                </div>

                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary">Create Booking</flux:button>
                    <flux:button type="button" wire:click="toggleCreateForm">Cancel</flux:button>
                </div>
            </form>
        </div>
    @endif

    @if ($rescheduleForm['booking_details_id'] !== '')
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900 dark:bg-amber-950">
            <h2 class="text-lg font-semibold text-amber-950 dark:text-amber-100">Reschedule Booking</h2>
            <p class="text-sm text-amber-800 dark:text-amber-200">{{ $rescheduleForm['label'] }}</p>
            <form wire:submit.prevent="saveReschedule" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                <flux:input label="New check-in date" type="date" wire:model="rescheduleForm.new_check_in_date" />
                <flux:button type="submit" variant="primary">Save Reschedule</flux:button>
                <flux:button type="button" wire:click="cancelReschedule">Cancel</flux:button>
            </form>
        </div>
    @endif

    @if ($transferForm['booking_details_id'] !== '')
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-5 dark:border-blue-900 dark:bg-blue-950">
            <h2 class="text-lg font-semibold text-blue-950 dark:text-blue-100">Transfer Facility</h2>
            <p class="text-sm text-blue-800 dark:text-blue-200">{{ $transferForm['label'] }}</p>
            <form wire:submit.prevent="saveTransfer" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">New facility</label>
                    <select wire:model="transferForm.new_facility_id" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100">
                        <option value="">Select matching facility</option>
                        @foreach ($transferFacilities as $facility)
                            <option value="{{ $facility->facility_id }}">{{ $facility->facility_name }} - {{ $facility->capacity }} pax</option>
                        @endforeach
                    </select>
                </div>
                <flux:button type="submit" variant="primary">Save Transfer</flux:button>
                <flux:button type="button" wire:click="cancelTransfer">Cancel</flux:button>
            </form>
        </div>
    @endif

    <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <flux:input placeholder="Search reference, guest, or facility..." wire:model.live.debounce.300ms="search" />
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                <thead>
                    <tr class="text-left text-zinc-600 dark:text-zinc-400">
                        <th class="px-3 py-2"><button wire:click="sortBy('booking_id')">ID</button></th>
                        <th class="px-3 py-2"><button wire:click="sortBy('b_ref_no')">Reference</button></th>
                        <th class="px-3 py-2"><button wire:click="sortBy('guest_name')">Guest</button></th>
                        <th class="px-3 py-2"><button wire:click="sortBy('facility_name')">Facility</button></th>
                        <th class="px-3 py-2">Date Range</th>
                        <th class="px-3 py-2">Rate</th>
                        <th class="px-3 py-2"><button wire:click="sortBy('total_price')">Total</button></th>
                        <th class="px-3 py-2"><button wire:click="sortBy('amount_due')">Due</button></th>
                        <th class="px-3 py-2"><button wire:click="sortBy('status')">Status</button></th>
                        <th class="px-3 py-2">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($bookings as $booking)
                        <tr wire:key="booking-row-{{ $booking->booking_id }}-{{ $booking->booking_details_id }}" class="text-zinc-800 dark:text-zinc-200">
                            <td class="px-3 py-3">{{ $booking->booking_id }}</td>
                            <td class="px-3 py-3 font-medium">{{ $booking->b_ref_no }}</td>
                            <td class="px-3 py-3">{{ trim($booking->guest_name) }}</td>
                            <td class="px-3 py-3">{{ $booking->facility_name ?? 'No facility' }}</td>
                            <td class="px-3 py-3">{{ $booking->check_in_date }} → {{ $booking->check_out_date }}</td>
                            <td class="px-3 py-3">{{ $booking->rate_type }}</td>
                            <td class="px-3 py-3">₱{{ number_format((float) $booking->total_price, 2) }}</td>
                            <td class="px-3 py-3">₱{{ number_format((float) $booking->amount_due, 2) }}</td>
                            <td class="px-3 py-3">{{ $booking->status }}</td>
                            <td class="px-3 py-3">
                                @if ($booking->booking_details_id)
                                    <div class="flex flex-wrap gap-2">
                                        <flux:button size="sm" wire:click="openReschedule({{ $booking->booking_details_id }})">Reschedule</flux:button>
                                        <flux:button size="sm" wire:click="openTransfer({{ $booking->booking_details_id }})">Transfer</flux:button>
                                        <flux:button size="sm" wire:click="extendBooking({{ $booking->booking_details_id }})">Extend</flux:button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-3 py-8 text-center text-zinc-500">No bookings found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $bookings->links() }}
        </div>
    </div>
</div>
