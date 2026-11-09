<?php

use App\Models\BookingDetail;
use App\Models\Facility;
use App\Models\FacilityProduct;
use App\Services\BookingWorkflowService;
use App\Services\FacilityAvailabilityService;
use App\Services\RoomOccupantService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {

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
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'date_from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'date_to', except: '')]
    public string $dateTo = '';

    #[Url(as: 'booking_status', except: '')]
    public string $bookingStatusFilter = '';

    #[Url(as: 'facility_status', except: '')]
    public string $detailStatusFilter = '';

    #[Url(as: 'sort', except: 'booking_id')]
    public string $sortField = 'booking_id';

    #[Url(as: 'direction', except: 'desc')]
    public string $sortDirection = 'desc';

    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    public array $rescheduleForm = [
        'booking_details_id' => '',
        'label' => '',
        'original_days' => '',
        'new_check_in_date' => '',
        'new_check_out_date' => '',
    ];

    public array $transferForm = [
        'booking_details_id' => '',
        'label' => '',
        'facility_product_id' => '',
        'facility_id' => '',
    ];

    public array $cancelDetailForm = [
        'booking_details_id' => '',
        'label' => '',
        'reason' => '',
    ];

    public bool $showRescheduleModal = false;
    public bool $showTransferModal = false;
    public bool $showExtendModal = false;
    public bool $showOccupantsModal = false;

    public array $extendForm = [
        'booking_details_id' => '',
        'label' => '',
    ];

    public array $occupantForm = [
        'booking_details_id' => '',
        'label' => '',
        'occupants' => [],
    ];

    public function with(): array
    {
        return [
            'bookings' => $this->bookings(),
            'bookingStatuses' => $this->bookingStatuses(),
            'detailStatuses' => $this->detailStatuses(),
            'transferProducts' => $this->transferProducts(),
            'transferFacilities' => $this->transferFacilities(),
        ];
    }

    public function updatedTransferForm(mixed $value, string $key): void
    {
        // A different destination product means the facility must be chosen again.
        if ($key === 'facility_product_id') {
            $this->transferForm['facility_id'] = '';
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedBookingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDetailStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 25, 50, 100], true)) {
            $this->perPage = 10;
        }

        $this->resetPage();
    }

    public function clearListFilters(): void
    {
        $this->search = '';
        $this->bookingStatusFilter = '';
        $this->detailStatusFilter = '';
        $this->sortField = 'booking_id';
        $this->sortDirection = 'desc';
        $this->perPage = 10;
        $this->dateFrom = '';
        $this->dateTo = '';

        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        $allowed = [
            'booking_id',
            'b_ref_no',
            'booking_date',
            'guest_name',
            'facility_name',
            'total_price',
            'amount_due',
            'booking_status',
            'status',
        ];

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

    public function sortIndicator(string $field): string
    {
        if ($this->sortField !== $field) {
            return '↕';
        }

        return $this->sortDirection === 'asc'
            ? '↑'
            : '↓';
    }

    public function openReschedule(int $bookingDetailsId): void
    {
        $detail = BookingDetail::query()->with(['booking', 'facility'])->find($bookingDetailsId);

        if (! $detail?->booking || ! $detail->facility) {
            $this->cancelReschedule();
            $this->addError('booking', 'This booking facility is no longer available. Refresh the list and try again.');

            return;
        }

        $this->resetValidation();
        $this->rescheduleForm = [
            'booking_details_id' => (string) $bookingDetailsId,
            'label' => $detail->booking->b_ref_no . ' - ' . $detail->facility->facility_name,
            // Display only. saveReschedule() re-reads the real length from the database.
            'original_days' => (string) app(BookingWorkflowService::class)->stayDays($detail->check_in_date, $detail->check_out_date),
            'new_check_in_date' => \Illuminate\Support\Carbon::parse($detail->check_in_date)->toDateString(),
            'new_check_out_date' => \Illuminate\Support\Carbon::parse($detail->check_out_date)->toDateString(),
        ];
        $this->showRescheduleModal = true;
    }

    public function cancelReschedule(): void
    {
        $this->showRescheduleModal = false;
        $this->rescheduleForm = [
            'booking_details_id' => '',
            'label' => '',
            'original_days' => '',
            'new_check_in_date' => '',
            'new_check_out_date' => '',
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        $unavailable = 'This booking facility is no longer available. Refresh the list and try again.';

        return [
            'rescheduleForm.booking_details_id.required' => $unavailable,
            'rescheduleForm.booking_details_id.integer' => $unavailable,
            'rescheduleForm.booking_details_id.exists' => $unavailable,
            'rescheduleForm.new_check_in_date.required' => 'Choose the new check-in date.',
            'rescheduleForm.new_check_in_date.date_format' => 'Enter a valid check-in date.',
            'rescheduleForm.new_check_in_date.after_or_equal' => 'The new check-in date cannot be in the past.',
            'rescheduleForm.new_check_out_date.required' => 'Choose the new check-out date.',
            'rescheduleForm.new_check_out_date.date_format' => 'Enter a valid check-out date.',
            'rescheduleForm.new_check_out_date.after_or_equal' => 'The new check-out date cannot be before the new check-in date.',

            'transferForm.booking_details_id.required' => $unavailable,
            'transferForm.booking_details_id.integer' => $unavailable,
            'transferForm.booking_details_id.exists' => $unavailable,
            'transferForm.facility_product_id.required' => 'Choose the facility to transfer to.',
            'transferForm.facility_product_id.integer' => 'Choose a facility from the list.',
            'transferForm.facility_product_id.exists' => 'That facility is no longer available. Choose another one.',
            'transferForm.facility_id.required' => 'Choose the facility to assign.',
            'transferForm.facility_id.integer' => 'Choose a facility from the list.',
            'transferForm.facility_id.exists' => 'That facility is no longer available. Choose another one.',

            'cancelDetailForm.booking_details_id.required' => $unavailable,
            'cancelDetailForm.booking_details_id.integer' => $unavailable,
            'cancelDetailForm.booking_details_id.exists' => $unavailable,
            'cancelDetailForm.reason.required' => 'Enter a reason for cancelling this facility.',
            'cancelDetailForm.reason.max' => 'The cancellation reason cannot be longer than :max characters.',

            'occupantForm.booking_details_id.required' => $unavailable,
            'occupantForm.booking_details_id.integer' => $unavailable,
            'occupantForm.booking_details_id.exists' => $unavailable,
            'occupantForm.occupants.required' => 'There are no occupants to save for this facility.',
            'occupantForm.occupants.min' => 'There are no occupants to save for this facility.',
            'occupantForm.occupants.max' => 'You can save up to :max occupants.',
            'occupantForm.occupants.*.first_name.required' => "Enter this occupant's first name.",
            'occupantForm.occupants.*.first_name.max' => 'The first name cannot be longer than :max characters.',
            'occupantForm.occupants.*.last_name.required' => "Enter this occupant's last name.",
            'occupantForm.occupants.*.last_name.max' => 'The last name cannot be longer than :max characters.',
            'occupantForm.occupants.*.middle_name.max' => 'The middle name cannot be longer than :max characters.',
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'rescheduleForm.new_check_in_date' => 'new check-in date',
            'rescheduleForm.new_check_out_date' => 'new check-out date',
            'transferForm.facility_product_id' => 'facility product',
            'transferForm.facility_id' => 'facility',
            'cancelDetailForm.reason' => 'cancellation reason',
            'occupantForm.occupants' => 'occupants',
            'occupantForm.occupants.*.first_name' => 'occupant first name',
            'occupantForm.occupants.*.middle_name' => 'occupant middle name',
            'occupantForm.occupants.*.last_name' => 'occupant last name',
        ];
    }

    public function saveReschedule(BookingWorkflowService $bookingWorkflow): void
    {
        // The original length comes from the database, never from the form state.
        $current = BookingDetail::query()->find((int) ($this->rescheduleForm['booking_details_id'] ?? 0));
        $originalDays = $current === null
            ? null
            : $bookingWorkflow->stayDays($current->check_in_date, $current->check_out_date);

        $validated = $this->validate([
            'rescheduleForm.booking_details_id' => ['required', 'integer', 'exists:tbl_booking_details,booking_details_id'],
            'rescheduleForm.new_check_in_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'rescheduleForm.new_check_out_date' => [
                'bail',
                'required',
                'date_format:Y-m-d',
                'after_or_equal:rescheduleForm.new_check_in_date',
                function (string $attribute, mixed $value, \Closure $fail) use ($bookingWorkflow, $originalDays): void {
                    $checkIn = (string) ($this->rescheduleForm['new_check_in_date'] ?? '');

                    if (
                        $originalDays === null
                        || validator(['d' => $checkIn], ['d' => ['required', 'date_format:Y-m-d']])->fails()
                    ) {
                        return; // the other rules report these
                    }

                    $newDays = $bookingWorkflow->stayDays($checkIn, (string) $value);

                    if ($newDays !== $originalDays) {
                        $fail($bookingWorkflow->stayLengthMismatchMessage($originalDays, $newDays));
                    }
                },
            ],
        ]);

        try {
            $bookingWorkflow->rescheduleBookingDetail(
                (int) $validated['rescheduleForm']['booking_details_id'],
                $validated['rescheduleForm']['new_check_in_date'],
                $validated['rescheduleForm']['new_check_out_date'],
            );

            $this->cancelReschedule();
            $this->resetPage();
            session()->flash('success', 'Booking rescheduled successfully.');
        } catch (\InvalidArgumentException $exception) {
            $this->addError('reschedule', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('reschedule', 'The booking could not be rescheduled. Refresh the list and try again.');
        }
    }

    public function openTransfer(int $bookingDetailsId): void
    {
        $detail = BookingDetail::query()->with(['booking', 'facility'])->find($bookingDetailsId);

        if (! $detail?->booking || ! $detail->facility) {
            $this->cancelTransfer();
            $this->addError('booking', 'This booking facility is no longer available. Refresh the list and try again.');

            return;
        }

        $this->transferForm = [
            'booking_details_id' => (string) $bookingDetailsId,
            'label' => $detail->booking->b_ref_no . ' - ' . $detail->facility->facility_name,
            'facility_product_id' => (string) ($detail->facility_product_id ?? ''),
            'facility_id' => '',
        ];
        $this->resetValidation();
        $this->showTransferModal = true;
    }

    public function cancelTransfer(): void
    {
        $this->showTransferModal = false;
        $this->transferForm = [
            'booking_details_id' => '',
            'label' => '',
            'facility_product_id' => '',
            'facility_id' => '',
        ];
    }

    public function saveTransfer(BookingWorkflowService $bookingWorkflow): void
    {
        $validated = $this->validate([
            'transferForm.booking_details_id' => ['required', 'integer', 'exists:tbl_booking_details,booking_details_id'],
            'transferForm.facility_product_id' => ['required', 'integer', 'exists:tbl_facility_product,facility_product_id'],
            'transferForm.facility_id' => ['required', 'integer', 'exists:tbl_facility,facility_id'],
        ]);

        try {
            $facilityId = (int) $validated['transferForm']['facility_id'];

            // The chosen facility must belong to the chosen destination product.
            if (! Facility::query()
                ->whereKey($facilityId)
                ->where('facility_product_id', (int) $validated['transferForm']['facility_product_id'])
                ->exists()
            ) {
                throw new \InvalidArgumentException('That facility does not belong to the chosen facility product.');
            }

            // Transfer to the exact facility the cashier picked.
            $bookingWorkflow->transferBookingDetail(
                (int) $validated['transferForm']['booking_details_id'],
                $facilityId,
            );

            $this->cancelTransfer();
            $this->resetPage();
            session()->flash('success', 'Facility transfer saved successfully. If there was an upgrade charge, it was added to amount due.');
        } catch (\InvalidArgumentException $exception) {
            $this->addError('transfer', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('transfer', 'The facility could not be transferred. Refresh the list and try again.');
        }
    }

    public function openExtend(int $bookingDetailsId): void
    {
        $detail = BookingDetail::query()->with(['booking', 'facility'])->find($bookingDetailsId);

        if (! $detail?->booking || ! $detail->facility) {
            $this->cancelExtend();
            $this->addError('booking', 'This booking facility is no longer available. Refresh the list and try again.');

            return;
        }

        $this->resetValidation();
        $this->extendForm = [
            'booking_details_id' => (string) $bookingDetailsId,
            'label' => $detail->booking->b_ref_no . ' - ' . $detail->facility->facility_name,
        ];
        $this->showExtendModal = true;
    }

    public function cancelExtend(): void
    {
        $this->showExtendModal = false;
        $this->extendForm = [
            'booking_details_id' => '',
            'label' => '',
        ];
    }

    public function confirmExtend(BookingWorkflowService $bookingWorkflow): void
    {
        $id = (int) ($this->extendForm['booking_details_id'] ?? 0);

        if ($id < 1) {
            $this->cancelExtend();

            return;
        }

        $this->extendBooking($id, $bookingWorkflow);
    }

    public function extendBooking(int $bookingDetailsId, BookingWorkflowService $bookingWorkflow): void
    {
        try {
            $bookingWorkflow->extendCottageDayRate($bookingDetailsId);
            $this->cancelExtend();
            $this->resetPage();
            session()->flash('success', 'Cottage extension recorded. The extension charge was added to amount due.');
        } catch (\InvalidArgumentException $exception) {
            $this->addError('extend', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('extend', 'The cottage could not be extended. Refresh the list and try again.');
        }
    }

    public function openCancelDetail(int $bookingDetailsId): void
    {
        $detail = BookingDetail::query()->with(['booking', 'facility'])->find($bookingDetailsId);

        if (! $detail?->booking || ! $detail->facility) {
            $this->cancelCancelDetail();
            $this->addError('booking', 'This booking facility is no longer available. Refresh the list and try again.');

            return;
        }

        $this->cancelDetailForm = [
            'booking_details_id' => (string) $bookingDetailsId,
            'label' => $detail->booking?->b_ref_no.' - '.$detail->facility?->facility_name,
            'reason' => '',
        ];
    }

    public function cancelCancelDetail(): void
    {
        $this->cancelDetailForm = [
            'booking_details_id' => '',
            'label' => '',
            'reason' => '',
        ];
    }

    public function saveCancelDetail(BookingWorkflowService $workflow): void
    {
        $validated = $this->validate([
            'cancelDetailForm.booking_details_id' => ['required', 'integer', 'exists:tbl_booking_details,booking_details_id'],
            'cancelDetailForm.reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $workflow->cancelBookingDetail(
                (int) $validated['cancelDetailForm']['booking_details_id'],
                $validated['cancelDetailForm']['reason'],
                (int) Auth::id(),
            );
            $this->cancelCancelDetail();
            session()->flash('success', 'Facility cancelled. Paid value remains as same-transaction credit.');
        } catch (\InvalidArgumentException $exception) {
            $this->addError('cancelDetail', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('cancelDetail', 'The facility could not be cancelled. Refresh the list and try again.');
        }
    }

    public function openOccupants(int $bookingDetailsId): void
    {
        $detail = BookingDetail::query()
            ->with(['booking', 'facility', 'roomOccupants'])
            ->find($bookingDetailsId);

        if (! $detail?->booking || ! $detail->facility) {
            $this->cancelOccupants();
            $this->addError('booking', 'This booking facility is no longer available. Refresh the list and try again.');

            return;
        }

        $this->occupantForm = [
            'booking_details_id' => (string) $bookingDetailsId,
            'label' => $detail->booking?->b_ref_no.' - '.$detail->facility?->facility_name,
            'occupants' => $detail->roomOccupants->map(fn ($occupant): array => [
                'first_name' => $occupant->first_name,
                'middle_name' => $occupant->middle_name ?? '',
                'last_name' => $occupant->last_name,
            ])->all(),
        ];
        $this->resetValidation();
        $this->showOccupantsModal = true;
    }

    public function cancelOccupants(): void
    {
        $this->showOccupantsModal = false;
        $this->occupantForm = [
            'booking_details_id' => '',
            'label' => '',
            'occupants' => [],
        ];
    }

    public function saveOccupants(RoomOccupantService $occupants): void
    {
        $validated = $this->validate([
            'occupantForm.booking_details_id' => ['required', 'integer', 'exists:tbl_booking_details,booking_details_id'],
            'occupantForm.occupants' => ['required', 'array', 'min:1', 'max:50'],
            'occupantForm.occupants.*' => ['required', 'array'],
            'occupantForm.occupants.*.first_name' => ['required', 'string', 'max:50'],
            'occupantForm.occupants.*.middle_name' => ['nullable', 'string', 'max:50'],
            'occupantForm.occupants.*.last_name' => ['required', 'string', 'max:50'],
        ]);

        try {
            $occupants->replaceBookingOccupants(
                (int) $validated['occupantForm']['booking_details_id'],
                $validated['occupantForm']['occupants'],
                (int) Auth::id(),
            );
            $this->cancelOccupants();
            session()->flash('success', 'Room occupant names updated and audited.');
        } catch (\InvalidArgumentException $exception) {
            $this->addError('occupants', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('occupants', 'Room occupants could not be saved. Refresh the list and try again.');
        }
    }

    private function bookings()
    {
        $query = DB::table('tbl_booking')
            ->join('tbl_guest', 'tbl_guest.guest_id', '=', 'tbl_booking.guest_id')
            ->leftJoin('tbl_booking_details', 'tbl_booking_details.booking_id', '=', 'tbl_booking.booking_id')
            ->leftJoin('tbl_facility', 'tbl_facility.facility_id', '=', 'tbl_booking_details.facility_id')
            ->leftJoin('tbl_facility_type', 'tbl_facility_type.facility_type_id', '=', 'tbl_facility.facility_type_id')
            ->select([
                'tbl_booking.booking_id',
                'tbl_booking.b_ref_no',
                'tbl_booking.booking_date',
                'tbl_booking.total_price',
                'tbl_booking.amount_due',
                'tbl_booking.no_of_extra_guests',
                'tbl_booking.status as booking_status',
                'tbl_booking_details.booking_details_id',
                'tbl_booking_details.rate_type',
                'tbl_booking_details.check_in_date',
                'tbl_booking_details.check_out_date',
                'tbl_booking_details.status',
                'tbl_facility.facility_name',
                DB::raw("(
                    tbl_facility_type.facility_type IN ('Cottage', 'Function Hall')
                    AND tbl_booking_details.rate_code = 'DAY'
                    AND COALESCE(tbl_booking_details.status, '') NOT IN ('Checked-out', 'Cancelled', 'Payment Rejected', 'Rejected')
                    AND COALESCE(tbl_booking.status, '') NOT IN ('Cancelled', 'Checked-out', 'Payment Rejected', 'Rejected', 'Pending Verification')
                    AND NOT EXISTS (
                        SELECT 1
                        FROM tbl_facility_schedule_blocks night_block
                        WHERE night_block.facility_id = tbl_booking_details.facility_id
                            AND night_block.slot = 'night'
                            AND night_block.service_date = DATE(tbl_booking_details.check_in_date)
                    )
                ) as can_extend"),
                DB::raw("CONCAT(tbl_guest.first_name, ' ', COALESCE(tbl_guest.middle_name, ''), ' ', tbl_guest.last_name) as guest_name"),
            ]);

        if ($this->search !== '') {
            $search = '%' . $this->search . '%';
            $query->where(function ($subQuery) use ($search): void {
                $subQuery->where('tbl_booking.b_ref_no', 'like', $search)
                    ->orWhere('tbl_guest.first_name', 'like', $search)
                    ->orWhere('tbl_guest.last_name', 'like', $search)
                    ->orWhereRaw(\App\Support\NameSearch::sql('tbl_guest.first_name', 'tbl_guest.middle_name', 'tbl_guest.last_name'), \App\Support\NameSearch::bindings($search))
                    ->orWhere('tbl_guest.contact_no', 'like', $search)
                    ->orWhere('tbl_guest.email', 'like', $search)
                    ->orWhere('tbl_facility.facility_name', 'like', $search);
            });
        }

        if ($this->bookingStatusFilter !== '') {
            $query->where('tbl_booking.status', $this->bookingStatusFilter);
        }

        if ($this->detailStatusFilter !== '') {
            $query->where('tbl_booking_details.status', $this->detailStatusFilter);
        }

        if($this->dateFrom !== ''){
            $query->where('tbl_booking_details.check_in_date', $this->dateFrom);
        }
        if($this->dateTo !== ''){
            $query->where('tbl_booking_details.check_out_date', $this->dateTo);
        }


        $sortMap = [
            'booking_id' => 'tbl_booking.booking_id',
            'b_ref_no' => 'tbl_booking.b_ref_no',
            'booking_date' => 'tbl_booking.booking_date',
            'guest_name' => 'guest_name',
            'facility_name' => 'tbl_facility.facility_name',
            'total_price' => 'tbl_booking.total_price',
            'amount_due' => 'tbl_booking.amount_due',
            'booking_status' => 'tbl_booking.status',
            'status' => 'tbl_booking_details.status',
        ];

        $query->orderBy(
            $sortMap[$this->sortField] ?? 'tbl_booking.booking_id',
            $this->sortDirection === 'asc' ? 'asc' : 'desc',
        );

        $perPage = in_array($this->perPage, [10, 25, 50, 100], true)
            ? $this->perPage
            : 10;

        return $query->paginate($perPage);
    }

    private function bookingStatuses()
    {
        return DB::table('tbl_booking')
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->distinct()
            ->orderBy('status')
            ->pluck('status');
    }

    private function detailStatuses()
    {
        return DB::table('tbl_booking_details')
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->distinct()
            ->orderBy('status')
            ->pluck('status');
    }

    /** Facilities of the chosen product that are free for this booking's schedule. */
    private function transferFacilities()
    {
        $productId = (int) ($this->transferForm['facility_product_id'] ?? 0);
        $detailId = (int) ($this->transferForm['booking_details_id'] ?? 0);

        if ($productId < 1 || $detailId < 1) {
            return collect();
        }

        $detail = BookingDetail::query()->find($detailId);

        if (! $detail) {
            return collect();
        }

        try {
            $facilityIds = app(FacilityAvailabilityService::class)->availableFacilityIds(
                $productId,
                $detail->getRawOriginal('rate_code') ?: (string) $detail->rate_type,
                Carbon::parse($detail->check_in_date)->toDateString(),
                Carbon::parse($detail->check_out_date)->toDateString(),
            );
        } catch (\Throwable) {
            return collect();
        }

        return Facility::query()
            ->whereIn('facility_id', $facilityIds->all())
            ->get(['facility_id', 'facility_name'])
            ->sortBy('facility_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    private function transferProducts()
    {
        if ($this->transferForm['booking_details_id'] === '') {
            return collect();
        }

        $detail = BookingDetail::query()
            ->with('facility')
            ->find((int) $this->transferForm['booking_details_id']);

        if (! $detail || ! $detail->facility) {
            return collect();
        }

        return FacilityProduct::query()
            ->where('facility_type_id', $detail->facility->facility_type_id)
            ->where('is_active', true)
            ->orderBy('display_name')
            ->get();
    }

};
?>

<div class="space-y-6">
    <x-staff-page-header
        
        title="Booking Management"
        description="Create paid facility bookings, reschedule stays, transfer facilities, and extend eligible cottage bookings."
    >
        <x-slot:actions>
            <flux:button href="{{ route('cashier.bookings.create') }}" variant="primary" wire:navigate>
                New booking
            </flux:button>
        </x-slot:actions>
    </x-staff-page-header>

    <x-toast :message="session('success')" fresh />

    {{-- One banner for page-level problems (service errors and stale-row errors), instead of four stacked ones. --}}
    @php
        $pageError = collect([
            'booking',
            'cancelDetailForm.booking_details_id',
        ])->map(fn (string $key): ?string => $errors->first($key) ?: null)->filter()->first();
    @endphp
    @if ($pageError)
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ $pageError }}</div>
    @endif

    <flux:card class="overflow-hidden p-0">
        

            <div class="grid gap-3 border-b border-zinc-200 p-5 md:grid-cols-6 dark:border-zinc-800">
                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        label="Search"
                        placeholder="Reference, guest"
                        clearable
                    />
                    <flux:select wire:model.live="bookingStatusFilter" label="Booking status">
                        <option value="">All </option>
                        @foreach ($bookingStatuses as $bookingStatus)
                            <option value="{{ $bookingStatus }}">{{ $bookingStatus }}</option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="detailStatusFilter" label="Facility status">
                        <option value="">All </option>
                        @foreach ($detailStatuses as $detailStatus)
                            <option value="{{ $detailStatus }}">{{ $detailStatus }}</option>
                        @endforeach
                    </flux:select>

                    <flux:input
                        wire:model.live="dateFrom"
                        type="date"
                        label="From"
                    />
                    <flux:input
                        wire:model.live="dateTo"
                        type="date"
                        label="To"
                    />
                    <div class="flex items-end gap-2">
                    <flux:select wire:model.live="perPage" label="Rows">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </flux:select>

                <flux:button type="button" wire:click="clearListFilters" variant="ghost">Clear Filters</flux:button>
                </div>
            </div>

        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,bookingStatusFilter,detailStatusFilter,perPage,sortBy,clearListFilters">
            <table class="w-full min-w-[86rem] text-left text-sm">
                <thead class="border-b border-brand-border bg-zinc-50 text-xs uppercase tracking-wide text-brand-text-muted dark:border-zinc-800 dark:bg-zinc-950/60 dark:text-zinc-400">
                    <tr>
                        <th class="px-5 py-3"><button type="button" wire:click="sortBy('b_ref_no')" class="font-semibold hover:text-brand-text dark:hover:text-white">REFERENCE {{ $this->sortIndicator('b_ref_no') }}</button></th>
                        <th class="px-5 py-3"><button type="button" wire:click="sortBy('guest_name')" class="font-semibold hover:text-brand-text dark:hover:text-white">GUEST {{ $this->sortIndicator('guest_name') }}</button></th>
                        <th class="px-5 py-3"><button type="button" wire:click="sortBy('facility_name')" class="font-semibold hover:text-brand-text dark:hover:text-white">FACILITY {{ $this->sortIndicator('facility_name') }}</button></th>
                        <th class="px-5 py-3"><button type="button" wire:click="sortBy('booking_date')" class="font-semibold hover:text-brand-text dark:hover:text-white">BOOKED ON {{ $this->sortIndicator('booking_date') }}</button></th>
                        <th class="px-5 py-3 font-semibold">Date Range</th>
                        <th class="px-5 py-3 font-semibold">Rate</th>
                        <th class="px-5 py-3"><button type="button" wire:click="sortBy('total_price')" class="font-semibold hover:text-brand-text dark:hover:text-white">TOTAL {{ $this->sortIndicator('total_price') }}</button></th>
                        <th class="px-5 py-3"><button type="button" wire:click="sortBy('amount_due')" class="font-semibold hover:text-brand-text dark:hover:text-white">BALANCE {{ $this->sortIndicator('amount_due') }}</button></th>
                        <th class="px-5 py-3"><button type="button" wire:click="sortBy('booking_status')" class="font-semibold hover:text-brand-text dark:hover:text-white">BOOKING STATUS {{ $this->sortIndicator('booking_status') }}</button></th>
                        <th class="px-5 py-3"><button type="button" wire:click="sortBy('status')" class="font-semibold hover:text-brand-text dark:hover:text-white">FACILITY STATUS {{ $this->sortIndicator('status') }}</button></th>
                        <th class="px-5 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($bookings as $booking)
                        <tr wire:key="booking-row-{{ $booking->booking_id }}-{{ $booking->booking_details_id }}" class="text-brand-text transition-colors hover:bg-brand-surface-muted/70 dark:text-zinc-200 dark:hover:bg-zinc-800/60">
                            <td class="px-5 py-4 font-semibold">{{ $booking->b_ref_no }}</td>
                            <td class="px-5 py-4">{{ trim($booking->guest_name) }}</td>
                            <td class="px-5 py-4">{{ $booking->facility_name ?? 'No facility' }}</td>
                            <td class="px-5 py-4">{{ \Carbon\Carbon::parse($booking->booking_date)->format('M d, Y')}}</td>
                            <td class="px-5 py-4 whitespace-nowrap">{{ \Carbon\Carbon::parse($booking->check_in_date)->format('M d, Y')}} → {{ \Carbon\Carbon::parse($booking->check_out_date)->format('M d, Y')}}</td>
                            <td class="px-5 py-4">{{ $booking->rate_type }}</td>
                            <td class="px-5 py-4 font-medium">₱{{ number_format((float) $booking->total_price, 2) }}</td>
                            <td class="px-5 py-4 font-medium">₱{{ number_format((float) $booking->amount_due, 2) }}</td>
                            <td class="px-5 py-4">
                                <x-status-badge :status="(string) $booking->booking_status" />
                            </td>
                            <td class="px-5 py-4">
                                <x-status-badge :status="(string) $booking->status" />
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end">
                                    <x-table-actions label="Actions for booking {{ $booking->b_ref_no }}">
                                        @if (Route::has('cashier.bookings.show'))
                                            <flux:menu.item
                                                icon="eye"
                                                href="{{ route('cashier.bookings.show', $booking->booking_id) }}"
                                                wire:navigate
                                            >
                                                View booking
                                            </flux:menu.item>
                                        @endif

                                        @if ($booking->booking_details_id)
                                            <flux:menu.separator />
                                            <flux:menu.item icon="calendar-days" wire:click="openReschedule({{ $booking->booking_details_id }})">Reschedule</flux:menu.item>
                                            <flux:menu.item icon="arrows-right-left" wire:click="openTransfer({{ $booking->booking_details_id }})">Transfer</flux:menu.item>
                                            <flux:menu.item icon="clock" wire:click="openExtend({{ $booking->booking_details_id }})">Extend</flux:menu.item>
                                            <flux:menu.item icon="user-group" wire:click="openOccupants({{ $booking->booking_details_id }})">Occupants</flux:menu.item>
                                            <flux:menu.separator />
                                        @endif
                                    </x-table-actions>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="px-5 py-6">
                                <x-dashboard-empty-state
                                    title="No booking records found"
                                    description="No booking facility records match the current search and status filters."
                                    class="border-0 bg-transparent py-6 shadow-none dark:bg-transparent"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>


        </div>

        <div class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-800">
            {{ $bookings->links() }}
        </div>
    </flux:card>
    {{-- Reschedule --}}
    <flux:modal wire:model="showRescheduleModal" class="md:w-[34rem]">
        @php $rescheduleError = $errors->first('reschedule') ?: $errors->first('rescheduleForm.booking_details_id'); @endphp
        <form wire:submit.prevent="saveReschedule" novalidate class="space-y-5">
            <div>
                <flux:heading size="lg">Reschedule booking</flux:heading>
                <flux:text class="mt-1">{{ $rescheduleForm['label'] }}</flux:text>
            </div>

            @if ($rescheduleError)
                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ $rescheduleError }}</div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:field class="relative">
                    <flux:label>New check-in date <span class="text-red-500">*</span></flux:label>
                    <flux:input type="date" wire:model="rescheduleForm.new_check_in_date" :invalid="$errors->has('rescheduleForm.new_check_in_date')" />
                    @if ($msg = $this->visibleError('rescheduleForm.new_check_in_date'))
                        <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                    @endif
                </flux:field>
                <flux:field class="relative">
                    <flux:label>New check-out date <span class="text-red-500">*</span></flux:label>
                    <flux:input type="date" wire:model="rescheduleForm.new_check_out_date" :invalid="$errors->has('rescheduleForm.new_check_out_date')" />
                    @if ($msg = $this->visibleError('rescheduleForm.new_check_out_date'))
                        <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                    @endif
                </flux:field>
            </div>


            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="cancelReschedule">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Save Reschedule</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Transfer --}}
    <flux:modal wire:model="showTransferModal" class="md:w-[34rem]">
        @php $transferError = $errors->first('transfer') ?: $errors->first('transferForm.booking_details_id'); @endphp
        <form wire:submit.prevent="saveTransfer" novalidate class="space-y-5">
            <div>
                <flux:heading size="lg">Transfer facility</flux:heading>
                <flux:text class="mt-1">{{ $transferForm['label'] }}</flux:text>
            </div>

            @if ($transferError)
                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ $transferError }}</div>
            @endif

            <div>
                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Destination product <span class="text-red-500">*</span></label>
                <select wire:model.live="transferForm.facility_product_id" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100{{ $errors->has('transferForm.facility_product_id') ? ' !border-red-500 !ring-1 !ring-red-500' : '' }}">
                    <option value="">Choose upgrade product</option>
                    @foreach ($transferProducts as $product)
                        <option value="{{ $product->facility_product_id }}">{{ $product->display_name }}</option>
                    @endforeach
                </select>
                @if ($message = $this->visibleError('transferForm.facility_product_id')) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @endif
            </div>

            <div>
                <flux:field class="relative">
                    <flux:label>Facility <span class="text-red-500">*</span></flux:label>
                    <flux:select wire:model="transferForm.facility_id" :invalid="$errors->has('transferForm.facility_id')">
                        <option value="">Choose a facility</option>
                        @foreach ($transferFacilities as $transferFacility)
                            <option value="{{ $transferFacility->facility_id }}">{{ $transferFacility->facility_name }}</option>
                        @endforeach
                    </flux:select>
                    @if ($message = $this->visibleError('transferForm.facility_id')) <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $message }}</p> @endif
                </flux:field>
                @if ($transferForm['facility_product_id'] !== '' && $transferFacilities->isEmpty())
                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">No facility of this product is free for this booking's schedule.</p>
                @endif
            </div>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="cancelTransfer">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Assign Transfer</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Extend --}}
    <flux:modal wire:model="showExtendModal" class="md:w-[30rem]">
        @php $extendError = $errors->first('extend'); @endphp
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Extend cottage booking</flux:heading>
                <flux:text class="mt-1">{{ $extendForm['label'] }}</flux:text>
            </div>

            <flux:text>
                This extends the Day Rate stay and adds the extension charge to the booking's amount due. Only eligible cottage or function hall bookings can be extended.
            </flux:text>

            @if ($extendError)
                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ $extendError }}</div>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="cancelExtend">Cancel</flux:button>
                <flux:button type="button" variant="primary" wire:click="confirmExtend">Confirm Extension</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Occupants --}}
    <flux:modal wire:model="showOccupantsModal" class="md:w-[46rem]">
        @php $occupantError = $errors->first('occupants') ?: $errors->first('occupantForm.occupants') ?: $errors->first('occupantForm.booking_details_id'); @endphp
        <form wire:submit="saveOccupants" novalidate class="space-y-5">
            <div>
                <flux:heading size="lg">Edit room occupants</flux:heading>
                <flux:text class="mt-1">{{ $occupantForm['label'] }}</flux:text>
            </div>

            @if ($occupantError)
                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ $occupantError }}</div>
            @endif

            <div class="max-h-[55vh] space-y-4 overflow-y-auto pr-1">
                @forelse ($occupantForm['occupants'] as $index => $occupant)
                    <div class="grid gap-3 md:grid-cols-3" wire:key="occupant-row-{{ $index }}">
                        <flux:field class="relative">
                            <flux:label>Occupant {{ $index + 1 }} first name <span class="text-red-500">*</span></flux:label>
                            <flux:input wire:model="occupantForm.occupants.{{ $index }}.first_name" :invalid="$errors->has('occupantForm.occupants.'.$index.'.first_name')" />
                            @if ($msg = $this->visibleError('occupantForm.occupants.'.$index.'.first_name'))
                                <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                            @endif
                        </flux:field>
                        <flux:input wire:model="occupantForm.occupants.{{ $index }}.middle_name" label="Middle name" />
                        <flux:field class="relative">
                            <flux:label>Last name <span class="text-red-500">*</span></flux:label>
                            <flux:input wire:model="occupantForm.occupants.{{ $index }}.last_name" :invalid="$errors->has('occupantForm.occupants.'.$index.'.last_name')" />
                            @if ($msg = $this->visibleError('occupantForm.occupants.'.$index.'.last_name'))
                                <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                            @endif
                        </flux:field>
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">No room occupants are attached to this facility.</p>
                @endforelse
            </div>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="cancelOccupants">Close</flux:button>
                <flux:button type="submit" variant="primary">Save Occupants</flux:button>
            </div>
        </form>
    </flux:modal>
</div>