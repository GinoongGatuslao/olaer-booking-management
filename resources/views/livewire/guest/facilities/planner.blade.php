<?php

use App\FacilitySchedulePolicy;
use App\Models\FacilityProduct;
use App\Services\FacilityAssignmentService;
use App\Services\FacilityAvailabilityService;
use App\Services\FacilityRequirementService;
use App\Services\GcashProofStorageService;
use App\Services\GuestSubmissionConfirmationService;
use App\Support\GroupCapacityCheck;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.public')] #[Title('Plan Facilities - Olaer Spring Resort')] class extends Component
{

    use \App\Concerns\HasPhilippineLocations;

    // The guest must read the Booking, Cancellation, and Refund Policy before the email is sent.
    public bool $showPolicyModal = false;
    public bool $acceptedPolicy = false;

    /**
     * Every error text, for the toast. Fields only turn red; all wording lives in the toast.
     * Combines the validation errors with the live checks (room capacity, availability).
     *
     * @return array<int, string>
     */
    public function bannerErrors(): array
    {
        $issues = $this->groupIssues();
        $hideOccupantErrors = $issues !== [];
        $messages = [];

        foreach ($this->getErrorBag()->messages() as $key => $list) {
            if ($key === 'acceptedPolicy') {
                continue; // shown inside the policy modal
            }

            if ($hideOccupantErrors && str_starts_with($key, 'roomOccupants.')) {
                continue; // the occupants card is hidden while a facility row has an error
            }

            $prefix = '';

            if (preg_match('/^groups\.(\d+)\./', $key, $match)) {
                $prefix = 'Facility group '.($match[1] + 1).': ';
            } elseif (preg_match('/^roomOccupants\.(\d+)\.(\d+)\./', $key, $match)) {
                $prefix = 'Room '.($match[1] + 1).', occupant '.($match[2] + 1).': ';
            }

            foreach ($list as $message) {
                // One general line instead of one per missing occupant name.
                $messages[] = $message === 'Room Occupants must be filled out.' ? $message : $prefix.$message;
            }
        }

        foreach ($issues as $index => $fields) {
            foreach ($fields as $message) {
                $messages[] = 'Facility group '.($index + 1).': '.$message;
            }
        }

        return array_values(array_unique($messages));
    }

    public function updated(string $property): void
    {
        // Clear the red highlight of a field as soon as the user edits it.
        $this->resetValidation($property);
    }

    /**
     * Live problems per facility row, shown as red fields + toast text without pressing Save.
     * Shape: [rowIndex => ['quantity' => message, 'estimated_users' => message]].
     *
     * @return array<int, array<string, string>>
     */
    public function groupIssues(): array
    {
        $issues = [];

        foreach ($this->groups as $index => $group) {
            $product = $this->productFor($index);

            if ($product === null) {
                continue;
            }

            $quantity = (int) ($group['quantity'] ?? 0);
            $guests = (int) ($group['estimated_users'] ?? 0);

            // Rooms only: a room never holds more than its strict maximum.
            $capacity = GroupCapacityCheck::message($product, $quantity, $guests);

            if ($capacity !== null) {
                $issues[$index]['estimated_users'] = $capacity;
            }

            if (($group['rate_code'] ?? '') === '') {
                continue;
            }

            $availability = $this->availabilityFor($index);

            if ($availability !== null && $availability['available'] !== null && $availability['requested'] > $availability['available']) {
                $issues[$index]['quantity'] = $availability['available'] === 0
                    ? "No {$product->display_name} is available for the selected schedule and date, but {$availability['requested']} requested."
                    : "Only {$availability['available']} {$product->display_name} available for the selected schedule and date, but {$availability['requested']} requested.";
            }
        }

        return $issues;
    }

    /** The occupant names card only shows while nothing above it is wrong. */
    public function showRoomOccupants(): bool
    {
        if ($this->roomOccupants === [] || $this->groupIssues() !== []) {
            return false;
        }

        foreach (array_keys($this->getErrorBag()->messages()) as $key) {
            if (str_starts_with($key, 'groups') || $key === 'plan' || $key === 'partyCount') {
                return false;
            }
        }

        return true;
    }

    /** Capacity text from the facility, e.g. "Capacity: 8–12 guests (recommended)". */
    public function capacityLabel(?FacilityProduct $product): ?string
    {
        if ($product === null) {
            return null;
        }

        if ($product->isRoom() && $product->strict_maximum !== null) {
            $label = 'Up to '.$product->strict_maximum.' guests per room';
            $included = $product->included_guest_count;

            if ($included !== null && $included >= 0 && $included < $product->strict_maximum) {
                $fee = \App\Support\GroupRowPricing::ROOM_EXTRA_GUEST_FEE;
                $fee = number_format($fee, fmod($fee, 1.0) === 0.0 ? 0 : 2);

                $label .= " · First {$included} guests free, ₱{$fee} per extra guest per night";
            }

            return $label;
        }

        $min = $product->suggested_minimum;
        $max = $product->suggested_maximum;

        return match (true) {
            $min !== null && $max !== null && $min !== $max => "Capacity: {$min}–{$max} guests (recommended)",
            $max !== null => "Capacity: up to {$max} guests (recommended)",
            $min !== null => "Capacity: {$min}+ guests (recommended)",
            default => 'Capacity is a recommendation',
        };
    }

    use WithFileUploads;

    public string $token = '';
    public bool $bookingMode = false;
    public string $planTotal = '';

    /** Bumped on every save/submit so the error toast shows again even if the messages are unchanged. */
    public int $errorToastTick = 0;
    public string $paymentAmount = '';
    public string $referenceNumber = '';
    public $proofOfPayment = null;

    /** Temporary pick for the proof of payment; it becomes $proofOfPayment once it passes the checks. */
    public $proofPhoto = null;

    /** @var array<string, array{available: int|null, requested: int}> */
    private array $availabilityMemo = [];
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

    /** @var array<int, array<int, array{first_name: string, middle_name: string, last_name: string}>> */
    public array $roomOccupants = [];

    public function mount(FacilityRequirementService $requirements): void
    {
        $this->bookingMode = request()->routeIs('guest.bookings.create');
        $sessionKey = $this->bookingMode ? 'guest.booking_plan_token' : 'guest.facility_plan_token';
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

    /**
     * Check-in / check-out times for a rate (schedule), matched by rate code
     * or, failing that, by the rate's display name.
     *
     * @return array{check_in: string, check_out: string}|null
     */
    public function rateTimes(?string $rateCode, ?string $displayName = null): ?array
    {
        $times = [
            'day'       => ['check_in' => '6:00 AM', 'check_out' => '6:00 PM'],
            'night'     => ['check_in' => '4:00 PM', 'check_out' => '12:00 AM'],
            'both'      => ['check_in' => '6:00 AM', 'check_out' => '12:00 AM'],
            'overnight' => ['check_in' => '2:00 PM', 'check_out' => '12:00 PM'],
        ];

        foreach ([$rateCode, $displayName] as $candidate) {
            $key = strtolower(trim((string) $candidate));

            if (isset($times[$key])) {
                return $times[$key];
            }
        }

        return null;
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
        $this->planTotal = '';
        $this->paymentAmount = '';
        $this->updatePartyCount();
    }

    public function removeGroup(int $index): void
    {
        if (count($this->groups) === 1) {
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

            $checkInDate = (string) ($group['check_in_date'] ?? '');

            if ($checkInDate === '') {
                continue;
            }

            if (! validator(['date' => $checkInDate], ['date' => 'date_format:Y-m-d'])->passes()) {
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

        $this->syncRoomOccupantRows();
        $this->planTotal = '';
        $this->paymentAmount = '';
        $this->updatePartyCount();
    }


    /** Quantity and estimated guests are whole numbers of at least 1 (a blank stays blank while typing). */
    private function sanitizeWholeNumbers(bool $fillBlanks = false): void
    {
        foreach ($this->groups as $index => $group) {
            foreach (['quantity', 'estimated_users'] as $field) {
                $digits = substr(preg_replace('/\D/', '', (string) ($group[$field] ?? '')), 0, 9);

                if ($digits === '') {
                    $this->groups[$index][$field] = $fillBlanks ? 1 : '';

                    continue;
                }

                $this->groups[$index][$field] = max(1, (int) $digits);
            }
        }
    }

    /** Proof of payment: one photo, picked first, kept only after it passes the checks. */
    public function updatedProofPhoto(): void
    {
        $this->resetErrorBag(['proofPhoto', 'proofOfPayment']);

        if ($this->proofPhoto === null) {
            return;
        }

        try {
            $this->validate([
                'proofPhoto' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:16384'],
            ], [
                'proofPhoto.mimes' => 'Proof of payment must be a JPG, PNG or WebP photo.',
                'proofPhoto.max' => 'Proof of payment must be 16 MB or smaller.',
                'proofPhoto.file' => 'The chosen proof of payment could not be uploaded.',
            ]);

            $this->proofOfPayment = $this->proofPhoto;
        } finally {
            $this->proofPhoto = null;
        }
    }

    public function removeProofOfPayment(): void
    {
        $this->proofOfPayment = null;
        $this->proofPhoto = null;
        $this->resetErrorBag(['proofPhoto', 'proofOfPayment']);
    }

    public function savePlan(
        FacilityRequirementService $requirements,
        FacilityAssignmentService $assignments,
    ): void {
        $this->errorToastTick++;
        $this->resetErrorBag('plan');
        $this->sanitizeWholeNumbers(fillBlanks: true);
        $this->validate($this->planRules());

        if ($this->groupIssues() !== []) {
            return; // the toast already lists them and the fields are red
        }

        try {
            $intent = $requirements->replaceGroups(
                $requirements->ownedIntent($this->token, session()->getId()),
                $this->partyCount,
                $this->groups,
            );
            $this->syncRoomOccupantRows();
            $this->planTotal = $assignments->quoteIntentTotal($intent);
            $this->paymentAmount = $this->bookingMode
                ? $this->planTotal
                : app(\App\Services\DecimalMoneyService::class)
                    ->percentage($this->planTotal, '0.500000');
            session()->flash('success', 'Facility plan saved. Availability and pricing were checked against current reservations and bookings.');
        } catch (\InvalidArgumentException $exception) {
            $this->addError('plan', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('plan', 'The facility plan could not be saved. Please try again.');
        }
    }

    /** Step 1: check the form, then show the policy. Nothing is sent yet. */
    public function reviewPolicy(): void
    {
        $this->errorToastTick++;
        $this->sanitizeWholeNumbers(fillBlanks: true);
        $this->validate($this->rules());

        if ($this->groupIssues() !== []) {
            return;
        }

        $this->acceptedPolicy = false;
        $this->resetErrorBag('acceptedPolicy');
        $this->showPolicyModal = true;
    }

    public function updatedShowPolicyModal(bool $open): void
    {
        if (! $open) {
            $this->acceptedPolicy = false;
            $this->resetErrorBag('acceptedPolicy');
        }
    }

    /** Step 2: the guest accepted the policy in the modal, so send the confirmation email. */
    public function submit(
        FacilityRequirementService $requirements,
        FacilityAssignmentService $assignments,
        GcashProofStorageService $proofStorage,
        GuestSubmissionConfirmationService $confirmations,
    ): void {
        $this->errorToastTick++;

        if (! $this->acceptedPolicy) {
            $this->addError('acceptedPolicy', 'Please confirm that you have read the Booking, Cancellation, and Refund Policy.');

            return;
        }

        try {
            $this->validate($this->rules());
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->showPolicyModal = false; // let the guest see what needs fixing

            throw $exception;
        }

        if ($this->groupIssues() !== []) {
            $this->showPolicyModal = false;

            return;
        }

        $proofPath = null;

        try {
            $intent = $requirements->replaceGroups(
                $requirements->ownedIntent($this->token, session()->getId()),
                $this->partyCount,
                $this->groups,
            );
            $this->syncRoomOccupantRows();
            $this->planTotal = $assignments->quoteIntentTotal($intent);

            $guestData = [
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
            ];

            $type = $this->bookingMode ? 'booking' : 'reservation';

            // Nothing is created yet: check the payment terms, keep the request, and
            // email a link. The reservation/booking and the payment are created when
            // the guest clicks that link.
            $confirmations->assertPaymentTerms($type, $this->planTotal, $this->paymentAmount, $this->referenceNumber);

            $proofPath = $proofStorage->store($this->proofOfPayment);

            $confirmations->request($type, [
                'party_count' => $this->partyCount,
                'groups' => $this->groups,
                'plan_total' => $this->planTotal,
                'guest' => $guestData,
                'payment_amount' => $this->paymentAmount,
                'reference_number' => $this->referenceNumber,
                'proof_of_payment_path' => $proofPath,
            ]);

            session()->forget($this->bookingMode ? 'guest.booking_plan_token' : 'guest.facility_plan_token');
            session()->put('guest.submission_sent', [
                'type' => $type,
                'email' => $this->email,
            ]);
            $this->redirect(route('guest.submissions.sent'), navigate: true);
        } catch (\InvalidArgumentException $exception) {
            if ($proofPath !== null) {
                $proofStorage->deletePrivate($proofPath);
            }

            $this->showPolicyModal = false;
            $this->addError('plan', $exception->getMessage());
        } catch (\Throwable $exception) {
            if ($proofPath !== null) {
                $proofStorage->deletePrivate($proofPath);
            }

            report($exception);
            $this->showPolicyModal = false;
            $this->addError('plan', 'Your request could not be submitted. Please try again or contact the resort.');
        }
    }

    /** @return array<string, array<int, mixed>> */
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

    /** @return array<string, array<int, mixed>> */
    protected function rules(): array
    {
        return [
            ...$this->planRules(),
            'firstName' => ['required', 'string', 'max:50'],
            'middleName' => ['nullable', 'string', 'max:50'],
            'lastName' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:50', 'regex:/^[A-Za-z0-9]+(?:[._%+-][A-Za-z0-9]+)*@(?:[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*\.)+[A-Za-z]{2,}$/'],
            'contactNo' => ['required', 'regex:/^09[0-9]{9}$/'],
            'province' => ['required', 'string', 'max:50'],
            'city' => ['required', 'string', 'max:50'],
            'barangay' => ['nullable', 'string', 'max:50'],
            'purok' => ['nullable', 'string', 'max:50'],
            'roomOccupants' => ['array'],
            'roomOccupants.*' => ['array'],
            'roomOccupants.*.*.first_name' => ['required', 'string', 'max:50'],
            'roomOccupants.*.*.middle_name' => ['nullable', 'string', 'max:50'],
            'roomOccupants.*.*.last_name' => ['required', 'string', 'max:50'],
            'paymentAmount' => ['required', 'numeric', 'min:1'],
            'referenceNumber' => ['required', 'string', 'max:50'],
            'proofOfPayment' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:16384'],
        ];
    }

    public function productFor(int $index): ?FacilityProduct
    {
        $productId = (int) ($this->groups[$index]['facility_product_id'] ?? 0);

        return $this->products->firstWhere('facility_product_id', $productId);
    }

    /**
     * Shown as soon as a facility and a schedule are chosen, even while other fields
     * are still incomplete. `available` is null when it cannot be counted yet (e.g. no date).
     *
     * @return array{available: int|null, requested: int}|null
     */
    public function availabilityFor(int $index): ?array
    {
        $group = $this->groups[$index] ?? [];
        $product = $this->productFor($index);
        $rateCode = (string) ($group['rate_code'] ?? '');

        if ($product === null || $rateCode === '') {
            return null;
        }

        $requested = max(1, (int) ($group['quantity'] ?? 1));
        $key = md5(json_encode([$product->facility_product_id, $rateCode, $group['check_in_date'] ?? '', $group['check_out_date'] ?? '']));

        if (! array_key_exists($key, $this->availabilityMemo)) {
            $this->availabilityMemo[$key] = $this->countAvailable($product, $rateCode, (string) ($group['check_in_date'] ?? ''), (string) ($group['check_out_date'] ?? ''));
        }

        return ['available' => $this->availabilityMemo[$key], 'requested' => $requested];
    }

    private function countAvailable(FacilityProduct $product, string $rateCode, string $checkIn, string $checkOut): ?int
    {
        try {
            if (! validator(['d' => $checkIn], ['d' => 'date_format:Y-m-d'])->passes()) {
                return null;
            }

            $start = \Carbon\CarbonImmutable::parse($checkIn);
            $end = $product->schedule_policy === FacilitySchedulePolicy::Overnight
                ? (validator(['d' => $checkOut], ['d' => 'date_format:Y-m-d'])->passes() && $checkOut > $checkIn
                    ? \Carbon\CarbonImmutable::parse($checkOut)
                    : $start->addDay())
                : $start;

            return app(FacilityAvailabilityService::class)->availabilityCount(
                (int) $product->facility_product_id,
                $rateCode,
                $start->toDateString(),
                $end->toDateString(),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<int, int> */
    private function expectedRoomGuestCounts(): array
    {
        $counts = [];

        foreach ($this->groups as $index => $group) {
            $product = $this->productFor($index);

            if ($product === null || ! $product->isRoom() || $product->included_guest_count === null || $product->strict_maximum === null) {
                continue;
            }

            $quantity = (int) ($group['quantity'] ?? 0);
            $estimatedUsers = (int) ($group['estimated_users'] ?? 0);

            // A room never holds more than its strict maximum, so a wrong row gets no occupant rows.
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
    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'groups.*.facility_product_id.required' => 'Choose a facility.',
            'groups.*.facility_product_id.integer' => 'Choose a facility.',
            'groups.*.facility_product_id.exists' => 'The chosen facility is not available. Choose another.',
            'groups.*.rate_code.required' => 'Choose a schedule.',
            'groups.*.quantity.required' => 'Enter a quantity (at least 1).',
            'groups.*.quantity.integer' => 'Quantity must be a whole number.',
            'groups.*.quantity.min' => 'Quantity must be at least 1.',
            'groups.*.quantity.max' => 'Quantity can be at most 50.',
            'groups.*.estimated_users.required' => 'Enter the estimated number of guests.',
            'groups.*.estimated_users.integer' => 'Estimated guests must be a whole number.',
            'groups.*.estimated_users.min' => 'There must be at least 1 estimated guest.',
            'groups.*.check_in_date.required' => 'Choose a check-in date.',
            'groups.*.check_in_date.date_format' => 'Choose a valid check-in date.',
            'groups.*.check_in_date.after_or_equal' => 'The check-in date cannot be on a previous date.',
            'groups.*.check_out_date.required' => 'Choose a check-out date.',
            'groups.*.check_out_date.date_format' => 'Choose a valid check-out date.',
            'firstName.required' => 'Enter your first name.',
            'lastName.required' => 'Enter your last name.',
            'email.required' => 'Enter your email address.',
            'email.email' => 'Enter a valid email address.',
            'email.regex' => 'Enter a valid email address.',
            'contactNo.required' => 'Enter your contact number.',
            'contactNo.regex' => 'The contact number must be 11 digits and start with 09.',
            'province.required' => 'Choose your province.',
            'city.required' => 'Choose your city or municipality.',
            'roomOccupants.*.*.first_name.required' => 'Room Occupants must be filled out.',
            'roomOccupants.*.*.last_name.required' => 'Room Occupants must be filled out.',
            'paymentAmount.required' => 'Enter the payment amount.',
            'paymentAmount.numeric' => 'The payment amount must be a number.',
            'paymentAmount.min' => 'The payment amount must be at least ₱1.',
            'referenceNumber.required' => 'Enter the GCash reference number.',
            'proofOfPayment.required' => 'Upload your proof of payment (1 photo).',
            'proofOfPayment.mimes' => 'Proof of payment must be a JPG, PNG or WebP photo.',
            'proofOfPayment.max' => 'Proof of payment must be 16 MB or smaller.',
            'proofOfPayment.file' => 'The chosen proof of payment could not be uploaded.',
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'groups.*.estimated_users' => 'estimated guests',
            'groups.*.quantity' => 'quantity',
            'groups.*.facility_product_id' => 'facility',
            'groups.*.rate_code' => 'schedule',
            'groups.*.check_in_date' => 'check-in date',
            'groups.*.check_out_date' => 'check-out date',
            'contactNo' => 'contact number',
            'referenceNumber' => 'GCash reference number',
        ];
    }

    private function syncRoomOccupantRows(): void
    {
        // While a room row is wrong, keep what was typed; the rows rebuild once it is fixed.
        foreach ($this->groups as $index => $group) {
            $product = $this->productFor($index);

            if (GroupCapacityCheck::message($product, (int) ($group['quantity'] ?? 0), (int) ($group['estimated_users'] ?? 0)) !== null) {
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
    private function updatePartyCount(): void
    {
        $this->partyCount = max(1, (int) collect($this->groups)
            ->sum(fn (array $group): int => (int) ($group['estimated_users'] ?? 0)));
    }
};
    
?>

<section class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    <div class="max-w-3xl">
        <p class="text-sm font-medium text-emerald-700 dark:text-emerald-300">{{ $bookingMode ? 'Direct booking' : 'Reservation' }}</p>
        <h1 class="mt-1 text-3xl font-bold tracking-tight text-zinc-950 dark:text-white">Plan one stay with multiple facilities</h1>
        <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">Choose facility products, schedules, and quantities. The system assigns exact physical units only when the transaction is submitted.</p>
    </div>

    <x-toast :message="session('success')" fresh />

    <form wire:submit="reviewPolicy" novalidate class="mt-8 space-y-6">
        <flux:card>
            <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_14rem] sm:items-end">
                <div>
                    <flux:heading size="lg">Facility requirements</flux:heading>
                    <flux:text class="mt-1">Fill in all the required details. To add more facilities, click “Add Facility.” Once completed, click “Check Availability & Total” to proceed.</flux:text>
                </div>
            </div>

            <div class="mt-6 space-y-4">
                @php($issues = $this->groupIssues())
                @foreach ($groups as $index => $group)
                    @php($availability = $this->availabilityFor($index))
                    @php($selectedProduct = $this->productFor($index))
                    <div wire:key="facility-group-{{ $index }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="grid gap-4 lg:grid-cols-5">
                            <flux:field class="relative" >
                                <flux:label>Facility name <span class="text-red-500">*</span></flux:label>
                                <flux:select wire:model.live="groups.{{ $index }}.facility_product_id" :invalid="$errors->has('groups.'.$index.'.facility_product_id')">
                                <option value="">Choose a facility</option>
                                @foreach ($this->products as $product)
                                    <option value="{{ $product->facility_product_id }}">{{ $product->display_name }}</option>
                                @endforeach
                            </flux:select>
                            </flux:field>

                            <flux:field class="relative">
                                <flux:label>Schedule <span class="text-red-500">*</span></flux:label>
                                <flux:select wire:model.live="groups.{{ $index }}.rate_code" :invalid="$errors->has('groups.'.$index.'.rate_code')">
                                <option value="">Choose rate</option>
                                @foreach ($selectedProduct?->productRates ?? [] as $rate)
                                    @continue(\App\Support\DayRateCutoff::blocks($selectedProduct->schedule_policy, $rate->rate_code->value, (string) ($group['check_in_date'] ?? '')))
                                    <option value="{{ $rate->rate_code->value }}">{{ $rate->display_name }} — ₱{{ number_format((float) $rate->amount, 2) }}</option>
                                @endforeach
                            </flux:select>
                                @if ($selectedProduct && \App\Support\DayRateCutoff::closedFor($selectedProduct->schedule_policy, (string) ($group['check_in_date'] ?? '')))
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
                                <flux:label class="invisible max-lg:hidden" aria-hidden="true">Remove</flux:label>
                                <flux:button type="button" variant="ghost" wire:click="removeGroup({{ $index }})" class="w-full">Remove</flux:button>
                            </flux:field>
                        </div>

                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <flux:field class="relative">
                                <flux:label>Check-in / use date <span class="text-red-500">*</span></flux:label>
                                <flux:input wire:model.live="groups.{{ $index }}.check_in_date" type="date" min="{{ today()->toDateString() }}" :invalid="$errors->has('groups.'.$index.'.check_in_date')" />
                            </flux:field>
                            <flux:field class="relative">
                                <flux:label>Check-out / end date <span class="text-red-500">*</span></flux:label>
                                <flux:input wire:model.live="groups.{{ $index }}.check_out_date" type="date" min="{{ today()->toDateString() }}" :invalid="$errors->has('groups.'.$index.'.check_out_date')" />
                            </flux:field>
                        </div>

                        @php($selectedRate = $selectedProduct?->productRates->first(fn ($rate) => $rate->rate_code->value === ($group['rate_code'] ?? '')))
                        @php($rateTimes = $planTotal !== '' && $selectedRate ? $this->rateTimes($selectedRate->rate_code->value, $selectedRate->display_name) : null)
                        @if ($rateTimes)
                            <div class="mt-4 flex flex-wrap gap-2 text-sm">
                                <flux:badge color="amber">{{ $selectedRate->display_name }}</flux:badge>
                                <flux:badge color="zinc">Check-in: {{ $rateTimes['check_in'] }}</flux:badge>
                                <flux:badge color="zinc">Check-out: {{ $rateTimes['check_out'] }}</flux:badge>
                            </div>
                        @endif

                        @php($rowSummary = \App\Support\GroupRowPricing::summarize($selectedProduct, $group))
                        @if ($rowSummary)
                            <div class="mt-4 flex flex-wrap gap-2 text-sm">
                                @if ($rowSummary['is_room'])
                                    <flux:badge color="amber">{{ $rowSummary['days'] }} {{ \Illuminate\Support\Str::plural('night', $rowSummary['days']) }}</flux:badge>
                                @endif
                                <flux:badge color="green" title="Before any discount">Subtotal: ₱{{ number_format($rowSummary['subtotal'], 2) }}</flux:badge>
                            </div>
                        @endif

                        {{-- Shown as soon as a facility and a schedule are chosen. --}}
                        @if ($availability)
                            <div class="mt-4 flex flex-wrap gap-2 text-sm">
                                <flux:badge color="emerald">{{ $availability['available'] ?? '—' }} available</flux:badge>
                                <flux:badge :color="$availability['available'] !== null && $availability['requested'] > $availability['available'] ? 'red' : 'zinc'">{{ $availability['requested'] }} requested</flux:badge>
                                @if ($capacityText = $this->capacityLabel($selectedProduct))
                                    <flux:badge color="blue">{{ $capacityText }}</flux:badge>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <flux:button type="button" variant="ghost" wire:click="addGroup">Add Facility</flux:button>
                <flux:button type="button" wire:click="savePlan">Check availability & total</flux:button>
                @if ($planTotal !== '')
                    <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">Current total: ₱{{ number_format((float) $planTotal, 2) }}</span>
                @endif
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="lg">Primary guest</flux:heading>
            <div class="mt-5 grid gap-4 md:grid-cols-3">
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
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <flux:field class="relative">
                    <flux:label>Email <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model="email" type="email" :invalid="$errors->has('email')" />
                </flux:field>
                <flux:field class="relative">
                    <flux:label>Contact number <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model="contactNo" placeholder="09XXXXXXXXX" :invalid="$errors->has('contactNo')" />
                </flux:field>
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <x-ph-province-select :options="$this->provinceOptions()" />
                <x-ph-city-select :options="$this->cityOptions()" :enabled="$province !== ''" :listed="count($this->provinceOptions()) > 0" label="City/Municipality" />
                <x-ph-barangay-select :options="$this->barangayOptions()" :enabled="$city !== ''" :listed="count($this->provinceOptions()) > 0" />
                <flux:field class="relative">
                    <flux:label>Purok/Street</flux:label>
                    <flux:input wire:model="purok" />
                </flux:field>
            </div>
        </flux:card>

        @if ($this->showRoomOccupants())
            <flux:card>
                <flux:heading size="lg">Room occupants</flux:heading>
                <flux:text class="mt-1">Every room occupant must be named. The system will attach these names to the exact room automatically assigned at submission.</flux:text>
                <div class="mt-5 space-y-5">
                    @foreach ($roomOccupants as $roomIndex => $occupants)
                        <div wire:key="room-occupants-{{ $roomIndex }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                            <div class="mb-3 flex items-center justify-between">
                                <p class="font-semibold">Room {{ $roomIndex + 1 }}</p>
                                <flux:badge color="blue">{{ count($occupants) }} occupant{{ count($occupants) === 1 ? '' : 's' }}</flux:badge>
                            </div>
                            <div class="space-y-3">
                                @foreach ($occupants as $occupantIndex => $occupant)
                                    <div wire:key="room-{{ $roomIndex }}-occupant-{{ $occupantIndex }}" class="grid gap-3 md:grid-cols-3">
                                        <flux:field class="relative">
                                            <flux:label>Occupant {{ $occupantIndex + 1 }} first name <span class="text-red-500">*</span></flux:label>
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

        <flux:card>
            <flux:heading size="lg">GCash payment verification</flux:heading>
            <flux:text class="mt-1">
                @if ($bookingMode)
                    Direct online booking requires 100% payment.
                @else
                    Reservation requires at least 50% of the final discounted total. 
                @endif
                The payment remains Pending until a Cashier verifies the reference and proof.
            </flux:text>
            <div class="mt-5 grid gap-4 md:grid-cols-2">
                <flux:field class="relative">
                    <flux:label>Payment amount <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model="paymentAmount" type="number" step="0.01" min="0.01" :readonly="$bookingMode" :invalid="$errors->has('paymentAmount')" />
                </flux:field>
                <flux:field class="relative">
                    <flux:label>GCash reference number <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model="referenceNumber" :invalid="$errors->has('referenceNumber')" />
                </flux:field>
            </div>
            @if (! $bookingMode && $planTotal !== '')
                <p class="mt-2 text-xs text-zinc-500">Minimum deposit: ₱{{ number_format((float) app(\App\Services\DecimalMoneyService::class)->percentage($planTotal, '0.500000'), 2) }} · Maximum: ₱{{ number_format((float) $planTotal, 2) }}</p>
            @endif
            <div class="mt-4">
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    Proof of payment <span class="text-red-600">*</span>
                </label>

                @if ($proofOfPayment === null)
                    @php($proofInvalid = $errors->has('proofOfPayment') || $errors->has('proofPhoto'))
                    <div @class([
                        'mt-2 rounded-lg border border-dashed p-3',
                        'border-red-500 bg-red-50/50 dark:bg-red-950/20' => $proofInvalid,
                        'border-zinc-300 dark:border-zinc-700' => ! $proofInvalid,
                    ])>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            wire:model="proofPhoto" x-data x-on:livewire-upload-finish="$el.value = ''"
                            class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium dark:text-zinc-300 dark:file:bg-zinc-800"
                        />
                        <p class="mt-1 text-xs text-zinc-500">JPG, PNG or WebP · 1 photo only · up to 16 MB. Stored privately. It is only sent when you submit.</p>
                        <p wire:loading wire:target="proofPhoto" class="mt-1 text-xs font-medium text-blue-600 dark:text-blue-400">Preparing photo…</p>
                    </div>
                @else
                    <div class="mt-2 rounded-lg border border-dashed border-blue-300 bg-blue-50/50 p-3 dark:border-blue-800 dark:bg-blue-950/20">
                        <p class="text-sm font-medium">1 photo ready <span class="font-normal text-zinc-500">· not sent yet</span></p>
                        <div class="relative mt-3 w-40 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <img
                                src="{{ $proofOfPayment->temporaryUrl() }}"
                                alt="{{ $proofOfPayment->getClientOriginalName() }}"
                                class="aspect-square w-full object-cover"
                            />
                            <p class="truncate px-2 py-1 text-[11px] text-zinc-500 dark:text-zinc-400">{{ $proofOfPayment->getClientOriginalName() }} · {{ number_format($proofOfPayment->getSize() / 1048576, 2) }} MB</p>
                            <button
                                type="button"
                                wire:click="removeProofOfPayment"
                                class="absolute right-1 top-1 rounded-full bg-black/60 px-2 py-0.5 text-xs font-medium text-white hover:bg-red-600"
                                aria-label="Discard photo"
                            >
                                Discard
                            </button>
                        </div>
                        <p class="mt-2 text-xs text-zinc-500">Only 1 photo is allowed. Discard it to choose a different one.</p>
                    </div>
                @endif
            </div>
        </flux:card>

        {{-- Every error text shows in the shared toast; fields only turn red. --}}
        <x-error-toast :messages="$this->bannerErrors()" heading="Please fix the following:" :tick="$errorToastTick" />

        <p class="text-sm text-zinc-600 dark:text-zinc-300">
           <strong>Please carefully review all your information, especially your email address, as an incorrect email may prevent you from receiving the confirmation link and require you to repeat the reservation process. Your {{ $bookingMode ? 'booking' : 'reservation' }} and payment proof are only submitted after you click it (the link works for {{ \App\Services\GuestSubmissionConfirmationService::LINK_MINUTES }} minutes).</strong>
        </p>

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="reviewPolicy">Review policy and continue</flux:button>
        </div>
    </form>

    {{-- The confirmation email is only sent from this modal, after the guest ticks the checkbox. --}}
    <flux:modal wire:model="showPolicyModal" class="w-full max-h-[90vh] overflow-y-auto md:w-[40rem]">
        <div class="space-y-5">
            <div class="pe-8">
                <flux:heading size="lg">Booking, Cancellation, and Refund Policy</flux:heading>
                <flux:text class="mt-1">Please read this policy. We will email your confirmation link after you accept it.</flux:text>
            </div>

            <div class="max-h-[45vh] overflow-y-auto rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <x-booking-policy-content />
            </div>

            <div>
                <label class="flex items-start gap-3 text-sm text-zinc-800 dark:text-zinc-200">
                    <input
                        type="checkbox"
                        wire:model.live="acceptedPolicy"
                        class="mt-0.5 size-4 shrink-0 rounded border-zinc-300 text-emerald-700 focus:ring-emerald-600 dark:border-zinc-600"
                    />
                    <span>I have read and agreed to the Terms and Conditions of the Olaer Spring Resort <span class="text-red-500">*</span></span>
                </label>
                @error('acceptedPolicy')
                    <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showPolicyModal', false)">Back</flux:button>
                <flux:button
                    type="button"
                    variant="primary"
                    wire:click="submit"
                    wire:loading.attr="disabled"
                    wire:target="submit"
                    :disabled="! $acceptedPolicy"
                >
                    <span wire:loading.remove wire:target="submit">Send confirmation email</span>
                    <span wire:loading wire:target="submit">Sending…</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>