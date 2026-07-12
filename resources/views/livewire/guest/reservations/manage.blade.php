<?php

use App\Models\Reservation;
use App\Services\GuestReservationManagementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.public')] #[Title('Manage Reservation - Olaer Spring Resort')] class extends Component
{
    public string $referenceNumber = '';
    public string $email = '';
    public string $otp = '';
    public ?int $reservationId = null;
    public bool $otpRequested = false;
    public bool $verified = false;
    public ?string $debugOtp = null;
    public ?string $successMessage = null;
    public ?string $errorMessage = null;

    public bool $showUpdateForm = false;
    public bool $showCancelForm = false;

    public ?int $facilityTypeId = null;
    public string $rateType = '';
    public ?int $facilityId = null;
    public string $checkInDate = '';
    public string $checkOutDate = '';
    public int $extraGuestCount = 0;
    public array $extraGuests = [];
    public string $cancellationReason = '';

    public function mount(): void
    {
        $this->checkInDate = Carbon::tomorrow()->toDateString();
        $this->checkOutDate = Carbon::tomorrow()->addDay()->toDateString();
        $this->syncExtraGuestRows();
    }

    public function requestOtp(GuestReservationManagementService $service): void
    {
        $this->resetMessages();

        $this->validate([
            'referenceNumber' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:100'],
        ]);

        try {
            $result = $service->requestOtp($this->referenceNumber, $this->email);
            $this->reservationId = $result['reservation_id'];
            $this->debugOtp = $result['debug_otp'];
            $this->otpRequested = true;
            $this->successMessage = 'OTP sent to the reservation email.';
        } catch (Throwable $exception) {
            $this->errorMessage = $exception->getMessage();
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
        } catch (Throwable $exception) {
            $this->errorMessage = $exception->getMessage();
        }
    }

    public function prepareUpdate(): void
    {
        $reservation = $this->reservation();

        if (! $reservation) {
            return;
        }

        $detail = $reservation->details->first();

        if (! $detail) {
            $this->errorMessage = 'Reservation has no facility detail to update.';
            return;
        }

        $this->showUpdateForm = true;
        $this->showCancelForm = false;
        $this->facilityTypeId = (int) $detail->facility?->facility_type_id;
        $this->rateType = (string) $detail->rate_type;
        $this->facilityId = (int) $detail->facility_id;
        $this->checkInDate = optional($detail->check_in_date)->format('Y-m-d') ?: Carbon::tomorrow()->toDateString();
        $this->checkOutDate = optional($detail->check_out_date)->format('Y-m-d') ?: Carbon::tomorrow()->addDay()->toDateString();
        $this->extraGuestCount = (int) $reservation->extraGuests->count();
        $this->extraGuests = $reservation->extraGuests
            ->map(fn ($guest): array => [
                'first_name' => (string) $guest->first_name,
                'middle_name' => (string) ($guest->middle_name ?? ''),
                'last_name' => (string) $guest->last_name,
            ])
            ->values()
            ->all();
        $this->syncExtraGuestRows();
    }

    public function prepareCancel(): void
    {
        $this->showCancelForm = true;
        $this->showUpdateForm = false;
        $this->cancellationReason = '';
    }

    public function updatedFacilityTypeId(): void
    {
        $this->rateType = '';
        $this->facilityId = null;
        $this->extraGuestCount = 0;
        $this->syncExtraGuestRows();
    }

    public function updatedRateType(): void
    {
        $this->facilityId = null;
    }

    public function updatedExtraGuestCount(): void
    {
        $this->extraGuestCount = max(0, min(6, (int) $this->extraGuestCount));
        $this->syncExtraGuestRows();
    }

    public function updateReservation(GuestReservationManagementService $service): void
    {
        $this->resetMessages();

        $this->validate([
            'facilityTypeId' => ['required', 'integer', 'exists:tbl_facility_type,facility_type_id'],
            'rateType' => ['required', 'string', 'max:20'],
            'facilityId' => ['required', 'integer', 'exists:tbl_facility,facility_id'],
            'checkInDate' => ['required', 'date', 'after_or_equal:today'],
            'checkOutDate' => ['required', 'date', 'after:checkInDate'],
            'extraGuestCount' => ['integer', 'min:0', 'max:6'],
            'extraGuests.*.first_name' => ['nullable', 'string', 'max:50'],
            'extraGuests.*.middle_name' => ['nullable', 'string', 'max:50'],
            'extraGuests.*.last_name' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $service->updateReservation((int) $this->reservationId, [
                'facility_id' => $this->facilityId,
                'rate_type' => $this->rateType,
                'check_in_date' => $this->checkInDate,
                'check_out_date' => $this->checkOutDate,
                'extra_guests' => array_slice($this->extraGuests, 0, $this->extraGuestCount),
            ]);

            $this->showUpdateForm = false;
            $this->successMessage = 'Reservation updated successfully.';
        } catch (Throwable $exception) {
            $this->errorMessage = $exception->getMessage();
        }
    }

    public function cancelReservation(GuestReservationManagementService $service): void
    {
        $this->resetMessages();

        $this->validate([
            'cancellationReason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $service->cancelReservation((int) $this->reservationId, $this->cancellationReason);
            $this->verified = false;
            $this->showCancelForm = false;
            $this->successMessage = 'Reservation cancelled successfully.';
        } catch (Throwable $exception) {
            $this->errorMessage = $exception->getMessage();
        }
    }

    public function reservation(): ?Reservation
    {
        if (! $this->reservationId) {
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

    public function rateTypes(GuestReservationManagementService $service)
    {
        return $service->rateTypesForFacilityType($this->facilityTypeId);
    }

    public function availableFacilities(GuestReservationManagementService $service)
    {
        $reservation = $this->reservation();
        $detail = $reservation?->details->first();

        return $service->availableFacilities(
            $this->facilityTypeId,
            $this->rateType,
            $this->checkInDate,
            $this->checkOutDate,
            $detail?->reservation_details_id,
        );
    }

    public function quote(GuestReservationManagementService $service): ?array
    {
        try {
            $reservation = $this->reservation();
            $detail = $reservation?->details->first();

            return $service->quotePreview(
                $this->facilityId,
                $this->rateType,
                $this->checkInDate,
                $this->checkOutDate,
                $this->extraGuestCount,
                $detail?->discount_id ? (int) $detail->discount_id : null,
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function syncExtraGuestRows(): void
    {
        $this->extraGuests = array_values($this->extraGuests);

        for ($i = count($this->extraGuests); $i < $this->extraGuestCount; $i++) {
            $this->extraGuests[$i] = [
                'first_name' => '',
                'middle_name' => '',
                'last_name' => '',
            ];
        }

        if (count($this->extraGuests) > $this->extraGuestCount) {
            $this->extraGuests = array_slice($this->extraGuests, 0, $this->extraGuestCount);
        }
    }

    private function resetMessages(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
    }
};
?>

<section class="space-y-6">
    <div>
        <p class="text-sm font-medium text-zinc-500">Guest Reservation</p>
        <h1 class="text-2xl font-bold tracking-tight">Manage Reservation</h1>
        <p class="mt-1 text-sm text-zinc-600">Verify your reservation using the reference number, email, and OTP before updating or cancelling.</p>
    </div>

    @if ($successMessage)
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ $successMessage }}</div>
    @endif

    @if ($errorMessage)
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errorMessage }}</div>
    @endif

    @if (! $verified)
        <div class="rounded-xl border bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold">Step 1: Find Reservation</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="block">
                    <span class="text-sm font-medium">Reservation Reference No.</span>
                    <input type="text" wire:model.live="referenceNumber" class="mt-1 w-full rounded-lg border-zinc-300" placeholder="Example: R260619XXXX">
                    @error('referenceNumber') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </label>

                <label class="block">
                    <span class="text-sm font-medium">Email Used for Reservation</span>
                    <input type="email" wire:model.live="email" class="mt-1 w-full rounded-lg border-zinc-300" placeholder="guest@email.com">
                    @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </label>
            </div>

            <div class="mt-4 flex gap-3">
                <flux:button variant="primary" wire:click="requestOtp">Send OTP</flux:button>
            </div>
        </div>

        @if ($otpRequested)
            <div class="rounded-xl border bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold">Step 2: Enter OTP</h2>
                <p class="mt-1 text-sm text-zinc-600">The OTP expires in 10 minutes.</p>

                @if ($debugOtp)
                    <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        Local testing OTP: <strong>{{ $debugOtp }}</strong>. In production, this should only be sent by email.
                    </div>
                @endif

                <div class="mt-4 max-w-xs">
                    <input type="text" wire:model.live="otp" class="w-full rounded-lg border-zinc-300" maxlength="6" placeholder="6-digit OTP">
                    @error('otp') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="mt-4">
                    <flux:button variant="primary" wire:click="verifyOtp">Verify OTP</flux:button>
                </div>
            </div>
        @endif
    @endif

    @if ($verified)
        @php($reservation = $this->reservation())

        @if ($reservation)
            <div class="rounded-xl border bg-white p-5 shadow-sm">
                <div class="flex flex-col justify-between gap-4 md:flex-row md:items-start">
                    <div>
                        <h2 class="text-lg font-semibold">Reservation {{ $reservation->r_ref_no }}</h2>
                        <p class="text-sm text-zinc-600">
                            {{ $reservation->guest?->first_name }} {{ $reservation->guest?->last_name }} · {{ $reservation->guest?->email }}
                        </p>
                        <p class="mt-1 text-sm text-zinc-600">Status: <strong>{{ $reservation->status }}</strong></p>
                    </div>
                    <div class="text-left md:text-right">
                        <p class="text-sm text-zinc-500">Total Price</p>
                        <p class="text-2xl font-bold">₱{{ number_format((float) $reservation->total_price, 2) }}</p>
                        <p class="text-sm text-zinc-500">Amount Due: ₱{{ number_format((float) $reservation->amount_due, 2) }}</p>
                    </div>
                </div>

                <div class="mt-5 overflow-hidden rounded-lg border">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-zinc-50 text-zinc-600">
                            <tr>
                                <th class="px-3 py-2">Facility</th>
                                <th class="px-3 py-2">Type</th>
                                <th class="px-3 py-2">Rate</th>
                                <th class="px-3 py-2">Check-in</th>
                                <th class="px-3 py-2">Check-out</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reservation->details as $detail)
                                <tr class="border-t">
                                    <td class="px-3 py-2">{{ $detail->facility?->facility_name }}</td>
                                    <td class="px-3 py-2">{{ $detail->facility?->facilityType?->facility_type }}</td>
                                    <td class="px-3 py-2">{{ $detail->rate_type }}</td>
                                    <td class="px-3 py-2">{{ optional($detail->check_in_date)->format('M d, Y') }}</td>
                                    <td class="px-3 py-2">{{ optional($detail->check_out_date)->format('M d, Y') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex flex-wrap gap-3">
                    <flux:button variant="primary" wire:click="prepareUpdate">Update Reservation</flux:button>
                    <flux:button variant="danger" wire:click="prepareCancel">Cancel Reservation</flux:button>
                </div>
            </div>
        @endif

        @if ($showUpdateForm)
            @php($facilityTypes = $this->facilityTypes(app(\App\Services\GuestReservationManagementService::class)))
            @php($rateTypes = $this->rateTypes(app(\App\Services\GuestReservationManagementService::class)))
            @php($availableFacilities = $this->availableFacilities(app(\App\Services\GuestReservationManagementService::class)))
            @php($quote = $this->quote(app(\App\Services\GuestReservationManagementService::class)))

            <div class="rounded-xl border bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold">Update Reservation Details</h2>
                <p class="mt-1 text-sm text-zinc-600">Paid reservations cannot be modified online. Ask the cashier if payment was already verified.</p>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium">Check-in Date</span>
                        <input type="date" wire:model.live="checkInDate" class="mt-1 w-full rounded-lg border-zinc-300">
                        @error('checkInDate') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Check-out Date</span>
                        <input type="date" wire:model.live="checkOutDate" class="mt-1 w-full rounded-lg border-zinc-300">
                        @error('checkOutDate') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Facility Type</span>
                        <select wire:model.live="facilityTypeId" class="mt-1 w-full rounded-lg border-zinc-300">
                            <option value="">Select type</option>
                            @foreach ($facilityTypes as $type)
                                <option value="{{ $type->facility_type_id }}">{{ $type->facility_type }}</option>
                            @endforeach
                        </select>
                        @error('facilityTypeId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Rate Type</span>
                        <select wire:model.live="rateType" class="mt-1 w-full rounded-lg border-zinc-300">
                            <option value="">Select rate</option>
                            @foreach ($rateTypes as $rate)
                                <option value="{{ $rate }}">{{ $rate }}</option>
                            @endforeach
                        </select>
                        @error('rateType') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </label>
                </div>

                <div class="mt-4">
                    <span class="text-sm font-medium">Available Facility</span>
                    <div class="mt-2 grid gap-3 md:grid-cols-3">
                        @forelse ($availableFacilities as $facility)
                            <label class="cursor-pointer rounded-lg border p-3 {{ (int) $facilityId === (int) $facility->facility_id ? 'border-zinc-900 bg-zinc-50' : 'border-zinc-200' }}">
                                <input type="radio" wire:model.live="facilityId" value="{{ $facility->facility_id }}" class="sr-only">
                                <p class="font-semibold">{{ $facility->facility_name }}</p>
                                <p class="text-sm text-zinc-600">{{ $facility->capacity }} pax</p>
                            </label>
                        @empty
                            <p class="text-sm text-zinc-600">No available facilities match the current date/rate selection.</p>
                        @endforelse
                    </div>
                    @error('facilityId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="mt-4 max-w-sm">
                    <label class="block">
                        <span class="text-sm font-medium">Room Extra Guests</span>
                        <input type="number" min="0" max="6" wire:model.live="extraGuestCount" class="mt-1 w-full rounded-lg border-zinc-300">
                    </label>
                    <p class="mt-1 text-xs text-zinc-500">Extra guest charge applies to rooms only. Cottage/function hall guests should select the correct facility capacity.</p>
                </div>

                @if ($extraGuestCount > 0)
                    <div class="mt-4 space-y-3">
                        <h3 class="font-medium">Extra Guest Names</h3>
                        @foreach ($extraGuests as $index => $guest)
                            <div class="grid gap-3 rounded-lg border p-3 md:grid-cols-3">
                                <input type="text" wire:model.live="extraGuests.{{ $index }}.first_name" class="rounded-lg border-zinc-300" placeholder="First name">
                                <input type="text" wire:model.live="extraGuests.{{ $index }}.middle_name" class="rounded-lg border-zinc-300" placeholder="Middle name">
                                <input type="text" wire:model.live="extraGuests.{{ $index }}.last_name" class="rounded-lg border-zinc-300" placeholder="Last name">
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($quote)
                    <div class="mt-4 rounded-lg border bg-zinc-50 p-4">
                        <p class="text-sm text-zinc-600">Updated Estimated Total</p>
                        <p class="text-2xl font-bold">₱{{ number_format((float) $quote['total_price'], 2) }}</p>
                    </div>
                @endif

                <div class="mt-5 flex gap-3">
                    <flux:button variant="primary" wire:click="updateReservation">Save Update</flux:button>
                    <flux:button wire:click="$set('showUpdateForm', false)">Cancel Edit</flux:button>
                </div>
            </div>
        @endif

        @if ($showCancelForm)
            <div class="rounded-xl border bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold">Cancel Reservation</h2>
                <p class="mt-1 text-sm text-zinc-600">Cancellation is allowed only for active unpaid reservations.</p>

                <label class="mt-4 block">
                    <span class="text-sm font-medium">Cancellation Reason</span>
                    <textarea wire:model.live="cancellationReason" rows="3" class="mt-1 w-full rounded-lg border-zinc-300" placeholder="Reason for cancellation"></textarea>
                    @error('cancellationReason') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </label>

                <div class="mt-5 flex gap-3">
                    <flux:button variant="danger" wire:click="cancelReservation">Confirm Cancellation</flux:button>
                    <flux:button wire:click="$set('showCancelForm', false)">Back</flux:button>
                </div>
            </div>
        @endif
    @endif
</section>
