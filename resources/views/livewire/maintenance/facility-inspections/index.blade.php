<?php

use App\Models\BookingDetail;
use App\Models\FacilityInspection;
use App\Models\FacilityInspectionRequest;
use App\Models\Fine;
use App\Models\GuestFine;
use App\Services\CheckOutInspectionRequestService;
use App\Services\FacilityInspectionWorkflowService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Facility Inspections - Olaer Spring Resort')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'active')]
    public string $statusFilter = 'active';

    #[Url(as: 'assignment', except: '')]
    public string $assignmentFilter = '';

    #[Url(as: 'departure', except: 'all')]
    public string $dateFilter = 'all';

    #[Url(as: 'sort', except: 'requested_at')]
    public string $sortField = 'requested_at';

    #[Url(as: 'direction', except: 'asc')]
    public string $sortDirection = 'asc';

    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    public ?int $selectedRequestId = null;
    public ?int $selectedBookingDetailsId = null;
    public ?int $selectedBookingId = null;
    public string $selectedLabel = '';
    public string $selectedChecklistKey = '';
    public ?int $fineId = null;
    public int $fineQuantity = 1;
    public string $remarks = '';

    public function mount(): void
    {
        $requestId = request()->integer('request');

        if ($requestId > 0) {
            $this->selectRequest($requestId);
        }
    }

    public function with(): array
    {
        return [
            'inspectionRequests' => $this->inspectionRequests(),
            'fines' => $this->fines(),
            'selectedChecklistItems' => $this->selectedChecklistItems(),
            'selectedGuestFines' => $this->selectedGuestFines(),
            'selectedInspection' => $this->selectedInspection(),
            'selectedInspectionRequest' =>
                $this->selectedInspectionRequest(),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->cancelSelection();
        $this->resetPage();
    }

    public function updatedAssignmentFilter(): void
    {
        $this->cancelSelection();
        $this->resetPage();
    }

    public function updatedDateFilter(): void
    {
        $this->cancelSelection();
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 25, 50, 100], true)) {
            $this->perPage = 10;
        }

        $this->resetPage();
    }

    public function updatedSelectedChecklistKey(): void
    {
        $this->fineId = null;
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'active';
        $this->assignmentFilter = '';
        $this->dateFilter = 'all';
        $this->sortField = 'requested_at';
        $this->sortDirection = 'asc';
        $this->perPage = 10;

        $this->cancelSelection();
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        $allowed = [
            'facility_inspection_request_id',
            'requested_at',
            'b_ref_no',
            'guest_name',
            'facility_name',
            'check_out_date',
            'request_status',
            'assigned_to',
        ];

        if (! in_array($field, $allowed, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection =
                $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = in_array(
                $field,
                [
                    'facility_inspection_request_id',
                    'requested_at',
                ],
                true,
            )
                ? 'desc'
                : 'asc';
        }

        $this->resetPage();
    }

    public function selectRequest(int $requestId): void
    {
        try {
            $request = FacilityInspectionRequest::query()
                ->with([
                    'booking.guest',
                    'bookingDetail',
                    'facility',
                    'assignedTo',
                    'inspection',
                ])
                ->findOrFail($requestId);

            if ($request->status === 'Cancelled') {
                throw ValidationException::withMessages([
                    'inspection' =>
                        'Cancelled inspection requests cannot be opened.',
                ]);
            }

            if (
                $request->assigned_to_user_id !== null
                && (int) $request->assigned_to_user_id !== (int) Auth::id()
                && $request->status !== 'Completed'
            ) {
                throw ValidationException::withMessages([
                    'inspection' =>
                        'This inspection request is assigned to another maintenance staff member.',
                ]);
            }

            if (
                $request->status === 'Pending'
                || (
                    $request->status === 'In Progress'
                    && $request->assigned_to_user_id === null
                )
            ) {
                $request = app(
                    CheckOutInspectionRequestService::class,
                )->acceptRequest(
                    $requestId,
                    (int) Auth::id(),
                );
            }

            $detail = BookingDetail::query()
                ->with([
                    'booking.guest',
                    'facility',
                ])
                ->findOrFail(
                    (int) $request->booking_details_id,
                );

            if (
                $request->status !== 'Completed'
                && $detail->status !== 'Checked-in'
            ) {
                throw ValidationException::withMessages([
                    'inspection' =>
                        'Only checked-in facility details can be inspected.',
                ]);
            }

            $guest = $detail->booking?->guest;
            $facilityName =
                $detail->facility?->facility_name
                ?? 'No facility';

            $this->selectedRequestId =
                (int) $request->facility_inspection_request_id;
            $this->selectedBookingDetailsId =
                (int) $detail->booking_details_id;
            $this->selectedBookingId =
                (int) $detail->booking_id;
            $this->selectedLabel =
                ($detail->booking?->b_ref_no ?? 'Unknown booking')
                .' — '
                .trim(
                    ($guest?->first_name ?? '')
                    .' '
                    .($guest?->last_name ?? '')
                )
                .' — '
                .$facilityName;

            $this->selectedChecklistKey = '';
            $this->fineId = null;
            $this->fineQuantity = 1;
            $this->remarks = (string) (
                $request->inspection?->remarks
                ?? ''
            );

            $this->resetValidation();
        } catch (\Throwable $exception) {
            $this->addError(
                'inspection',
                $exception->getMessage(),
            );
        }
    }

    public function cancelSelection(): void
    {
        $this->selectedRequestId = null;
        $this->selectedBookingDetailsId = null;
        $this->selectedBookingId = null;
        $this->selectedLabel = '';
        $this->selectedChecklistKey = '';
        $this->fineId = null;
        $this->fineQuantity = 1;
        $this->remarks = '';

        $this->resetValidation();
    }

    public function markNoDamage(
        FacilityInspectionWorkflowService $inspectionWorkflow,
    ): void {
        $validated = $this->validate([
            'selectedBookingDetailsId' => [
                'required',
                'integer',
                'exists:tbl_booking_details,booking_details_id',
            ],
            'remarks' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        try {
            $this->guardCanMarkNoDamage();

            $inspectionWorkflow->markNoDamage(
                (int) $validated['selectedBookingDetailsId'],
                (int) Auth::id(),
                trim((string) $validated['remarks']) !== ''
                    ? trim((string) $validated['remarks'])
                    : null,
            );

            session()->flash(
                'success',
                'Inspection cleared. The cashier may proceed when the booking balance is zero.',
            );
        } catch (\Throwable $exception) {
            $this->addError(
                'inspection',
                $exception->getMessage(),
            );
        }
    }

    public function addFine(
        FacilityInspectionWorkflowService $inspectionWorkflow,
    ): void {
        $validated = $this->validate([
            'selectedBookingDetailsId' => [
                'required',
                'integer',
                'exists:tbl_booking_details,booking_details_id',
            ],
            'selectedChecklistKey' => [
                'nullable',
                'string',
                'max:80',
            ],
            'fineId' => [
                'required',
                'integer',
                'exists:tbl_fine,fine_id',
            ],
            'fineQuantity' => [
                'required',
                'integer',
                'min:1',
                'max:100',
            ],
            'remarks' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        try {
            $this->guardCanRecordFine();

            [$source, $sourceId] = $this->parseChecklistKey(
                (string) (
                    $validated['selectedChecklistKey']
                    ?? ''
                ),
            );

            $inspectionWorkflow->recordFine(
                (int) $validated['selectedBookingDetailsId'],
                (int) $validated['fineId'],
                (int) $validated['fineQuantity'],
                (int) Auth::id(),
                trim((string) $validated['remarks']) !== ''
                    ? trim((string) $validated['remarks'])
                    : null,
                $source,
                $sourceId,
            );

            $this->fineId = null;
            $this->fineQuantity = 1;

            session()->flash(
                'success',
                'Fine recorded and added to cashier billing.',
            );
        } catch (\Throwable $exception) {
            $this->addError(
                'inspection',
                $exception->getMessage(),
            );
        }
    }

    public function canMarkNoDamage(): bool
    {
        $request = $this->selectedInspectionRequest();

        return $request !== null
            && $request->status === 'In Progress'
            && (int) $request->assigned_to_user_id
                === (int) Auth::id();
    }

    public function canRecordFine(): bool
    {
        $request = $this->selectedInspectionRequest();
        $inspection = $this->selectedInspection();

        if (
            $request === null
            || (int) $request->assigned_to_user_id
                !== (int) Auth::id()
        ) {
            return false;
        }

        if ($request->status === 'In Progress') {
            return true;
        }

        return $request->status === 'Completed'
            && $inspection?->inspection_status === 'Damage Found';
    }

    public function requestStatusColor(string $status): string
    {
        return match ($status) {
            'Pending' => 'amber',
            'In Progress' => 'purple',
            'Completed' => 'green',
            'Cancelled' => 'red',
            default => 'zinc',
        };
    }

    public function inspectionStatusColor(?string $status): string
    {
        return match ($status) {
            'Cleared' => 'green',
            'Damage Found' => 'red',
            default => 'zinc',
        };
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

    public function staffName(
        mixed $staff,
        string $fallback = 'Unassigned',
    ): string {
        if ($staff === null) {
            return $fallback;
        }

        return $staff->full_name
            ?? trim(implode(' ', array_filter([
                $staff->first_name,
                $staff->middle_name,
                $staff->last_name,
            ])))
            ?: ($staff->username ?? $fallback);
    }

    public function formatFineLabel(?Fine $fine): string
    {
        if ($fine === null) {
            return 'Unknown fine';
        }

        $charge =
            '₱'.number_format(
                (float) $fine->fine_charge,
                2,
            );

        if (
            in_array(
                (string) $fine->fine_type,
                ['Amenity', 'Amenity Fine'],
                true,
            )
        ) {
            $amenityLabel =
                $fine->amenity?->amenityName?->amenity_name
                ?? 'Amenity';

            $damageLabel =
                $fine->damageType?->damage_type
                ?? 'Damage';

            return $amenityLabel
                .' / '
                .$damageLabel
                .' — '
                .$charge;
        }

        return (
            $fine->situational_fine
            ?? 'Situational fine'
        )
            .' — '
            .$charge;
    }

    private function guardCanMarkNoDamage(): void
    {
        if (! $this->canMarkNoDamage()) {
            throw ValidationException::withMessages([
                'inspection' =>
                    'Only the assigned maintenance staff can clear an active inspection.',
            ]);
        }
    }

    private function guardCanRecordFine(): void
    {
        if (! $this->canRecordFine()) {
            throw ValidationException::withMessages([
                'inspection' =>
                    'Only the assigned maintenance staff can record fines for this inspection.',
            ]);
        }
    }

    private function parseChecklistKey(
        string $key,
    ): array {
        if ($key === '') {
            return [null, null];
        }

        $parts = explode(':', $key, 2);

        if (
            count($parts) !== 2
            || ! in_array(
                $parts[0],
                [
                    'facility_amenity',
                    'amenity_request',
                ],
                true,
            )
            || (int) $parts[1] < 1
        ) {
            throw new \InvalidArgumentException(
                'Invalid checklist item selection.',
            );
        }

        return [
            $parts[0],
            (int) $parts[1],
        ];
    }

    private function inspectionRequests(): LengthAwarePaginator
    {
        $allowedSorts = [
            'facility_inspection_request_id',
            'requested_at',
            'b_ref_no',
            'guest_name',
            'facility_name',
            'check_out_date',
            'request_status',
            'assigned_to',
        ];

        $sortField = in_array(
            $this->sortField,
            $allowedSorts,
            true,
        )
            ? $this->sortField
            : 'requested_at';

        $direction =
            $this->sortDirection === 'desc'
                ? 'desc'
                : 'asc';

        $perPage = in_array(
            $this->perPage,
            [10, 25, 50, 100],
            true,
        )
            ? $this->perPage
            : 10;

        $query = FacilityInspectionRequest::query()
            ->select(
                'tbl_facility_inspection_request.*',
            )
            ->join(
                'tbl_booking',
                'tbl_booking.booking_id',
                '=',
                'tbl_facility_inspection_request.booking_id',
            )
            ->join(
                'tbl_booking_details',
                'tbl_booking_details.booking_details_id',
                '=',
                'tbl_facility_inspection_request.booking_details_id',
            )
            ->join(
                'tbl_guest',
                'tbl_guest.guest_id',
                '=',
                'tbl_booking.guest_id',
            )
            ->leftJoin(
                'tbl_facility',
                'tbl_facility.facility_id',
                '=',
                'tbl_facility_inspection_request.facility_id',
            )
            ->leftJoin(
                'tbl_user as requested_user',
                'requested_user.user_id',
                '=',
                'tbl_facility_inspection_request.requested_by_user_id',
            )
            ->leftJoin(
                'tbl_user as assigned_user',
                'assigned_user.user_id',
                '=',
                'tbl_facility_inspection_request.assigned_to_user_id',
            )
            ->with([
                'booking.guest',
                'bookingDetail',
                'facility.facilityType',
                'requestedBy',
                'assignedTo',
                'inspection.inspectedBy',
            ]);

        if ($this->statusFilter === 'pending') {
            $query->where(
                'tbl_facility_inspection_request.status',
                'Pending',
            );
        } elseif ($this->statusFilter === 'in_progress') {
            $query->where(
                'tbl_facility_inspection_request.status',
                'In Progress',
            );
        } elseif ($this->statusFilter === 'completed') {
            $query->where(
                'tbl_facility_inspection_request.status',
                'Completed',
            );
        } else {
            $query->whereIn(
                'tbl_facility_inspection_request.status',
                [
                    'Pending',
                    'In Progress',
                ],
            );
        }

        if ($this->statusFilter !== 'completed') {
            $query->where(
                'tbl_booking_details.status',
                'Checked-in',
            );
        }

        if ($this->assignmentFilter === 'unassigned') {
            $query
                ->where(
                    'tbl_facility_inspection_request.status',
                    'Pending',
                )
                ->whereNull(
                    'tbl_facility_inspection_request.assigned_to_user_id',
                );
        } elseif ($this->assignmentFilter === 'mine') {
            $query->where(
                'tbl_facility_inspection_request.assigned_to_user_id',
                Auth::id(),
            );
        } elseif ($this->assignmentFilter === 'other') {
            $query
                ->whereNotNull(
                    'tbl_facility_inspection_request.assigned_to_user_id',
                )
                ->where(
                    'tbl_facility_inspection_request.assigned_to_user_id',
                    '!=',
                    Auth::id(),
                );
        }

        if ($this->dateFilter === 'today') {
            $query->whereDate(
                'tbl_booking_details.check_out_date',
                now()->toDateString(),
            );
        } elseif ($this->dateFilter === 'upcoming') {
            $query->whereDate(
                'tbl_booking_details.check_out_date',
                '>',
                now()->toDateString(),
            );
        } elseif ($this->dateFilter === 'overdue') {
            $query->whereDate(
                'tbl_booking_details.check_out_date',
                '<',
                now()->toDateString(),
            );
        }

        $searchText = trim($this->search);

        if ($searchText !== '') {
            $needle = '%'.$searchText.'%';
            $numeric = ctype_digit($searchText)
                ? (int) $searchText
                : null;

            $query->where(
                function ($query) use (
                    $needle,
                    $numeric,
                ): void {
                    $query
                        ->where(
                            'tbl_booking.b_ref_no',
                            'like',
                            $needle,
                        )
                        ->orWhere(
                            'tbl_guest.first_name',
                            'like',
                            $needle,
                        )
                        ->orWhere(
                            'tbl_guest.middle_name',
                            'like',
                            $needle,
                        )
                        ->orWhere(
                            'tbl_guest.last_name',
                            'like',
                            $needle,
                        )
                        ->orWhere(
                            'tbl_guest.contact_no',
                            'like',
                            $needle,
                        )
                        ->orWhere(
                            'tbl_guest.email',
                            'like',
                            $needle,
                        )
                        ->orWhere(
                            'tbl_facility.facility_name',
                            'like',
                            $needle,
                        )
                        ->orWhere(
                            'requested_user.first_name',
                            'like',
                            $needle,
                        )
                        ->orWhere(
                            'requested_user.last_name',
                            'like',
                            $needle,
                        )
                        ->orWhere(
                            'assigned_user.first_name',
                            'like',
                            $needle,
                        )
                        ->orWhere(
                            'assigned_user.last_name',
                            'like',
                            $needle,
                        );

                    if ($numeric !== null) {
                        $query->orWhere(
                            'tbl_facility_inspection_request.facility_inspection_request_id',
                            $numeric,
                        );
                    }
                },
            );
        }

        match ($sortField) {
            'facility_inspection_request_id' =>
                $query->orderBy(
                    'tbl_facility_inspection_request.facility_inspection_request_id',
                    $direction,
                ),
            'b_ref_no' =>
                $query->orderBy(
                    'tbl_booking.b_ref_no',
                    $direction,
                ),
            'guest_name' =>
                $query
                    ->orderBy(
                        'tbl_guest.last_name',
                        $direction,
                    )
                    ->orderBy(
                        'tbl_guest.first_name',
                        $direction,
                    ),
            'facility_name' =>
                $query->orderBy(
                    'tbl_facility.facility_name',
                    $direction,
                ),
            'check_out_date' =>
                $query->orderBy(
                    'tbl_booking_details.check_out_date',
                    $direction,
                ),
            'request_status' =>
                $query->orderBy(
                    'tbl_facility_inspection_request.status',
                    $direction,
                ),
            'assigned_to' =>
                $query
                    ->orderBy(
                        'assigned_user.last_name',
                        $direction,
                    )
                    ->orderBy(
                        'assigned_user.first_name',
                        $direction,
                    ),
            default =>
                $query->orderBy(
                    'tbl_facility_inspection_request.requested_at',
                    $direction,
                ),
        };

        return $query
            ->orderBy(
                'tbl_facility_inspection_request.facility_inspection_request_id',
            )
            ->paginate($perPage);
    }

    private function fines(): Collection
    {
        $query = Fine::query()
            ->with([
                'amenity.amenityName',
                'damageType',
            ]);

        if (
            $this->selectedChecklistKey !== ''
            && $this->selectedBookingDetailsId !== null
        ) {
            $selectedItem = collect(
                $this->selectedChecklistItems(),
            )->firstWhere(
                'key',
                $this->selectedChecklistKey,
            );

            if ($selectedItem !== null) {
                $query
                    ->whereIn(
                        'fine_type',
                        [
                            'Amenity',
                            'Amenity Fine',
                        ],
                    )
                    ->where(
                        'amenity_id',
                        $selectedItem['amenity_id'],
                    );
            }
        }

        return $query
            ->orderBy('fine_type')
            ->orderBy('fine_id')
            ->get();
    }

    private function selectedChecklistItems(): array
    {
        if ($this->selectedBookingDetailsId === null) {
            return [];
        }

        $items = app(
            FacilityInspectionWorkflowService::class,
        )->checklistFor(
            $this->selectedBookingDetailsId,
        );

        return array_map(
            function (array $item): array {
                $item['fine_count'] = Fine::query()
                    ->whereIn(
                        'fine_type',
                        [
                            'Amenity',
                            'Amenity Fine',
                        ],
                    )
                    ->where(
                        'amenity_id',
                        $item['amenity_id'],
                    )
                    ->count();

                return $item;
            },
            $items,
        );
    }

    private function selectedGuestFines(): Collection
    {
        if (
            $this->selectedBookingId === null
            || $this->selectedBookingDetailsId === null
        ) {
            return new Collection();
        }

        $detail = BookingDetail::query()
            ->find(
                $this->selectedBookingDetailsId,
            );

        if ($detail === null) {
            return new Collection();
        }

        return GuestFine::query()
            ->with([
                'fine.amenity.amenityName',
                'fine.damageType',
                'facility',
                'reportedBy',
            ])
            ->where(
                'booking_id',
                $this->selectedBookingId,
            )
            ->where(
                'facility_id',
                $detail->facility_id,
            )
            ->latest('guest_fine_id')
            ->get();
    }

    private function selectedInspection(): ?FacilityInspection
    {
        if ($this->selectedBookingDetailsId === null) {
            return null;
        }

        return FacilityInspection::query()
            ->with([
                'inspectedBy',
                'items.amenity.amenityName',
                'items.fine.damageType',
            ])
            ->where(
                'booking_details_id',
                $this->selectedBookingDetailsId,
            )
            ->first();
    }

    private function selectedInspectionRequest(): ?FacilityInspectionRequest
    {
        if ($this->selectedRequestId === null) {
            return null;
        }

        return FacilityInspectionRequest::query()
            ->with([
                'booking.guest',
                'bookingDetail',
                'facility',
                'requestedBy',
                'assignedTo',
                'inspection.inspectedBy',
            ])
            ->find($this->selectedRequestId);
    }
};

?>

<div class="space-y-6" wire:poll.15s>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">
                Facility Inspections
            </h1>

            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Accept only cashier-requested inspections, inspect the correct facility, and send fines to billing.
            </p>
        </div>

        @if (Route::has('maintenance.dashboard'))
            <flux:button
                href="{{ route('maintenance.dashboard') }}"
                wire:navigate
                variant="ghost"
            >
                Back to Dashboard
            </flux:button>
        @endif
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @error('inspection')
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            {{ $message }}
        </div>
    @enderror

    @if (
        $selectedRequestId !== null
        && $selectedInspectionRequest !== null
    )
        <flux:card>
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-lg font-semibold">
                            Inspection Request #{{ $selectedInspectionRequest->facility_inspection_request_id }}
                        </h2>

                        <flux:badge
                            color="{{ $this->requestStatusColor((string) $selectedInspectionRequest->status) }}"
                            size="sm"
                        >
                            {{ $selectedInspectionRequest->status }}
                        </flux:badge>

                        @if ($selectedInspection !== null)
                            <flux:badge
                                color="{{ $this->inspectionStatusColor((string) $selectedInspection->inspection_status) }}"
                                size="sm"
                            >
                                {{ $selectedInspection->inspection_status }}
                            </flux:badge>
                        @endif
                    </div>

                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ $selectedLabel }}
                    </p>

                    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <dt class="text-zinc-500">Requested by</dt>
                            <dd class="font-medium">
                                {{ $this->staffName($selectedInspectionRequest->requestedBy, 'Unknown cashier') }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Assigned to</dt>
                            <dd class="font-medium">
                                {{ $this->staffName($selectedInspectionRequest->assignedTo) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Requested</dt>
                            <dd class="font-medium">
                                {{ $selectedInspectionRequest->requested_at?->format('M d, Y h:i A') ?? 'N/A' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Completed</dt>
                            <dd class="font-medium">
                                {{ $selectedInspectionRequest->completed_at?->format('M d, Y h:i A') ?? 'Not completed' }}
                            </dd>
                        </div>
                    </dl>

                    @if (filled($selectedInspectionRequest->request_notes))
                        <p class="mt-3 rounded-lg bg-zinc-50 px-3 py-2 text-sm text-zinc-600 dark:bg-zinc-900 dark:text-zinc-300">
                            Cashier note: {{ $selectedInspectionRequest->request_notes }}
                        </p>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    <flux:button
                        variant="ghost"
                        wire:click="cancelSelection"
                    >
                        Close
                    </flux:button>
                </div>
            </div>

            <div class="mt-5 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                <h3 class="text-sm font-semibold">
                    Checklist items
                </h3>

                <p class="mt-1 text-xs text-zinc-500">
                    Includes inclusive facility amenities and delivered amenity requests assigned to this facility.
                </p>

                <div class="mt-3 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                        <thead class="bg-zinc-50 dark:bg-zinc-900">
                            <tr>
                                <th class="px-3 py-2 text-left">Item</th>
                                <th class="px-3 py-2 text-left">Source</th>
                                <th class="px-3 py-2 text-left">Expected Qty</th>
                                <th class="px-3 py-2 text-left">Fine setup</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @forelse ($selectedChecklistItems as $item)
                                <tr>
                                    <td class="px-3 py-2 font-medium">
                                        {{ $item['amenity_name'] }}
                                    </td>

                                    <td class="px-3 py-2">
                                        {{ $item['source_label'] }}
                                    </td>

                                    <td class="px-3 py-2">
                                        {{ $item['expected_quantity'] }}
                                    </td>

                                    <td class="px-3 py-2">
                                        @if ($item['fine_count'] > 0)
                                            <span class="text-green-700 dark:text-green-300">
                                                {{ $item['fine_count'] }} fine option(s)
                                            </span>
                                        @else
                                            <span class="text-amber-700 dark:text-amber-300">
                                                No amenity fine configured
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-3 py-5 text-center text-zinc-500">
                                        No checklist items are configured for this facility.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if (
                $this->canMarkNoDamage()
                || $this->canRecordFine()
            )
                <div class="mt-5">
                    <flux:textarea
                        label="Inspection remarks"
                        wire:model="remarks"
                        rows="3"
                        placeholder="Optional notes: all items complete, towel missing, stained blanket..."
                    />
                </div>
            @endif

            @if ($this->canRecordFine())
                <div class="mt-5 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                    <h3 class="font-semibold">
                        Record damage, missing item, or situational fine
                    </h3>

                    <div class="mt-4 grid gap-3 lg:grid-cols-4 lg:items-end">
                        <flux:select
                            label="Checklist item"
                            wire:model.live="selectedChecklistKey"
                        >
                            <option value="">
                                No specific item / situational fine
                            </option>

                            @foreach ($selectedChecklistItems as $item)
                                <option value="{{ $item['key'] }}">
                                    {{ $item['amenity_name'] }}
                                    — {{ $item['source_label'] }}
                                </option>
                            @endforeach
                        </flux:select>

                        <flux:select
                            label="Fine"
                            wire:model="fineId"
                        >
                            <option value="">Select fine</option>

                            @foreach ($fines as $fine)
                                <option value="{{ $fine->fine_id }}">
                                    {{ $this->formatFineLabel($fine) }}
                                </option>
                            @endforeach
                        </flux:select>

                        <flux:input
                            type="number"
                            label="Quantity"
                            min="1"
                            wire:model="fineQuantity"
                        />

                        <flux:button
                            variant="primary"
                            wire:click="addFine"
                        >
                            Record Fine
                        </flux:button>
                    </div>

                    @if (
                        $selectedChecklistKey !== ''
                        && $fines->isEmpty()
                    )
                        <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                            Admin must configure an amenity fine for this checklist item before it can be charged.
                        </div>
                    @endif
                </div>
            @endif

            @if ($this->canMarkNoDamage())
                <div class="mt-5 flex flex-col gap-2 rounded-lg border border-green-200 bg-green-50 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-green-900 dark:bg-green-950/30">
                    <div>
                        <p class="font-medium text-green-900 dark:text-green-100">
                            No damage or missing items found
                        </p>

                        <p class="text-xs text-green-700 dark:text-green-300">
                            Use this only after checking all inclusive and delivered requested amenities.
                        </p>
                    </div>

                    <flux:button
                        wire:click="markNoDamage"
                        wire:confirm="Confirm that all checklist items are complete and undamaged?"
                        variant="primary"
                    >
                        Mark All Complete / No Damage
                    </flux:button>
                </div>
            @elseif (
                ! $this->canRecordFine()
                && $selectedInspectionRequest->status === 'Completed'
            )
                <div class="mt-5 rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
                    This completed inspection is read-only for the current user.
                </div>
            @elseif (
                (int) $selectedInspectionRequest->assigned_to_user_id
                    !== (int) Auth::id()
            )
                <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    This request is assigned to another maintenance staff member.
                </div>
            @endif

            <div class="mt-5">
                <h3 class="text-sm font-semibold">
                    Recorded fines for this facility
                </h3>

                <div class="mt-2 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                        <thead class="bg-zinc-50 dark:bg-zinc-900">
                            <tr>
                                <th class="px-3 py-2 text-left">Fine</th>
                                <th class="px-3 py-2 text-left">Qty</th>
                                <th class="px-3 py-2 text-left">Charge</th>
                                <th class="px-3 py-2 text-left">Reported by</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @forelse ($selectedGuestFines as $guestFine)
                                <tr>
                                    <td class="px-3 py-2">
                                        {{ $this->formatFineLabel($guestFine->fine) }}
                                    </td>

                                    <td class="px-3 py-2">
                                        {{ $guestFine->quantity }}
                                    </td>

                                    <td class="px-3 py-2">
                                        ₱{{ number_format((float) $guestFine->total_charge, 2) }}
                                    </td>

                                    <td class="px-3 py-2">
                                        {{ $this->staffName($guestFine->reportedBy, 'Unknown') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-3 py-5 text-center text-zinc-500">
                                        No fines have been recorded for this facility.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </flux:card>
    @endif

    <flux:card class="overflow-hidden p-0">
        <div class="border-b border-zinc-200 p-5 dark:border-zinc-800">
            <div>
                <h2 class="font-semibold">
                    Inspection request queue and history
                </h2>

                <p class="mt-1 text-sm text-zinc-500">
                    New cashier requests appear automatically. Active inspections remain assigned to one maintenance staff member.
                </p>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    label="Search"
                    placeholder="Request, booking, guest, facility, staff"
                    clearable
                    class="xl:col-span-2"
                />

                <flux:select
                    wire:model.live="statusFilter"
                    label="Request status"
                >
                    <option value="active">Pending / In Progress</option>
                    <option value="pending">Pending only</option>
                    <option value="in_progress">In Progress only</option>
                    <option value="completed">Completed history</option>
                </flux:select>

                <flux:select
                    wire:model.live="assignmentFilter"
                    label="Assignment"
                >
                    <option value="">All assignments</option>
                    <option value="unassigned">Unassigned pending</option>
                    <option value="mine">Assigned to me</option>
                    <option value="other">Assigned to another staff</option>
                </flux:select>

                <flux:select
                    wire:model.live="dateFilter"
                    label="Scheduled departure"
                >
                    <option value="all">All departure dates</option>
                    <option value="today">Due today</option>
                    <option value="upcoming">Upcoming</option>
                    <option value="overdue">Past scheduled date</option>
                </flux:select>

                <flux:select
                    wire:model.live="perPage"
                    label="Rows per page"
                >
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </flux:select>
            </div>

            <div class="mt-4 flex justify-end">
                <flux:button
                    wire:click="clearFilters"
                    variant="ghost"
                >
                    Clear Filters
                </flux:button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[94rem] text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50">
                    <tr>
                        <th class="px-4 py-3">
                            <button wire:click="sortBy('facility_inspection_request_id')" class="font-semibold">
                                Request {{ $this->sortIndicator('facility_inspection_request_id') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">
                            <button wire:click="sortBy('requested_at')" class="font-semibold">
                                Requested {{ $this->sortIndicator('requested_at') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">
                            <button wire:click="sortBy('b_ref_no')" class="font-semibold">
                                Booking {{ $this->sortIndicator('b_ref_no') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">
                            <button wire:click="sortBy('guest_name')" class="font-semibold">
                                Guest {{ $this->sortIndicator('guest_name') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">
                            <button wire:click="sortBy('facility_name')" class="font-semibold">
                                Facility {{ $this->sortIndicator('facility_name') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">
                            <button wire:click="sortBy('check_out_date')" class="font-semibold">
                                Scheduled Departure {{ $this->sortIndicator('check_out_date') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">Requested By</th>

                        <th class="px-4 py-3">
                            <button wire:click="sortBy('assigned_to')" class="font-semibold">
                                Assigned To {{ $this->sortIndicator('assigned_to') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">
                            <button wire:click="sortBy('request_status')" class="font-semibold">
                                Request Status {{ $this->sortIndicator('request_status') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">Inspection Result</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($inspectionRequests as $request)
                        <tr wire:key="inspection-request-{{ $request->facility_inspection_request_id }}" class="align-top">
                            <td class="px-4 py-4 font-medium">
                                #{{ $request->facility_inspection_request_id }}
                            </td>

                            <td class="px-4 py-4">
                                {{ $request->requested_at?->format('M d, Y h:i A') ?? 'N/A' }}
                            </td>

                            <td class="px-4 py-4 font-medium">
                                {{ $request->booking?->b_ref_no ?? 'Unknown' }}
                            </td>

                            <td class="px-4 py-4">
                                <p class="font-medium">
                                    {{ $request->booking?->guest?->full_name
                                        ?? trim(($request->booking?->guest?->first_name ?? '').' '.($request->booking?->guest?->last_name ?? ''))
                                        ?: 'Unknown guest' }}
                                </p>

                                <p class="mt-1 text-xs text-zinc-500">
                                    {{ $request->booking?->guest?->contact_no ?? 'No contact' }}
                                </p>
                            </td>

                            <td class="px-4 py-4">
                                {{ $request->facility?->facility_name ?? 'No facility' }}
                            </td>

                            <td class="px-4 py-4">
                                {{ $request->bookingDetail?->check_out_date?->format('M d, Y') ?? 'N/A' }}
                            </td>

                            <td class="px-4 py-4">
                                {{ $this->staffName($request->requestedBy, 'Unknown cashier') }}
                            </td>

                            <td class="px-4 py-4">
                                {{ $this->staffName($request->assignedTo) }}
                            </td>

                            <td class="px-4 py-4">
                                <flux:badge
                                    color="{{ $this->requestStatusColor((string) $request->status) }}"
                                    size="sm"
                                >
                                    {{ $request->status }}
                                </flux:badge>
                            </td>

                            <td class="px-4 py-4">
                                @if ($request->inspection !== null)
                                    <flux:badge
                                        color="{{ $this->inspectionStatusColor((string) $request->inspection->inspection_status) }}"
                                        size="sm"
                                    >
                                        {{ $request->inspection->inspection_status }}
                                    </flux:badge>
                                @else
                                    <span class="text-xs text-zinc-500">
                                        Not recorded
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-4 text-right">
                                <div class="flex flex-wrap justify-end gap-2">
                                    @if ($request->status === 'Pending')
                                        <flux:button
                                            wire:click="selectRequest({{ $request->facility_inspection_request_id }})"
                                            size="sm"
                                            variant="primary"
                                        >
                                            Accept & Inspect
                                        </flux:button>
                                    @elseif (
                                        $request->status === 'In Progress'
                                        && (int) $request->assigned_to_user_id
                                            === (int) Auth::id()
                                    )
                                        <flux:button
                                            wire:click="selectRequest({{ $request->facility_inspection_request_id }})"
                                            size="sm"
                                            variant="primary"
                                        >
                                            Continue
                                        </flux:button>
                                    @elseif ($request->status === 'In Progress')
                                        <span class="self-center text-xs text-zinc-500">
                                            Assigned to another staff
                                        </span>
                                    @else
                                        <flux:button
                                            wire:click="selectRequest({{ $request->facility_inspection_request_id }})"
                                            size="sm"
                                            variant="ghost"
                                        >
                                            View
                                        </flux:button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="px-4 py-12 text-center text-zinc-500">
                                No inspection request matches the selected filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex flex-col gap-3 border-t border-zinc-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
            <p class="text-sm text-zinc-500">
                Showing
                {{ $inspectionRequests->firstItem() ?? 0 }}
                to
                {{ $inspectionRequests->lastItem() ?? 0 }}
                of
                {{ $inspectionRequests->total() }}
                inspection requests
            </p>

            {{ $inspectionRequests->links() }}
        </div>
    </flux:card>
</div>
