<?php

use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\Facility;
use App\Models\FacilityProduct;
use App\Models\Guest;
use App\Models\ModeOfPayment;
use App\Models\RefundRequest;
use App\Services\BookingWorkflowService;
use App\Services\FacilityAvailabilityService;
use App\Services\RefundRequestWorkflowService;
use App\Services\StaffBookingCancellationService;
use App\Services\RoomOccupantService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    public function mount(): void
    {
        // The list starts on today; a date in the address bar still wins.
        if ($this->dateFrom === '' && ! request()->has('date_from')) {
            $this->dateFrom = now()->toDateString();
        }
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

    // Cancelling a whole booking
    public bool $showCancelBookingModal = false;
    public ?int $cancelBookingId = null;
    public string $cancelBookingReason = '';

    // Asking the admin for a refund on a cancelled booking
    public bool $showRefundModal = false;
    public ?int $refundBookingId = null;
    public string $refundNote = '';

    // Giving an approved refund back to the guest
    public bool $showReleaseModal = false;
    public ?int $releaseRequestId = null;
    public string $releaseAmount = '';
    public string $releaseModeId = '';
    public string $releaseNote = '';
    public string $releaseContact = '';

    public function with(): array
    {
        $bookings = $this->bookings();

        return [
            'bookings' => $bookings,
            'bookingModels' => $this->bookingModels($bookings),
            'refundBooking' => $this->refundBooking(),
            'releaseRequest' => $this->releaseRequest(),
            'refundModes' => ModeOfPayment::query()->orderBy('mode_of_payment_id')->get(),
            'bookingStatuses' => $this->bookingStatuses(),
            'detailStatuses' => $this->detailStatuses(),
            'transferProducts' => $this->transferProducts(),
            'transferFacilities' => $this->transferFacilities(),
        ];
    }

    public function updatedRescheduleForm(mixed $value, string $key): void
    {
        // The stay keeps its original length, so the check-out date follows the check-in date.
        if ($key === 'new_check_in_date') {
            $this->rescheduleForm['new_check_out_date'] = $this->checkOutAfterStay(
                (string) $value,
                (int) ($this->rescheduleForm['original_days'] ?? 0),
            );
        }
    }

    private function checkOutAfterStay(string $checkIn, int $days): string
    {
        if ($checkIn === '') {
            return '';
        }

        try {
            return \Illuminate\Support\Carbon::parse($checkIn)->addDays($days)->toDateString();
        } catch (\Throwable) {
            return '';
        }
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

    /** Joins and filters shared by the paged booking list and the detail-row lookup. */
    private function bookingScope($query)
    {
        $query
            ->join('tbl_guest', 'tbl_guest.guest_id', '=', 'tbl_booking.guest_id')
            ->leftJoin('tbl_booking_details', 'tbl_booking_details.booking_id', '=', 'tbl_booking.booking_id')
            ->leftJoin('tbl_facility', 'tbl_facility.facility_id', '=', 'tbl_booking_details.facility_id')
            ->leftJoin('tbl_facility_type', 'tbl_facility_type.facility_type_id', '=', 'tbl_facility.facility_type_id');

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

        if ($this->dateFrom !== '') {
            $query->where('tbl_booking_details.check_in_date', $this->dateFrom);
        }

        if ($this->dateTo !== '') {
            $query->where('tbl_booking_details.check_out_date', $this->dateTo);
        }

        return $query;
    }

    /**
     * One entry per booking (reference number). Each entry is the collection of
     * that booking's facility rows, so a booking with several facilities is a
     * single, collapsible line in the table.
     */
    private function bookings()
    {
        // Booking-level sort expressions; facility-level fields use MIN() so a
        // booking with several facilities still sorts as one row.
        $sortMap = [
            'booking_id' => 'tbl_booking.booking_id',
            'b_ref_no' => 'MIN(tbl_booking.b_ref_no)',
            'booking_date' => 'MIN(tbl_booking.booking_date)',
            'guest_name' => "MIN(CONCAT(tbl_guest.first_name, ' ', COALESCE(tbl_guest.middle_name, ''), ' ', tbl_guest.last_name))",
            'facility_name' => 'MIN(tbl_facility.facility_name)',
            'total_price' => 'MIN(tbl_booking.total_price)',
            'amount_due' => 'MIN(tbl_booking.amount_due)',
            'booking_status' => 'MIN(tbl_booking.status)',
            'status' => 'MIN(tbl_booking_details.status)',
        ];

        $direction = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        $perPage = in_array($this->perPage, [10, 25, 50, 100], true)
            ? $this->perPage
            : 10;

        $pageQuery = $this->bookingScope(DB::table('tbl_booking'))
            ->select('tbl_booking.booking_id')
            ->groupBy('tbl_booking.booking_id')
            ->orderByRaw(($sortMap[$this->sortField] ?? 'tbl_booking.booking_id') . ' ' . $direction)
            ->orderBy('tbl_booking.booking_id', 'desc');

        $paginator = $pageQuery->paginate($perPage);
        $bookingIds = $paginator->getCollection()->pluck('booking_id')->all();

        $rows = $bookingIds === []
            ? collect()
            : $this->bookingScope(DB::table('tbl_booking'))
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
                'tbl_facility_type.facility_type',
                'tbl_booking_details.rate_code',
                'tbl_guest.contact_no',
                'tbl_guest.email',
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
            ])
                ->whereIn('tbl_booking.booking_id', $bookingIds)
                ->orderBy('tbl_booking_details.booking_details_id')
                ->get()
                ->groupBy('booking_id');

        $paginator->setCollection(
            $paginator->getCollection()
                ->map(fn ($row) => $rows->get($row->booking_id))
                ->filter()
                ->values(),
        );

        return $paginator;
    }

    private function bookingStatuses()
    {
        // 'Cancelled' is always offered, even before the first cancellation exists.
        return DB::table('tbl_booking')
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->distinct()
            ->pluck('status')
            ->push('Cancelled')
            ->unique()
            ->sort()
            ->values();
    }

    private function detailStatuses()
    {
        // 'Cancelled' is always offered, even before the first cancellation exists.
        return DB::table('tbl_booking_details')
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->distinct()
            ->pluck('status')
            ->push('Cancelled')
            ->unique()
            ->sort()
            ->values();
    }


    /** The bookings on this page as models, for cancellation and refund details. */
    private function bookingModels($paginator)
    {
        $ids = $paginator->getCollection()
            ->map(fn ($rows) => (int) $rows->first()->booking_id)
            ->all();

        if ($ids === []) {
            return collect();
        }

        return Booking::query()
            ->with([
                'details',
                'payments',
                'reservation.payments',
                'refundRequests.reviewedBy',
                'refundRequests.refundModeOfPayment',
                'refundRequests.refundedBy',
                'refundRequests.gcashHandoffBy',
            ])
            ->whereIn('booking_id', $ids)
            ->get()
            ->keyBy('booking_id');
    }

    public function guestName(?Guest $guest): string
    {
        return trim(implode(' ', array_filter([
            $guest?->first_name,
            $guest?->middle_name,
            $guest?->last_name,
        ]))) ?: '—';
    }

    public function canCancelBooking(Booking $booking): bool
    {
        return app(StaffBookingCancellationService::class)->canCancel($booking);
    }

    public function beginBookingCancellation(int $bookingId): void
    {
        $booking = Booking::query()->with('details')->find($bookingId);

        if ($booking === null) {
            session()->flash('error', 'This booking is no longer available. Refresh the list.');

            return;
        }

        if (! $this->canCancelBooking($booking)) {
            session()->flash('error', 'Only booked bookings that have not been checked in can be cancelled.');

            return;
        }

        $this->cancelBookingId = (int) $booking->booking_id;
        $this->cancelBookingReason = '';
        $this->resetValidation();
        $this->showCancelBookingModal = true;
    }

    public function closeBookingCancellation(): void
    {
        $this->showCancelBookingModal = false;
        $this->cancelBookingId = null;
        $this->cancelBookingReason = '';
        $this->resetValidation();
    }

    public function updatedShowCancelBookingModal(bool $open): void
    {
        if (! $open) {
            $this->closeBookingCancellation();
        }
    }

    public function cancelBooking(StaffBookingCancellationService $cancellation): void
    {
        $validated = $this->validate([
            'cancelBookingId' => ['required', 'exists:tbl_booking,booking_id'],
            'cancelBookingReason' => ['required', 'string', 'max:500'],
        ], [
            'cancelBookingId.required' => 'This booking is no longer available. Refresh the list.',
            'cancelBookingId.exists' => 'This booking is no longer available. Refresh the list.',
            'cancelBookingReason.required' => 'Enter a reason for cancelling this booking.',
            'cancelBookingReason.max' => 'The cancellation reason cannot be longer than :max characters.',
        ]);

        try {
            $cancellation->cancel(
                (int) $validated['cancelBookingId'],
                $validated['cancelBookingReason'],
                (int) Auth::id(),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addError('cancelBookingReason', $exception->getMessage());

            return;
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('cancelBookingReason', 'The booking could not be cancelled. Refresh the list and try again.');

            return;
        }

        $this->closeBookingCancellation();
        session()->flash('success', 'Booking cancelled successfully.');
    }

    /** @return array{can_request: bool, reason: ?string, paid: string, used: int, max: int, next_attempt: int, latest: ?RefundRequest} */
    public function refundInfo(Booking $booking): array
    {
        $booking->loadMissing(['payments', 'reservation.payments', 'refundRequests']);

        return app(RefundRequestWorkflowService::class)->eligibility($booking);
    }

    public function refundBooking(): ?Booking
    {
        if ($this->refundBookingId === null) {
            return null;
        }

        return Booking::query()
            ->with(['guest', 'details.facility.facilityType', 'payments', 'reservation.payments', 'refundRequests'])
            ->find($this->refundBookingId);
    }

    public function beginRefundRequest(int $bookingId): void
    {
        $booking = Booking::query()
            ->with(['payments', 'reservation.payments', 'refundRequests'])
            ->find($bookingId);

        if ($booking === null) {
            session()->flash('error', 'This booking is no longer available. Refresh the list.');

            return;
        }

        $eligibility = $this->refundInfo($booking);

        if (! $eligibility['can_request']) {
            session()->flash('error', (string) $eligibility['reason']);

            return;
        }

        $this->refundBookingId = (int) $booking->booking_id;
        $this->refundNote = '';
        $this->resetValidation();
        $this->showRefundModal = true;
    }

    public function closeRefundRequest(): void
    {
        $this->showRefundModal = false;
        $this->refundBookingId = null;
        $this->refundNote = '';
        $this->resetValidation();
    }

    public function updatedShowRefundModal(bool $open): void
    {
        if (! $open) {
            $this->closeRefundRequest();
        }
    }

    public function submitRefundRequest(RefundRequestWorkflowService $refunds): void
    {
        $validated = $this->validate([
            'refundBookingId' => ['required', 'exists:tbl_booking,booking_id'],
            'refundNote' => ['required', 'string', 'max:1000'],
        ], [
            'refundBookingId.required' => 'This booking is no longer available. Refresh the list.',
            'refundBookingId.exists' => 'This booking is no longer available. Refresh the list.',
            'refundNote.required' => 'Add a note to the admin explaining why this refund is needed.',
            'refundNote.max' => 'The note cannot be longer than :max characters.',
        ]);

        try {
            $refunds->submitForBooking(
                (int) $validated['refundBookingId'],
                $validated['refundNote'],
                (int) Auth::id(),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addError('refundRequest', $exception->getMessage());

            return;
        }

        $this->closeRefundRequest();
        session()->flash('success', 'Refund request sent to the admin.');
    }

    public function releaseRequest(): ?RefundRequest
    {
        if ($this->releaseRequestId === null) {
            return null;
        }

        return RefundRequest::query()
            ->with(['booking.guest'])
            ->find($this->releaseRequestId);
    }

    public function refundProofUrl(RefundRequest $refund): ?string
    {
        if (blank($refund->refund_proof_path) || ! \Illuminate\Support\Facades\Route::has('refunds.proof')) {
            return null;
        }

        return route('refunds.proof', $refund);
    }

    public function beginRefundRelease(int $refundRequestId): void
    {
        $request = RefundRequest::query()->find($refundRequestId);

        if ($request === null || ! $request->isWaitingForRelease()) {
            session()->flash('error', 'This refund is no longer waiting to be given to the guest. Refresh the list.');

            return;
        }

        $this->releaseRequestId = (int) $request->refund_request_id;
        $this->releaseAmount = number_format((float) $request->approved_amount, 2, '.', '');
        $this->releaseModeId = (string) (ModeOfPayment::query()
            ->whereRaw('LOWER(mode_of_payment) = ?', ['cash'])
            ->value('mode_of_payment_id') ?? '');
        $this->releaseNote = '';
        $this->releaseContact = '';
        $this->resetValidation();
        $this->showReleaseModal = true;
    }

    public function closeRefundRelease(): void
    {
        $this->showReleaseModal = false;
        $this->releaseRequestId = null;
        $this->releaseAmount = '';
        $this->releaseModeId = '';
        $this->releaseNote = '';
        $this->releaseContact = '';
        $this->resetValidation();
    }

    public function updatedShowReleaseModal(bool $open): void
    {
        if (! $open) {
            $this->closeRefundRelease();
        }
    }

    public function isGcashRelease(): bool
    {
        return strtolower(trim((string) ModeOfPayment::query()
            ->whereKey((int) $this->releaseModeId)
            ->value('mode_of_payment'))) === 'gcash';
    }

    public function updatedReleaseModeId(): void
    {
        if (! $this->isGcashRelease()) {
            $this->releaseNote = '';
            $this->releaseContact = '';
        }

        $this->resetValidation(['releaseNote', 'releaseContact']);
    }

    /**
     * Cash (or any non-GCash mode): the cashier gives the money and it is recorded now.
     * GCash: the refund goes to the admin, who sends it and records the reference and proof.
     */
    public function releaseRefund(RefundRequestWorkflowService $refunds): void
    {
        $isGcash = $this->isGcashRelease();

        $validated = $this->validate([
            'releaseRequestId' => ['required', 'integer'],
            'releaseAmount' => ['required', 'numeric', 'min:0.01'],
            'releaseModeId' => ['required', 'integer', 'exists:tbl_mode_of_payment,mode_of_payment_id'],
            'releaseNote' => [$isGcash ? 'required' : 'nullable', 'string', 'max:1000'],
            'releaseContact' => [$isGcash ? 'required' : 'nullable', 'regex:/^\d{11}$/'],
        ], [
            'releaseRequestId.required' => 'This refund is no longer available. Refresh the list.',
            'releaseAmount.required' => $isGcash ? 'Enter the amount to refund through GCash.' : 'Enter the amount given to the guest.',
            'releaseAmount.numeric' => 'The amount must be a number.',
            'releaseAmount.min' => 'The amount must be greater than zero.',
            'releaseModeId.required' => 'Choose how the refund is given.',
            'releaseModeId.exists' => 'Choose a valid mode of payment.',
            'releaseNote.required' => 'Add a note to the admin about this GCash refund.',
            'releaseNote.max' => 'The note cannot be longer than :max characters.',
            'releaseContact.required' => 'Enter the guest\'s GCash contact number.',
            'releaseContact.regex' => 'The contact number must be 11 digits, numbers only.',
        ]);

        try {
            if ($isGcash) {
                $refunds->sendGcashToAdmin(
                    (int) $validated['releaseRequestId'],
                    (string) $validated['releaseAmount'],
                    $validated['releaseNote'] ?? null,
                    $validated['releaseContact'] ?? null,
                    (int) Auth::id(),
                );
            } else {
                $refunds->release(
                    (int) $validated['releaseRequestId'],
                    (int) $validated['releaseModeId'],
                    (string) $validated['releaseAmount'],
                    null,
                    null,
                    (int) Auth::id(),
                );
            }
        } catch (\InvalidArgumentException $exception) {
            $this->addError('releaseRefund', $exception->getMessage());

            return;
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('releaseRefund', 'The refund could not be recorded. Please refresh and try again.');

            return;
        }

        $this->closeRefundRelease();
        session()->flash(
            'success',
            $isGcash
                ? 'GCash refund sent to the admin. They will send it and record the reference number and proof.'
                : 'Refund recorded. It now reduces revenue.',
        );
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
        eyebrow="Cashier operations"
        title="Booking Management"
        description="Create paid facility bookings, reschedule stays, transfer facilities, extend eligible cottage bookings, and cancel or refund bookings."
    >
        <x-slot:actions>
            <flux:button href="{{ route('cashier.bookings.create') }}" variant="primary" wire:navigate>
                New booking
            </flux:button>
            <flux:button
                :href="route('cashier.dashboard')"
                wire:navigate
                variant="ghost"
            >
                Back to dashboard
            </flux:button>
        </x-slot:actions>
    </x-staff-page-header>

    <x-toast :message="session('success')" fresh />

    {{-- One banner for page-level problems (service errors and stale-row errors), instead of four stacked ones. --}}
    @php
        $pageError = collect([
            'booking',
            'cancelDetailForm.booking_details_id',
            'refundBookingId',
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
            <table class="w-full min-w-[64rem] text-left text-sm">
                <thead class="border-b border-brand-border bg-zinc-50 text-xs uppercase tracking-wide text-brand-text-muted dark:border-zinc-800 dark:bg-zinc-950/60 dark:text-zinc-400">
                    <tr>
                        <th class="w-10 px-3 py-3"><span class="sr-only">Expand</span></th>
                        <th class="px-4 py-3"><button type="button" wire:click="sortBy('b_ref_no')" class="font-semibold hover:text-brand-text dark:hover:text-white">REFERENCE {{ $this->sortIndicator('b_ref_no') }}</button></th>
                        <th class="px-4 py-3"><button type="button" wire:click="sortBy('guest_name')" class="font-semibold hover:text-brand-text dark:hover:text-white">GUEST {{ $this->sortIndicator('guest_name') }}</button></th>
                        <th class="px-4 py-3"><button type="button" wire:click="sortBy('facility_name')" class="font-semibold hover:text-brand-text dark:hover:text-white">FACILITY {{ $this->sortIndicator('facility_name') }}</button></th>
                        <th class="px-4 py-3 font-semibold">SCHEDULE</th>
                        <th class="px-4 py-3"><button type="button" wire:click="sortBy('total_price')" class="font-semibold hover:text-brand-text dark:hover:text-white">TOTAL {{ $this->sortIndicator('total_price') }}</button></th>
                        <th class="px-4 py-3"><button type="button" wire:click="sortBy('amount_due')" class="font-semibold hover:text-brand-text dark:hover:text-white">BALANCE {{ $this->sortIndicator('amount_due') }}</button></th>
                        <th class="px-4 py-3"><button type="button" wire:click="sortBy('booking_status')" class="font-semibold hover:text-brand-text dark:hover:text-white">STATUS {{ $this->sortIndicator('booking_status') }}</button></th>
                        <th class="px-4 py-3 text-right font-semibold">ACTIONS</th>
                    </tr>
                </thead>
                @php
                    $rateTimes = [
                        'DAY' => ['6:00 AM', '6:00 PM'],
                        'NIGHT' => ['4:00 PM', '12:00 AM'],
                        'BOTH' => ['6:00 AM', '12:00 AM'],
                        'OVERNIGHT' => ['2:00 PM', '12:00 PM'],
                    ];
                @endphp
                @forelse ($bookings as $rows)
                    @php
                        $booking = $rows->first();
                        $withFacility = $rows->filter(fn ($row) => $row->booking_details_id);
                        $firstRow = $withFacility->first();
                        $extraFacilities = max($withFacility->count() - 1, 0);
                        $rangeStart = $withFacility->min('check_in_date');
                        $rangeEnd = $withFacility->max('check_out_date');
                    @endphp
                    <tbody
                        wire:key="booking-row-{{ $booking->booking_id }}"
                        x-data="{ open: false }"
                        class="border-b border-zinc-100 text-brand-text dark:border-zinc-800 dark:text-zinc-200"
                    >
                        <tr
                            @click="open = ! open"
                            class="cursor-pointer transition-colors hover:bg-brand-surface-muted/70 dark:hover:bg-zinc-800/60"
                        >
                            <td class="px-3 py-3 text-brand-text-muted dark:text-zinc-400">
                                <flux:icon.chevron-right class="size-4 transition-transform" x-bind:class="open && 'rotate-90'" />
                            </td>
                            <td class="px-4 py-3 font-semibold">{{ $booking->b_ref_no }}</td>
                            <td class="px-4 py-3 font-medium">{{ trim(preg_replace('/\s+/', ' ', $booking->guest_name)) }}</td>
                            <td class="px-4 py-3">
                                @if ($firstRow)
                                    {{ $firstRow->facility_name ?? 'No facility' }}
                                    @if ($extraFacilities > 0)
                                        <span class="ml-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-brand-text-muted dark:bg-zinc-800 dark:text-zinc-300">+{{ $extraFacilities }} more</span>
                                    @endif
                                @else
                                    <span class="text-brand-text-muted dark:text-zinc-400">No facility</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                @if ($rangeStart)
                                    {{ \Carbon\Carbon::parse($rangeStart)->format('M d, Y') }} → {{ \Carbon\Carbon::parse($rangeEnd)->format('M d, Y') }}
                                @endif
                            </td>
                            <td class="px-4 py-3">₱{{ number_format((float) $booking->total_price, 2) }}</td>
                            <td class="px-4 py-3">{{ (string) $booking->booking_status === 'Cancelled' ? '—' : '₱'.number_format((float) $booking->amount_due, 2) }}</td>
                            <td class="px-4 py-3">
                                <x-status-badge :status="(string) $booking->booking_status" />
                            </td>
                            <td class="px-4 py-3" @click.stop>
                                <div class="flex justify-end">
                                    @if (Route::has('cashier.bookings.show'))
                                        <x-table-actions label="Actions for booking {{ $booking->b_ref_no }}">
                                            <flux:menu.item
                                                icon="eye"
                                                href="{{ route('cashier.bookings.show', $booking->booking_id) }}"
                                                wire:navigate
                                            >
                                                View booking
                                            </flux:menu.item>
                                            @php $cancelModel = $bookingModels->get($booking->booking_id); @endphp
                                            @if ($cancelModel && $this->canCancelBooking($cancelModel))
                                                <flux:menu.item
                                                    icon="x-circle"
                                                    variant="danger"
                                                    wire:click="beginBookingCancellation({{ $booking->booking_id }})"
                                                >
                                                    Cancel booking
                                                </flux:menu.item>
                                            @endif
                                        </x-table-actions>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        <tr x-show="open" x-transition.opacity style="display: none" class="bg-zinc-50/60 dark:bg-zinc-950/40">
                            <td colspan="9" class="px-6 py-4">
                                <div class="mb-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                    <div>
                                        <div class="text-xs uppercase tracking-wide text-brand-text-muted dark:text-zinc-400">Booked on</div>
                                        <div>{{ \Carbon\Carbon::parse($booking->booking_date)->format('M d, Y') }}</div>
                                    </div>
                                    <div>
                                        <div class="text-xs uppercase tracking-wide text-brand-text-muted dark:text-zinc-400">Contact</div>
                                        <div>{{ $booking->contact_no ?: '—' }}</div>
                                    </div>
                                    <div>
                                        <div class="text-xs uppercase tracking-wide text-brand-text-muted dark:text-zinc-400">Email</div>
                                        <div class="break-all">{{ $booking->email ?: '—' }}</div>
                                    </div>
                                    <div>
                                        <div class="text-xs uppercase tracking-wide text-brand-text-muted dark:text-zinc-400">Extra guests</div>
                                        <div>{{ (int) $booking->no_of_extra_guests }}</div>
                                    </div>
                                </div>

                                @php $model = $bookingModels->get($booking->booking_id); @endphp
                                @if ($model && (string) $model->status === 'Cancelled')
                                    @php $refund = $this->refundInfo($model); @endphp
                                    <div class="mt-4 space-y-3 rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                                        <div>
                                            <div class="text-xs uppercase tracking-wide text-brand-text-muted dark:text-zinc-400">Cancellation</div>
                                            <div class="text-brand-text dark:text-white">
                                                {{ $model->cancelled_at?->format('M d, Y') }} · {{ $model->cancellation_reason ?: 'No reason recorded' }}
                                            </div>
                                        </div>

                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <div class="text-xs uppercase tracking-wide text-brand-text-muted dark:text-zinc-400">Refund</div>
                                                <div class="text-brand-text dark:text-white">
                                                    Verified payments ₱{{ number_format((float) $refund['paid'], 2) }} · {{ $refund['used'] }} of {{ $refund['max'] }} requests used
                                                </div>
                                            </div>

                                            @if ($refund['can_request'])
                                                <flux:button size="sm" variant="primary" icon="arrow-uturn-left" type="button" wire:click="beginRefundRequest({{ $model->booking_id }})">
                                                    Request refund
                                                </flux:button>
                                            @else
                                                <span class="text-xs text-brand-text-muted dark:text-zinc-400">{{ $refund['reason'] }}</span>
                                            @endif
                                        </div>

                                        @foreach ($model->refundRequests as $request)
                                            <div wire:key="refund-{{ $request->refund_request_id }}" class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-900/60">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="font-medium text-brand-text dark:text-white">Attempt {{ $request->attempt_no }}</span>
                                                    <flux:badge size="sm" color="{{ \App\Support\StatusColor::for((string) $request->status) }}">{{ $request->status }}</flux:badge>
                                                    <span class="text-xs text-brand-text-muted dark:text-zinc-400">{{ $request->created_at?->format('M d, Y h:i A') }}</span>
                                                </div>
                                                <div class="mt-1 text-brand-text dark:text-zinc-200">
                                                    Requested ₱{{ number_format((float) $request->requested_amount, 2) }}
                                                    @if ($request->status === 'Approved')
                                                        · <span class="font-medium">Admin approved ₱{{ number_format((float) $request->approved_amount, 2) }}.</span>
                                                    @endif
                                                </div>
                                                @if ($request->isWaitingForRelease())
                                                    <div class="mt-2 flex flex-wrap items-center gap-3">
                                                        <span class="text-xs text-brand-text-muted dark:text-zinc-400">Give the guest the money, then record how you paid it.</span>
                                                        <flux:button size="sm" variant="primary" icon="banknotes" type="button" wire:click="beginRefundRelease({{ $request->refund_request_id }})">
                                                            Give refund to guest
                                                        </flux:button>
                                                    </div>
                                                @elseif ($request->isAwaitingAdminGcash())
                                                    <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100">
                                                        <span class="font-medium">Sent to admin for GCash</span>
                                                        · ₱{{ number_format((float) $request->gcash_handoff_amount, 2) }}
                                                        · {{ $request->gcash_handoff_at?->format('M d, Y h:i A') }}
                                                        @if ($request->gcashHandoffBy)
                                                            by {{ $request->gcashHandoffBy->full_name }}
                                                        @endif
                                                        <div class="text-xs">Waiting for the admin to send the money and add the reference number and proof.</div>
                                                        @if ($request->gcash_handoff_contact_number)
                                                            <div class="mt-1 text-xs">GCash number: {{ $request->gcash_handoff_contact_number }}</div>
                                                        @endif
                                                        @if ($request->gcash_handoff_note)
                                                            <div class="mt-1 text-xs">Your note: {{ $request->gcash_handoff_note }}</div>
                                                        @endif
                                                    </div>
                                                @elseif ($request->isGivenToGuest())
                                                    @php $proofLink = $this->refundProofUrl($request); @endphp
                                                    <div class="mt-1 text-sm text-brand-text dark:text-zinc-200">
                                                        <span class="font-medium">Refunded ₱{{ number_format((float) $request->refunded_amount, 2) }}</span>
                                                        via {{ $request->refundModeOfPayment?->mode_of_payment ?? 'Unknown' }}
                                                        on {{ $request->refunded_at?->format('M d, Y h:i A') }}
                                                        @if ($request->refundedBy)
                                                            by {{ $request->refundedBy->full_name }}
                                                        @endif
                                                        @if ($request->refund_reference_number)
                                                            · Ref {{ $request->refund_reference_number }}
                                                        @endif
                                                        @if ($proofLink)
                                                            · <a href="{{ $proofLink }}" target="_blank" rel="noopener" class="font-medium underline">View proof</a>
                                                        @endif
                                                    </div>
                                                    @if ($proofLink)
                                                        <a href="{{ $proofLink }}" target="_blank" rel="noopener" class="mt-2 block w-fit">
                                                            <img src="{{ $proofLink }}" alt="GCash refund proof" loading="lazy" class="max-h-40 rounded-lg border border-zinc-200 object-contain dark:border-zinc-700" />
                                                        </a>
                                                    @endif
                                                    @if ($request->gcash_handoff_note)
                                                        <div class="mt-1 text-xs text-brand-text-muted dark:text-zinc-400">Your note to the admin: {{ $request->gcash_handoff_note }}</div>
                                                    @endif
                                                @endif
                                                @if ($request->request_note)
                                                    <div class="mt-1 text-xs text-brand-text-muted dark:text-zinc-400">Your note: {{ $request->request_note }}</div>
                                                @endif
                                                @if ($request->admin_note)
                                                    <div class="mt-1 text-xs text-brand-text-muted dark:text-zinc-400">
                                                        Admin{{ $request->reviewedBy ? ' ('.$request->reviewedBy->full_name.')' : '' }}: {{ $request->admin_note }}
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                <div class="space-y-3">
                                    @foreach ($withFacility as $row)
                                        @php $times = $rateTimes[strtoupper((string) $row->rate_code)] ?? null; @endphp
                                        <div wire:key="booking-detail-{{ $row->booking_details_id }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                            <div class="flex flex-col gap-2 xl:flex-row xl:items-start xl:justify-between">
                                                <div>
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <span class="font-medium">{{ $row->facility_name ?? 'No facility' }}</span>
                                                        <x-status-badge :status="(string) $row->status" />
                                                    </div>
                                                    <div class="mt-1 text-xs text-brand-text-muted dark:text-zinc-400">
                                                        {{ $row->facility_type }}
                                                        · {{ $row->rate_type }}@if ($times) ({{ $times[0] }} – {{ $times[1] }})@endif
                                                        · {{ \Carbon\Carbon::parse($row->check_in_date)->format('M d, Y') }} → {{ \Carbon\Carbon::parse($row->check_out_date)->format('M d, Y') }}
                                                    </div>
                                                </div>
                                                <div @click.stop>
                                                    <x-table-actions label="Actions for {{ $booking->b_ref_no }} facility {{ $row->booking_details_id }}">
                                                        <flux:menu.item icon="calendar-days" wire:click="openReschedule({{ $row->booking_details_id }})">Reschedule</flux:menu.item>
                                                        <flux:menu.item icon="arrows-right-left" wire:click="openTransfer({{ $row->booking_details_id }})">Transfer</flux:menu.item>
                                                        <flux:menu.item icon="clock" wire:click="openExtend({{ $row->booking_details_id }})">Extend</flux:menu.item>
                                                        <flux:menu.item icon="user-group" wire:click="openOccupants({{ $row->booking_details_id }})">Occupants</flux:menu.item>
                                                    </x-table-actions>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    </tbody>
                @empty
                    <tbody>
                        <tr>
                            <td colspan="9" class="px-5 py-6">
                                <x-dashboard-empty-state
                                    title="No booking records found"
                                    description="No booking facility records match the current search and status filters."
                                    class="border-0 bg-transparent py-6 shadow-none dark:bg-transparent"
                                />
                            </td>
                        </tr>
                    </tbody>
                @endforelse
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
                    <flux:input type="date" wire:model.live="rescheduleForm.new_check_in_date" :invalid="$errors->has('rescheduleForm.new_check_in_date')" />
                    @if ($msg = $this->visibleError('rescheduleForm.new_check_in_date'))
                        <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                    @endif
                </flux:field>
                <flux:field class="relative">
                    <flux:label>New check-out date <span class="text-red-500">*</span></flux:label>
                    <flux:input type="date" readonly wire:model="rescheduleForm.new_check_out_date" :invalid="$errors->has('rescheduleForm.new_check_out_date')" />
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Set automatically: @if ((int) ($rescheduleForm['original_days'] ?? 0) === 0) the same day as check-in @else {{ (int) $rescheduleForm['original_days'] }} {{ (int) $rescheduleForm['original_days'] === 1 ? 'day' : 'days' }} after check-in @endif, the same length as the original stay.
                    </p>
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
                            <flux:label>First name <span class="text-red-500">*</span></flux:label>
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

    {{-- Cancel a whole booking --}}
    <flux:modal wire:model="showCancelBookingModal" class="w-full md:w-[30rem]">
        <form wire:submit="cancelBooking" novalidate class="space-y-5">
            <div class="pe-8">
                <flux:heading size="lg">Cancel booking</flux:heading>
                <flux:text class="mt-1">
                    Every facility on this booking is cancelled and released for other guests. Payments stay on record; request a refund afterwards if one is due.
                </flux:text>
            </div>

            @error('cancelBookingId')
                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200">{{ $message }}</div>
            @enderror

            <flux:field class="relative">
                <flux:label>Cancellation reason <span class="text-red-500">*</span></flux:label>
                <flux:input wire:model="cancelBookingReason" :invalid="$errors->has('cancelBookingReason')" />
                @if ($msg = $this->visibleError('cancelBookingReason'))
                    <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                @endif
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="closeBookingCancellation">Close</flux:button>
                <flux:button type="submit" variant="danger">Confirm cancellation</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Request a refund for a cancelled booking --}}
    <flux:modal wire:model="showRefundModal" class="w-full max-h-[90vh] overflow-y-auto md:w-[36rem]">
        @if ($refundBooking)
            @php $refundInfo = $this->refundInfo($refundBooking); @endphp
            <form wire:submit="submitRefundRequest" novalidate class="space-y-5">
                <div class="pe-8">
                    <flux:heading size="lg">Request a refund</flux:heading>
                    <flux:text class="mt-1">
                        The admin receives this together with the booking details and the cancellation reason.
                        Attempt {{ $refundInfo['next_attempt'] }} of {{ $refundInfo['max'] }}.
                    </flux:text>
                </div>

                @error('refundRequest')
                    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200">{{ $message }}</div>
                @enderror
                @error('refundBookingId')
                    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200">{{ $message }}</div>
                @enderror

                <div class="space-y-2 rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Booking</span><span class="font-medium">{{ $refundBooking->b_ref_no }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Guest</span><span class="text-right font-medium">{{ $this->guestName($refundBooking->guest) }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Contact</span><span>{{ $refundBooking->guest->contact_no ?: '—' }}</span></div>
                    <div class="flex justify-between gap-4">
                        <span class="text-zinc-500">Facilities</span>
                        <span class="text-right">
                            @foreach ($refundBooking->details as $detail)
                                <span class="block">{{ $detail->facility?->facility_name ?? 'Unassigned facility' }} · {{ \Illuminate\Support\Carbon::parse($detail->check_in_date)->format('M d, Y') }} → {{ \Illuminate\Support\Carbon::parse($detail->check_out_date)->format('M d, Y') }}</span>
                            @endforeach
                        </span>
                    </div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Total price</span><span>₱{{ number_format((float) $refundBooking->total_price, 2) }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Verified payments</span><span class="font-medium">₱{{ number_format((float) $refundInfo['paid'], 2) }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Cancelled on</span><span>{{ $refundBooking->cancelled_at?->format('M d, Y') }}</span></div>
                    <div>
                        <div class="text-zinc-500">Cancellation reason</div>
                        <div class="mt-0.5">{{ $refundBooking->cancellation_reason ?: 'No reason recorded' }}</div>
                    </div>
                </div>

                <flux:text class="text-sm">
                    The request covers what the guest paid (₱{{ number_format((float) $refundInfo['paid'], 2) }}). The admin decides how much to approve.
                </flux:text>

                <flux:field class="relative">
                    <flux:label>Note to the admin <span class="text-red-500">*</span></flux:label>
                    <flux:textarea wire:model="refundNote" rows="3" placeholder="Explain this case, for example why the guest should be refunded." :invalid="$errors->has('refundNote')" />
                    @error('refundNote')
                        <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </flux:field>

                <details class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                    <summary class="cursor-pointer font-medium">Booking, Cancellation, and Refund Policy</summary>
                    <x-booking-policy-content class="mt-3 max-h-64 overflow-y-auto pr-1" />
                </details>

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="closeRefundRequest">Close</flux:button>
                    <flux:button type="submit" variant="primary">Send to admin</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
    {{-- Give an approved refund back to the guest --}}
    <flux:modal wire:model="showReleaseModal" class="w-full max-h-[90vh] overflow-y-auto md:w-[34rem]">
        @if ($releaseRequest)
            <form wire:submit="releaseRefund" novalidate class="space-y-5">
                <div class="pe-8">
                    <flux:heading size="lg">Give refund to guest</flux:heading>
                    <flux:text class="mt-1">
                        @if ($this->isGcashRelease())
                            GCash refunds are sent by the admin. Add a note and send it back; the admin will send the money and add the reference number and proof.
                        @else
                            Record how the approved refund is given back. It reduces revenue from today.
                        @endif
                    </flux:text>
                </div>

                @error('releaseRefund')
                    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200">{{ $message }}</div>
                @enderror
                @error('releaseRequestId')
                    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200">{{ $message }}</div>
                @enderror

                <div class="space-y-2 rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Booking</span><span class="font-medium">{{ $releaseRequest->referenceNumber() }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Guest</span><span class="text-right font-medium">{{ $this->guestName($releaseRequest->subject()?->guest) }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Approved by admin</span><span class="font-medium">₱{{ number_format((float) $releaseRequest->approved_amount, 2) }}</span></div>
                </div>

                <flux:field class="relative">
                    <flux:label>{{ $this->isGcashRelease() ? 'Amount to refund through GCash' : 'Amount given' }} <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model="releaseAmount" type="number" step="0.01" min="0.01" max="{{ $releaseRequest->approved_amount }}" :invalid="$errors->has('releaseAmount')" />
                    <flux:text class="text-xs">Up to ₱{{ number_format((float) $releaseRequest->approved_amount, 2) }}.</flux:text>
                    @if ($msg = $this->visibleError('releaseAmount'))
                        <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                    @endif
                </flux:field>

                <flux:field class="relative">
                    <flux:label>Mode of payment <span class="text-red-500">*</span></flux:label>
                    <flux:select wire:model.live="releaseModeId" :invalid="$errors->has('releaseModeId')">
                        @foreach ($refundModes as $mode)
                            <option value="{{ $mode->mode_of_payment_id }}">{{ $mode->mode_of_payment }}</option>
                        @endforeach
                    </flux:select>
                    @if ($msg = $this->visibleError('releaseModeId'))
                        <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                    @endif
                </flux:field>

                @if ($this->isGcashRelease())
                    <flux:field class="relative">
                        <flux:label>GCash contact number <span class="text-red-500">*</span></flux:label>
                        <flux:input
                            wire:model="releaseContact"
                            type="text"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            maxlength="11"
                            placeholder="09XXXXXXXXX"
                            x-on:input="$el.value = $el.value.replace(/\D/g, '')"
                            :invalid="$errors->has('releaseContact')"
                        />
                        <flux:text class="text-xs">The guest's GCash number the admin will send the refund to. Numbers only.</flux:text>
                        @error('releaseContact')
                            <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </flux:field>

                    <flux:field class="relative">
                        <flux:label>Note to admin <span class="text-red-500">*</span></flux:label>
                        <flux:textarea wire:model="releaseNote" rows="3" placeholder="Explain this GCash refund to the admin." :invalid="$errors->has('releaseNote')" />
                        @error('releaseNote')
                            <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </flux:field>
                @endif

                <details class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                    <summary class="cursor-pointer font-medium">Booking, Cancellation, and Refund Policy</summary>
                    <x-booking-policy-content class="mt-3 max-h-64 overflow-y-auto pr-1" />
                </details>

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="closeRefundRelease">Close</flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="releaseRefund">{{ $this->isGcashRelease() ? 'Send to admin' : 'Record refund' }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>