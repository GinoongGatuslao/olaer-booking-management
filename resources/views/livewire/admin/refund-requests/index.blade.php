<?php

use App\Models\Guest;
use App\Models\ModeOfPayment;
use App\Models\RefundRequest;
use App\Models\Reservation;
use App\Services\GcashProofStorageService;
use App\Services\RefundRequestWorkflowService;
use App\Support\NameSearch;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Refund Requests - Olaer Spring Resort')] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'Pending')]
    public string $statusFilter = 'Pending';

    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    public bool $showReviewModal = false;
    public ?int $reviewRequestId = null;
    public string $approvedAmount = '';
    public string $adminNote = '';
    public string $refundModeId = '';
    public string $refundReference = '';
    public $refundProof = null;

    // Find a cancelled reservation (modal 1) and create a refund for it (modal 2)
    public bool $showLookupModal = false;
    public string $lookupSearch = '';
    public string $lookupDateFrom = '';
    public string $lookupDateTo = '';
    public string $lookupFilter = 'available';
    public int $lookupPerPage = 10;

    public bool $showCreateModal = false;
    public ?int $createReservationId = null;
    public string $createAmount = '';
    public string $createModeId = '';
    public string $createReference = '';
    public $createProof = null;
    public string $createNote = '';

    public function updated(string $property): void
    {
        // Clear the red highlight of a field as soon as it is edited.
        $this->resetValidation($property);
    }

    /** Error text for a field; an empty required field only turns red (no text). */
    public function visibleError(string $field): ?string
    {
        $message = $this->getErrorBag()->first($field);

        if ($message === '') {
            return null;
        }

        $value = data_get($this, $field);

        return $value === null || $value === '' || $value === [] ? null : $message;
    }

    public function with(): array
    {
        return [
            'refunds' => $this->refunds(),
            'selected' => $this->selectedRequest(),
            'refundModes' => ModeOfPayment::query()->orderBy('mode_of_payment_id')->get(),
            'lookup' => $this->showLookupModal ? $this->lookupReservations() : null,
            'createReservation' => $this->createReservation(),
        ];
    }

    public function refunds(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return RefundRequest::query()
            ->with(['reservation.guest', 'reservation.payments', 'requestedBy', 'reviewedBy'])
            ->when(
                in_array($this->statusFilter, ['Pending', 'Approved', 'Rejected'], true),
                fn ($query) => $query->where('status', $this->statusFilter),
            )
            ->when($search !== '', function ($query) use ($search): void {
                $query->whereHas('reservation', function ($reservation) use ($search): void {
                    $reservation->where('r_ref_no', 'like', "%{$search}%")
                        ->orWhereHas('guest', function ($guest) use ($search): void {
                            $guest->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('contact_no', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByRaw("case when status = 'Pending' then 0 else 1 end")
            ->orderByDesc('refund_request_id')
            ->paginate(in_array($this->perPage, [10, 25, 50, 100], true) ? $this->perPage : 10);
    }

    public function selectedRequest(): ?RefundRequest
    {
        if ($this->reviewRequestId === null) {
            return null;
        }

        return RefundRequest::query()
            ->with([
                'reservation.guest',
                'reservation.details.facility.facilityType',
                'reservation.payments',
                'reservation.refundRequests',
                'requestedBy',
                'reviewedBy',
            ])
            ->find($this->reviewRequestId);
    }

    public function amountPaid(Reservation $reservation): string
    {
        return app(RefundRequestWorkflowService::class)->amountPaid($reservation);
    }

    public function guestName(?Guest $guest): string
    {
        return trim(implode(' ', array_filter([
            $guest?->first_name,
            $guest?->middle_name,
            $guest?->last_name,
        ]))) ?: '—';
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'Pending';
        $this->perPage = 10;
        $this->resetPage();
    }

    public function openReview(int $refundRequestId): void
    {
        $request = RefundRequest::query()->find($refundRequestId);

        if ($request === null) {
            session()->flash('error', 'This refund request is no longer available. Refresh the list.');

            return;
        }

        $this->reviewRequestId = (int) $request->refund_request_id;
        // A request still waiting for a payout (approved before payouts were part of
        // the approval) starts from the amount that was approved.
        $this->approvedAmount = number_format((float) ($request->isWaitingForRelease()
            ? $request->approved_amount
            : $request->requested_amount), 2, '.', '');
        $this->adminNote = '';
        $this->refundModeId = (string) (ModeOfPayment::query()
            ->whereRaw('LOWER(mode_of_payment) = ?', ['cash'])
            ->value('mode_of_payment_id') ?? '');
        $this->refundReference = '';
        $this->refundProof = null;
        $this->resetValidation();
        $this->showReviewModal = true;
    }

    public function closeReview(): void
    {
        $this->showReviewModal = false;
        $this->reviewRequestId = null;
        $this->approvedAmount = '';
        $this->adminNote = '';
        $this->refundModeId = '';
        $this->refundReference = '';
        $this->refundProof = null;
        $this->resetValidation();
    }

    public function isGcashRefund(): bool
    {
        return strtolower(trim((string) ModeOfPayment::query()
            ->whereKey((int) $this->refundModeId)
            ->value('mode_of_payment'))) === 'gcash';
    }

    public function updatedRefundModeId(): void
    {
        // Reference number and proof only belong to a GCash refund.
        if (! $this->isGcashRefund()) {
            $this->refundReference = '';
            $this->refundProof = null;
        }

        $this->resetValidation(['refundReference', 'refundProof']);
    }

    public function refundProofUrl(RefundRequest $refund): ?string
    {
        if (blank($refund->refund_proof_path) || ! \Illuminate\Support\Facades\Route::has('refunds.proof')) {
            return null;
        }

        return route('refunds.proof', $refund);
    }

    public function updatedShowReviewModal(bool $open): void
    {
        if (! $open) {
            $this->closeReview();
        }
    }

    // ---- Find a cancelled reservation -------------------------------------------------

    public function openLookup(): void
    {
        $this->lookupSearch = '';
        $this->lookupDateFrom = '';
        $this->lookupDateTo = '';
        $this->lookupFilter = 'available';
        $this->lookupPerPage = 10;
        $this->resetPage('lookupPage');
        $this->resetValidation();
        $this->showLookupModal = true;
    }

    public function closeLookup(): void
    {
        $this->showLookupModal = false;
    }

    public function clearLookupFilters(): void
    {
        $this->lookupSearch = '';
        $this->lookupDateFrom = '';
        $this->lookupDateTo = '';
        $this->lookupFilter = 'available';
        $this->lookupPerPage = 10;
        $this->resetPage('lookupPage');
    }

    public function updatedLookupSearch(): void
    {
        $this->resetPage('lookupPage');
    }

    public function updatedLookupFilter(): void
    {
        $this->resetPage('lookupPage');
    }

    public function updatedLookupDateFrom(): void
    {
        $this->resetPage('lookupPage');
    }

    public function updatedLookupDateTo(): void
    {
        $this->resetPage('lookupPage');
    }

    public function updatedLookupPerPage(): void
    {
        if (! in_array($this->lookupPerPage, [10, 25, 50, 100], true)) {
            $this->lookupPerPage = 10;
        }

        $this->resetPage('lookupPage');
    }

    /** Cancelled reservations, filtered like the cashier's Reservation Management list. */
    public function lookupReservations(): LengthAwarePaginator
    {
        $verified = fn ($payments) => $payments->whereRaw('LOWER(payment_status) = ?', ['verified']);

        $query = Reservation::query()
            ->with([
                'guest',
                'details.facility.facilityType',
                'payments',
                'refundRequests.refundModeOfPayment',
            ])
            ->where('status', 'Cancelled');

        match ($this->lookupFilter) {
            'available' => $query
                ->whereHas('payments', $verified)
                ->whereDoesntHave('refundRequests', fn ($requests) => $requests->whereIn('status', [
                    RefundRequest::STATUS_PENDING,
                    RefundRequest::STATUS_APPROVED,
                ]))
                ->has('refundRequests', '<', RefundRequestWorkflowService::MAX_ATTEMPTS),
            'pending' => $query->whereHas('refundRequests', fn ($requests) => $requests->where('status', RefundRequest::STATUS_PENDING)),
            'refunded' => $query->whereHas('refundRequests', fn ($requests) => $requests->where('status', RefundRequest::STATUS_APPROVED)),
            'no_payment' => $query->whereDoesntHave('payments', $verified),
            default => null,
        };

        if ($this->lookupDateFrom !== '' || $this->lookupDateTo !== '') {
            $query->whereHas('details', function ($details): void {
                if ($this->lookupDateFrom !== '') {
                    $details->whereDate('check_in_date', '>=', $this->lookupDateFrom);
                }

                if ($this->lookupDateTo !== '') {
                    $details->whereDate('check_out_date', '<=', $this->lookupDateTo);
                }
            });
        }

        $needle = trim($this->lookupSearch);

        if ($needle !== '') {
            $like = '%'.$needle.'%';

            $query->where(function ($query) use ($like): void {
                $query->where('r_ref_no', 'like', $like)
                    ->orWhereHas('guest', function ($guest) use ($like): void {
                        $guest->where('first_name', 'like', $like)
                            ->orWhere('middle_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhereRaw(NameSearch::sql('first_name', 'middle_name', 'last_name'), NameSearch::bindings($like))
                            ->orWhere('contact_no', 'like', $like)
                            ->orWhere('email', 'like', $like);
                    });
            });
        }

        return $query
            ->orderByDesc('cancelled_at')
            ->orderByDesc('reservation_id')
            ->paginate(
                in_array($this->lookupPerPage, [10, 25, 50, 100], true) ? $this->lookupPerPage : 10,
                pageName: 'lookupPage',
            );
    }

    /** @return array{can_request: bool, reason: ?string, paid: string, used: int, max: int, next_attempt: int, latest: ?RefundRequest} */
    public function refundEligibility(Reservation $reservation): array
    {
        return app(RefundRequestWorkflowService::class)->eligibility($reservation);
    }

    // ---- Create the refund ---------------------------------------------------------------

    public function createReservation(): ?Reservation
    {
        if ($this->createReservationId === null) {
            return null;
        }

        return Reservation::query()
            ->with(['guest', 'details.facility.facilityType', 'payments', 'refundRequests'])
            ->find($this->createReservationId);
    }

    public function beginCreate(int $reservationId): void
    {
        $reservation = Reservation::query()
            ->with(['payments', 'refundRequests'])
            ->find($reservationId);

        if ($reservation === null) {
            session()->flash('error', 'This reservation is no longer available. Refresh the list.');

            return;
        }

        $eligibility = $this->refundEligibility($reservation);

        if (! $eligibility['can_request']) {
            $this->addError('lookup', (string) $eligibility['reason']);

            return;
        }

        $this->createReservationId = (int) $reservation->reservation_id;
        $this->createAmount = number_format((float) $eligibility['paid'], 2, '.', '');
        $this->createModeId = (string) (ModeOfPayment::query()
            ->whereRaw('LOWER(mode_of_payment) = ?', ['cash'])
            ->value('mode_of_payment_id') ?? '');
        $this->createReference = '';
        $this->createProof = null;
        $this->createNote = '';
        $this->resetValidation();
        $this->showLookupModal = false;
        $this->showCreateModal = true;
    }

    /** Back to the cancelled-reservation search without losing the filters. */
    public function backToLookup(): void
    {
        $this->closeCreate();
        $this->showLookupModal = true;
    }

    public function closeCreate(): void
    {
        $this->showCreateModal = false;
        $this->createReservationId = null;
        $this->createAmount = '';
        $this->createModeId = '';
        $this->createReference = '';
        $this->createProof = null;
        $this->createNote = '';
        $this->resetValidation();
    }

    public function updatedShowCreateModal(bool $open): void
    {
        if (! $open) {
            $this->closeCreate();
        }
    }

    public function isGcashCreate(): bool
    {
        return strtolower(trim((string) ModeOfPayment::query()
            ->whereKey((int) $this->createModeId)
            ->value('mode_of_payment'))) === 'gcash';
    }

    public function updatedCreateModeId(): void
    {
        if (! $this->isGcashCreate()) {
            $this->createReference = '';
            $this->createProof = null;
        }

        $this->resetValidation(['createReference', 'createProof']);
    }

    public function createRefund(
        RefundRequestWorkflowService $refunds,
        GcashProofStorageService $proofStorage,
    ): void {
        $isGcash = $this->isGcashCreate();

        $validated = $this->validate([
            'createReservationId' => ['required', 'exists:tbl_reservation,reservation_id'],
            'createAmount' => ['required', 'numeric', 'min:0.01'],
            'createModeId' => ['required', 'integer', 'exists:tbl_mode_of_payment,mode_of_payment_id'],
            'createReference' => [$isGcash ? 'required' : 'nullable', 'string', 'max:50'],
            'createProof' => [$isGcash ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'createNote' => ['nullable', 'string', 'max:1000'],
        ], [
            'createReservationId.required' => 'This reservation is no longer available. Refresh the list.',
            'createReservationId.exists' => 'This reservation is no longer available. Refresh the list.',
            'createAmount.required' => 'Enter the amount refunded to the guest.',
            'createAmount.numeric' => 'The refund amount must be a number.',
            'createAmount.min' => 'The refund amount must be greater than zero.',
            'createModeId.required' => 'Choose how the refund is given.',
            'createModeId.exists' => 'Choose a valid mode of payment.',
            'createReference.max' => 'The reference number must not exceed :max characters.',
            'createProof.image' => 'The proof must be an image.',
            'createProof.mimes' => 'The proof must be a JPG, PNG or WebP image.',
            'createProof.max' => 'The proof image must be 5 MB or smaller.',
            'createNote.max' => 'The note cannot be longer than :max characters.',
        ]);

        $proofPath = null;

        try {
            if ($isGcash && $this->createProof !== null) {
                $proofPath = $proofStorage->store($this->createProof);
            }

            $refunds->createByAdmin(
                (int) $validated['createReservationId'],
                (string) $validated['createAmount'],
                $validated['createNote'] ?? null,
                (int) $validated['createModeId'],
                $isGcash ? (string) ($validated['createReference'] ?? '') : null,
                $proofPath,
                (int) Auth::id(),
            );
        } catch (\InvalidArgumentException $exception) {
            if ($proofPath !== null) {
                $proofStorage->deletePrivate($proofPath);
            }

            $this->addError('createRefund', $exception->getMessage());

            return;
        } catch (\Throwable $exception) {
            if ($proofPath !== null) {
                $proofStorage->deletePrivate($proofPath);
            }

            report($exception);
            $this->addError('createRefund', 'The refund could not be saved. Please refresh and try again.');

            return;
        }

        $this->closeCreate();
        session()->flash('success', 'Refund created and recorded. It now reduces revenue.');
    }

    public function approve(
        RefundRequestWorkflowService $refunds,
        GcashProofStorageService $proofStorage,
    ): void {
        $this->respond($refunds, $proofStorage, 'approve');
    }

    public function reject(
        RefundRequestWorkflowService $refunds,
        GcashProofStorageService $proofStorage,
    ): void {
        $this->respond($refunds, $proofStorage, 'reject');
    }

    /** Records the payout of a refund that was approved earlier and is still waiting for it. */
    public function recordRefund(
        RefundRequestWorkflowService $refunds,
        GcashProofStorageService $proofStorage,
    ): void {
        $isGcash = $this->isGcashRefund();

        $validated = $this->validate($this->payoutRules($isGcash) + [
            'reviewRequestId' => ['required', 'exists:tbl_refund_request,refund_request_id'],
        ], $this->payoutMessages());

        $proofPath = null;

        try {
            if ($isGcash && $this->refundProof !== null) {
                $proofPath = $proofStorage->store($this->refundProof);
            }

            $refunds->release(
                (int) $validated['reviewRequestId'],
                (int) $validated['refundModeId'],
                (string) $validated['approvedAmount'],
                $isGcash ? (string) ($validated['refundReference'] ?? '') : null,
                $proofPath,
                (int) Auth::id(),
            );
        } catch (\InvalidArgumentException $exception) {
            if ($proofPath !== null) {
                $proofStorage->deletePrivate($proofPath);
            }

            $this->addError('reviewRequest', $exception->getMessage());

            return;
        } catch (\Throwable $exception) {
            if ($proofPath !== null) {
                $proofStorage->deletePrivate($proofPath);
            }

            report($exception);
            $this->addError('reviewRequest', 'The refund could not be recorded. Please refresh and try again.');

            return;
        }

        $this->closeReview();
        session()->flash('success', 'Refund recorded. It now reduces revenue.');
    }

    /** @return array<string, array<int, string>> */
    private function payoutRules(bool $isGcash): array
    {
        return [
            'approvedAmount' => ['required', 'numeric', 'min:0.01'],
            'refundModeId' => ['required', 'integer', 'exists:tbl_mode_of_payment,mode_of_payment_id'],
            'refundReference' => [$isGcash ? 'required' : 'nullable', 'string', 'max:50'],
            'refundProof' => [$isGcash ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    /** @return array<string, string> */
    private function payoutMessages(): array
    {
        return [
            'reviewRequestId.required' => 'This refund request is no longer available. Refresh the list.',
            'reviewRequestId.exists' => 'This refund request is no longer available. Refresh the list.',
            'approvedAmount.required' => 'Enter the amount refunded to the guest.',
            'approvedAmount.numeric' => 'The refund amount must be a number.',
            'approvedAmount.min' => 'The refund amount must be greater than zero.',
            'refundModeId.required' => 'Choose how the refund is given.',
            'refundModeId.exists' => 'Choose a valid mode of payment.',
            'refundReference.max' => 'The reference number must not exceed :max characters.',
            'refundProof.image' => 'The proof must be an image.',
            'refundProof.mimes' => 'The proof must be a JPG, PNG or WebP image.',
            'refundProof.max' => 'The proof image must be 5 MB or smaller.',
        ];
    }

    private function respond(
        RefundRequestWorkflowService $refunds,
        GcashProofStorageService $proofStorage,
        string $decision,
    ): void {
        $approving = $decision === 'approve';
        $isGcash = $approving && $this->isGcashRefund();

        $rules = [
            'reviewRequestId' => ['required', 'exists:tbl_refund_request,refund_request_id'],
            'adminNote' => ['required', 'string', 'max:1000'],
        ];

        if ($approving) {
            $rules += $this->payoutRules($isGcash);
        }

        $validated = $this->validate($rules, $this->payoutMessages() + [
            'adminNote.required' => 'Add a note explaining your decision.',
            'adminNote.max' => 'The note cannot be longer than :max characters.',
        ]);

        $proofPath = null;

        try {
            if ($isGcash && $this->refundProof !== null) {
                $proofPath = $proofStorage->store($this->refundProof);
            }

            $refunds->review(
                (int) $validated['reviewRequestId'],
                $decision,
                (string) $validated['adminNote'],
                $approving ? (string) $validated['approvedAmount'] : null,
                (int) Auth::id(),
                $approving ? (int) $validated['refundModeId'] : null,
                $isGcash ? (string) ($validated['refundReference'] ?? '') : null,
                $proofPath,
            );
        } catch (\InvalidArgumentException $exception) {
            if ($proofPath !== null) {
                $proofStorage->deletePrivate($proofPath);
            }

            $this->addError('reviewRequest', $exception->getMessage());

            return;
        } catch (\Throwable $exception) {
            if ($proofPath !== null) {
                $proofStorage->deletePrivate($proofPath);
            }

            report($exception);
            $this->addError('reviewRequest', 'The refund could not be saved. Please refresh and try again.');

            return;
        }

        $this->closeReview();
        session()->flash(
            'success',
            $approving
                ? 'Refund approved and recorded. It now reduces revenue.'
                : 'Refund request rejected. The cashier can now see your note.',
        );
    }
};

?>

<div class="space-y-6">
    <x-staff-page-header
        eyebrow="Admin"
        title="Refund Requests"
        description="Cashiers send refund requests for cancelled reservations. Approve to give the refund (enter the amount and how it is paid, Cash or GCash), or reject, and leave a note either way."
    >
        <x-slot:actions>
            <flux:button type="button" variant="primary" icon="plus" wire:click="openLookup">Create refund request</flux:button>
            <flux:button :href="route('admin.dashboard')" wire:navigate variant="ghost">Back to dashboard</flux:button>
        </x-slot:actions>
    </x-staff-page-header>

    <x-toast :message="session('success')" fresh />

    @if (session('error'))
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            {{ session('error') }}
        </div>
    @endif

    <flux:card class="overflow-hidden p-0">
        <div class="grid gap-3 border-b border-zinc-200 p-5 md:grid-cols-4 dark:border-zinc-800">
            <flux:input wire:model.live.debounce.300ms="search" label="Search" placeholder="Reference, guest, contact" clearable />

            <flux:select wire:model.live="statusFilter" label="Status">
                <option value="All">All</option>
                <option value="Pending">Pending</option>
                <option value="Approved">Approved</option>
                <option value="Rejected">Rejected</option>
            </flux:select>

            <flux:select wire:model.live="perPage" label="Rows">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </flux:select>

            <div class="flex items-end">
                <flux:button type="button" wire:click="clearFilters" variant="ghost">Clear Filters</flux:button>
            </div>
        </div>

        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,statusFilter,perPage,clearFilters,gotoPage,previousPage,nextPage">
            <table class="w-full min-w-[56rem] text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-950/60 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Sent</th>
                        <th class="px-4 py-3 font-semibold">Reservation</th>
                        <th class="px-4 py-3 font-semibold">Guest</th>
                        <th class="px-4 py-3 text-right font-semibold">Paid</th>
                        <th class="px-4 py-3 text-right font-semibold">Requested</th>
                        <th class="px-4 py-3 text-right font-semibold">Approved</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th class="px-4 py-3 text-right font-semibold">Action</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($refunds as $refund)
                        <tr wire:key="refund-row-{{ $refund->refund_request_id }}" class="{{ $reviewRequestId === (int) $refund->refund_request_id ? 'bg-blue-50 dark:bg-blue-950/30' : '' }}">
                            <td class="whitespace-nowrap px-4 py-4">
                                <div>{{ $refund->created_at?->format('M d, Y') }}</div>
                                <div class="text-xs text-zinc-500">{{ $refund->created_at?->format('h:i A') }} · by {{ $refund->requestedBy?->full_name ?? 'Unknown' }}</div>
                            </td>
                            <td class="px-4 py-4">
                                <div class="font-medium">{{ $refund->reservation?->r_ref_no }}</div>
                                <div class="text-xs text-zinc-500">Attempt {{ $refund->attempt_no }} of {{ RefundRequestWorkflowService::MAX_ATTEMPTS }}</div>
                            </td>
                            <td class="px-4 py-4">{{ $this->guestName($refund->reservation?->guest) }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right">₱{{ $refund->reservation ? number_format((float) $this->amountPaid($refund->reservation), 2) : '0.00' }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right">₱{{ number_format((float) $refund->requested_amount, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right font-medium">{{ $refund->approved_amount !== null ? '₱'.number_format((float) $refund->approved_amount, 2) : '—' }}</td>
                            <td class="px-4 py-4">
                                <flux:badge size="sm" color="{{ match ((string) $refund->status) { 'Approved' => 'green', 'Rejected' => 'red', default => 'amber' } }}">{{ $refund->status }}</flux:badge>
                                @if ($refund->refunded_at)
                                    <flux:badge size="sm" color="zinc">Given to guest</flux:badge>
                                @elseif ($refund->status === 'Approved')
                                    <flux:badge size="sm" color="amber">Not paid out yet</flux:badge>
                                @endif
                            </td>
                            <td class="px-4 py-4 text-right">
                                <flux:button size="sm" variant="{{ $refund->status === 'Pending' ? 'primary' : 'ghost' }}" wire:click="openReview({{ $refund->refund_request_id }})">
                                    {{ $refund->status === 'Pending' ? 'Review' : 'View' }}
                                </flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-10 text-center text-zinc-500">No refund requests match the current filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-800">
            {{ $refunds->links() }}
        </div>
    </flux:card>

    <flux:modal wire:model="showReviewModal" class="w-full max-h-[90vh] overflow-y-auto md:w-[40rem]">
        @if ($selected && $selected->reservation)
            @php
                $reservation = $selected->reservation;
                $paid = $this->amountPaid($reservation);
                $isPending = (string) $selected->status === 'Pending';
                $awaitingPayout = $selected->isWaitingForRelease();
                $others = $reservation->refundRequests->where('refund_request_id', '!=', $selected->refund_request_id);
            @endphp

            <div class="space-y-5">
                <div class="pe-8">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="lg">Refund request · {{ $reservation->r_ref_no }}</flux:heading>
                        <flux:badge size="sm" color="{{ match ((string) $selected->status) { 'Approved' => 'green', 'Rejected' => 'red', default => 'amber' } }}">{{ $selected->status }}</flux:badge>
                    </div>
                    <flux:text class="mt-1">
                        Attempt {{ $selected->attempt_no }} of {{ RefundRequestWorkflowService::MAX_ATTEMPTS }}, sent {{ $selected->created_at?->format('M d, Y h:i A') }} by {{ $selected->requestedBy?->full_name ?? 'a cashier' }}.
                    </flux:text>
                </div>

                @error('reviewRequest')
                    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200">{{ $message }}</div>
                @enderror
                @error('reviewRequestId')
                    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200">{{ $message }}</div>
                @enderror

                <div class="space-y-2 rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Reservation</div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Guest</span><span class="text-right font-medium">{{ $this->guestName($reservation->guest) }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Contact</span><span>{{ $reservation->guest?->contact_no ?: '—' }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Email</span><span class="break-all">{{ $reservation->guest?->email ?: '—' }}</span></div>
                    <div class="flex justify-between gap-4">
                        <span class="text-zinc-500">Facilities</span>
                        <span class="text-right">
                            @foreach ($reservation->details as $detail)
                                <span class="block">{{ $detail->facility?->facility_name ?? 'Unassigned facility' }}{{ $detail->facility?->facilityType ? ' ('.$detail->facility->facilityType->facility_type.')' : '' }} · {{ $detail->check_in_date?->format('M d, Y') }} → {{ $detail->check_out_date?->format('M d, Y') }}</span>
                            @endforeach
                        </span>
                    </div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Total price</span><span>₱{{ number_format((float) $reservation->total_price, 2) }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Verified payments</span><span class="font-medium">₱{{ number_format((float) $paid, 2) }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Cancelled on</span><span>{{ $reservation->cancelled_at?->format('M d, Y') }}</span></div>
                </div>

                <div class="rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Cancellation reason</div>
                    <div class="mt-1">{{ $reservation->cancellation_reason ?: 'No reason recorded' }}</div>
                </div>

                <div class="rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                    <div class="flex justify-between gap-4">
                        <span class="text-zinc-500">Cashier is asking for</span>
                        <span class="font-semibold">₱{{ number_format((float) $selected->requested_amount, 2) }}</span>
                    </div>
                    @if ($selected->request_note)
                        <div class="mt-2 text-xs text-zinc-500">Cashier's note</div>
                        <div class="mt-0.5">{{ $selected->request_note }}</div>
                    @endif
                </div>

                @if ($others->isNotEmpty())
                    <div class="rounded-lg bg-zinc-50 p-4 text-sm dark:bg-zinc-900/60">
                        <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Earlier attempts on this reservation</div>
                        @foreach ($others as $other)
                            <div class="mt-2" wire:key="other-{{ $other->refund_request_id }}">
                                Attempt {{ $other->attempt_no }} · {{ $other->status }} · asked ₱{{ number_format((float) $other->requested_amount, 2) }}@if ($other->approved_amount !== null), approved ₱{{ number_format((float) $other->approved_amount, 2) }}@endif
                                @if ($other->admin_note)
                                    <div class="text-xs text-zinc-500">Admin note: {{ $other->admin_note }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($isPending || $awaitingPayout)
                    @if ($awaitingPayout)
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">
                            Approved for ₱{{ number_format((float) $selected->approved_amount, 2) }} but the money was not recorded as given yet. Record how it was paid out.
                        </div>
                    @endif

                    <flux:field class="relative">
                        <flux:label>{{ $awaitingPayout ? 'Amount given to the guest' : 'Amount refunded to the guest' }} <span class="text-red-500">*</span></flux:label>
                        <flux:input wire:model="approvedAmount" type="number" step="0.01" min="0.01" max="{{ $awaitingPayout ? $selected->approved_amount : $paid }}" :invalid="$errors->has('approvedAmount')" />
                        <flux:text class="text-xs">
                            @if ($awaitingPayout)
                                Up to ₱{{ number_format((float) $selected->approved_amount, 2) }} (the approved amount).
                            @else
                                The real amount you are giving back. Only used when you approve. Up to ₱{{ number_format((float) $paid, 2) }} (what the guest paid).
                            @endif
                        </flux:text>
                        @if ($msg = $this->visibleError('approvedAmount'))
                            <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                        @endif
                    </flux:field>

                    <flux:field class="relative">
                        <flux:label>Mode of payment <span class="text-red-500">*</span></flux:label>
                        <flux:select wire:model.live="refundModeId" :invalid="$errors->has('refundModeId')">
                            @foreach ($refundModes as $mode)
                                <option value="{{ $mode->mode_of_payment_id }}">{{ $mode->mode_of_payment }}</option>
                            @endforeach
                        </flux:select>
                        @if ($msg = $this->visibleError('refundModeId'))
                            <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                        @endif
                    </flux:field>

                    @if ($this->isGcashRefund())
                        <flux:field class="relative">
                            <flux:label>GCash reference number <span class="text-red-500">*</span></flux:label>
                            <flux:input wire:model="refundReference" placeholder="Reference number of the GCash refund" :invalid="$errors->has('refundReference')" />
                            @if ($msg = $this->visibleError('refundReference'))
                                <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                            @endif
                        </flux:field>

                        <flux:field class="relative">
                            <flux:label>GCash proof image <span class="text-red-500">*</span></flux:label>
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                wire:model="refundProof"
                                class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium dark:text-zinc-300 dark:file:bg-zinc-800"
                            />
                            <flux:text class="text-xs">Screenshot of the GCash refund. JPG, PNG or WebP, up to 5 MB.</flux:text>
                            <p wire:loading wire:target="refundProof" class="text-xs font-medium text-blue-600 dark:text-blue-400">Uploading…</p>
                            @if ($msg = $this->visibleError('refundProof'))
                                <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                            @endif
                        </flux:field>

                        @if ($refundProof && ! $errors->has('refundProof'))
                            @php($proofPreview = rescue(fn () => $refundProof->temporaryUrl(), null, false))
                            @if ($proofPreview)
                                <img src="{{ $proofPreview }}" alt="GCash proof preview" class="max-h-56 w-full rounded-lg border border-zinc-200 object-contain dark:border-zinc-700" />
                            @endif
                        @endif
                    @endif

                    @if ($isPending)
                        <flux:field class="relative">
                            <flux:label>Note <span class="text-red-500">*</span></flux:label>
                            <flux:textarea wire:model="adminNote" rows="3" placeholder="Explain your decision, for example why this amount or why it is rejected." :invalid="$errors->has('adminNote')" />
                            @error('adminNote')
                                <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </flux:field>

                        <div class="flex justify-end gap-3">
                            <flux:button type="button" variant="ghost" wire:click="closeReview">Close</flux:button>
                            <flux:button type="button" variant="danger" wire:click="reject" wire:loading.attr="disabled" wire:target="approve,reject,refundProof">Reject</flux:button>
                            <flux:button type="button" variant="primary" wire:click="approve" wire:loading.attr="disabled" wire:target="approve,reject,refundProof">Approve and give refund</flux:button>
                        </div>
                    @else
                        <div class="flex justify-end gap-3">
                            <flux:button type="button" variant="ghost" wire:click="closeReview">Close</flux:button>
                            <flux:button type="button" variant="primary" wire:click="recordRefund" wire:loading.attr="disabled" wire:target="recordRefund,refundProof">Record refund</flux:button>
                        </div>
                    @endif
                @else
                    <div class="rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                        <div class="flex justify-between gap-4">
                            <span class="text-zinc-500">{{ $selected->status }} by</span>
                            <span class="font-medium">{{ $selected->reviewedBy?->full_name ?? 'Unknown' }} · {{ $selected->reviewed_at?->format('M d, Y h:i A') }}</span>
                        </div>
                        @if ($selected->isGivenToGuest())
                            <div class="mt-2 flex justify-between gap-4">
                                <span class="text-zinc-500">Refunded to the guest</span>
                                <span class="font-semibold">₱{{ number_format((float) $selected->refunded_amount, 2) }} via {{ $selected->refundModeOfPayment?->mode_of_payment ?? 'Unknown' }}</span>
                            </div>
                            <div class="mt-1 flex justify-between gap-4">
                                <span class="text-zinc-500">Given on</span>
                                <span>{{ $selected->refunded_at?->format('M d, Y h:i A') }}@if ($selected->refundedBy) by {{ $selected->refundedBy->full_name }}@endif</span>
                            </div>
                            @if ($selected->refund_reference_number)
                                <div class="mt-1 flex justify-between gap-4">
                                    <span class="text-zinc-500">GCash reference</span>
                                    <span>{{ $selected->refund_reference_number }}</span>
                                </div>
                            @endif
                            @if ($proofLink = $this->refundProofUrl($selected))
                                <div class="mt-1 text-right">
                                    <a href="{{ $proofLink }}" target="_blank" rel="noopener" class="font-medium underline">View proof</a>
                                </div>
                            @endif
                        @elseif ($selected->approved_amount !== null)
                            <div class="mt-2 flex justify-between gap-4">
                                <span class="text-zinc-500">Approved amount</span>
                                <span class="font-semibold">₱{{ number_format((float) $selected->approved_amount, 2) }}</span>
                            </div>
                        @endif
                        <div class="mt-2 text-xs text-zinc-500">Admin note</div>
                        <div class="mt-0.5">{{ $selected->admin_note }}</div>
                    </div>

                    <div class="flex justify-end">
                        <flux:button type="button" variant="ghost" wire:click="closeReview">Close</flux:button>
                    </div>
                @endif
            </div>
        @endif
    </flux:modal>
    {{-- 1. Find a cancelled reservation --}}
    <flux:modal wire:model="showLookupModal" class="w-full max-h-[90vh] overflow-y-auto max-w-[95vw] md:w-[72rem]">
        <div class="space-y-5">
            <div class="pe-8">
                <flux:heading size="lg">Create a refund request</flux:heading>
                <flux:text class="mt-1">Find the cancelled reservation, then create its refund.</flux:text>
            </div>

            @error('lookup')
                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200">{{ $message }}</div>
            @enderror

            @if ($lookup)
                <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <div class="grid gap-3 border-b border-zinc-200 p-4 md:grid-cols-5 dark:border-zinc-800">
                        <flux:input wire:model.live.debounce.300ms="lookupSearch" label="Search" placeholder="Reference, guest, contact" clearable />

                        <flux:select wire:model.live="lookupFilter" label="Refund">
                            <option value="available">Can request a refund</option>
                            <option value="all">All cancelled</option>
                            <option value="pending">Request pending</option>
                            <option value="refunded">Already refunded</option>
                            <option value="no_payment">No verified payment</option>
                        </flux:select>

                        <flux:input wire:model.live="lookupDateFrom" type="date" label="From" />

                        <flux:input wire:model.live="lookupDateTo" type="date" label="To" />

                        <div class="flex items-end gap-2">
                            <flux:select wire:model.live="lookupPerPage" label="Rows">
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </flux:select>
                            <flux:button type="button" wire:click="clearLookupFilters" variant="ghost">Clear</flux:button>
                        </div>
                    </div>

                    <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="lookupSearch,lookupFilter,lookupDateFrom,lookupDateTo,lookupPerPage,clearLookupFilters,gotoPage,previousPage,nextPage">
                        <table class="w-full min-w-[60rem] text-left text-sm">
                            <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-400">
                                <tr>
                                    <th class="w-10 px-3 py-3"><span class="sr-only">Expand</span></th>
                                    <th class="px-4 py-3 font-semibold">Ref</th>
                                    <th class="px-4 py-3 font-semibold">Guest</th>
                                    <th class="px-4 py-3 font-semibold">Facility</th>
                                    <th class="px-4 py-3 font-semibold">Schedule</th>
                                    <th class="px-4 py-3 font-semibold">Cancelled</th>
                                    <th class="px-4 py-3 text-right font-semibold">Paid</th>
                                    <th class="px-4 py-3 text-right font-semibold">Action</th>
                                </tr>
                            </thead>

                            @forelse ($lookup as $row)
                                @php($rowDetails = $row->details)
                                @php($rowFirst = $rowDetails->first())
                                @php($rowExtra = max($rowDetails->count() - 1, 0))
                                @php($rowElig = $this->refundEligibility($row))
                                <tbody wire:key="lookup-{{ $row->reservation_id }}" x-data="{ open: false }" class="border-b border-zinc-200 dark:border-zinc-800">
                                    <tr @click="open = ! open" class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                                        <td class="px-3 py-4 text-zinc-500 dark:text-zinc-400">
                                            <flux:icon.chevron-right class="size-4 transition-transform" x-bind:class="open && 'rotate-90'" />
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-4 font-medium">{{ $row->r_ref_no }}</td>
                                        <td class="px-4 py-4">
                                            <div class="font-medium">{{ $this->guestName($row->guest) }}</div>
                                            <div class="text-xs text-zinc-500">{{ $row->guest?->contact_no }}</div>
                                        </td>
                                        <td class="px-4 py-4">
                                            @if ($rowFirst)
                                                <div class="flex flex-wrap items-center gap-1">
                                                    <span class="font-medium">{{ $rowFirst->facility?->facility_name ?? 'No facility' }}</span>
                                                    @if ($rowExtra > 0)
                                                        <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">+{{ $rowExtra }} more</span>
                                                    @endif
                                                </div>
                                            @else
                                                <span class="font-medium">No facility</span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-4">
                                            <div>{{ optional($rowDetails->min('check_in_date'))->format('M d, Y') }}</div>
                                            <div class="text-xs text-zinc-500">to {{ optional($rowDetails->max('check_out_date'))->format('M d, Y') }}</div>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-4">{{ $row->cancelled_at?->format('M d, Y') }}</td>
                                        <td class="whitespace-nowrap px-4 py-4 text-right font-medium">₱{{ number_format((float) $rowElig['paid'], 2) }}</td>
                                        <td class="px-4 py-4 text-right" @click.stop>
                                            @if ($rowElig['can_request'])
                                                <flux:button size="sm" variant="primary" wire:click="beginCreate({{ $row->reservation_id }})">Create refund</flux:button>
                                            @else
                                                <span class="text-xs text-zinc-500">{{ $rowElig['reason'] }}</span>
                                            @endif
                                        </td>
                                    </tr>

                                    <tr x-show="open" x-transition.opacity style="display: none" class="bg-zinc-50/60 dark:bg-zinc-950/40">
                                        <td colspan="8" class="space-y-3 px-6 py-4 text-sm">
                                            <div>
                                                <div class="text-xs uppercase tracking-wide text-zinc-500">Reserved facilities ({{ $rowDetails->count() }})</div>
                                                @foreach ($rowDetails as $rowDetail)
                                                    <div class="mt-1">
                                                        <span class="font-medium">{{ $rowDetail->facility?->facility_name ?? 'Unassigned facility' }}</span>
                                                        <span class="text-xs text-zinc-500">
                                                            {{ $rowDetail->facility?->facilityType?->facility_type }}
                                                            · {{ $rowDetail->check_in_date?->format('M d, Y') }} → {{ $rowDetail->check_out_date?->format('M d, Y') }}
                                                        </span>
                                                    </div>
                                                @endforeach
                                            </div>
                                            <div>
                                                <div class="text-xs uppercase tracking-wide text-zinc-500">Cancellation reason</div>
                                                <div>{{ $row->cancellation_reason ?: 'No reason recorded' }}</div>
                                            </div>
                                            @if ($row->refundRequests->isNotEmpty())
                                                <div>
                                                    <div class="text-xs uppercase tracking-wide text-zinc-500">Refund attempts ({{ $rowElig['used'] }} of {{ $rowElig['max'] }})</div>
                                                    @foreach ($row->refundRequests->sortBy('attempt_no') as $attempt)
                                                        <div class="mt-1" wire:key="lookup-attempt-{{ $attempt->refund_request_id }}">
                                                            Attempt {{ $attempt->attempt_no }} · {{ $attempt->status }}
                                                            · asked ₱{{ number_format((float) $attempt->requested_amount, 2) }}
                                                            @if ($attempt->refunded_at)
                                                                · refunded ₱{{ number_format((float) $attempt->refunded_amount, 2) }} via {{ $attempt->refundModeOfPayment?->mode_of_payment ?? 'Unknown' }}
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                </tbody>
                            @empty
                                <tbody>
                                    <tr>
                                        <td colspan="8" class="px-4 py-10 text-center text-zinc-500">No cancelled reservations match these filters.</td>
                                    </tr>
                                </tbody>
                            @endforelse
                        </table>
                    </div>

                    <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-800">
                        {{ $lookup->links() }}
                    </div>
                </div>
            @endif

            <div class="flex justify-end">
                <flux:button type="button" variant="ghost" wire:click="closeLookup">Close</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- 2. Create the refund for the chosen reservation --}}
    <flux:modal wire:model="showCreateModal" class="w-full max-h-[90vh] overflow-y-auto md:w-[36rem]">
        @if ($createReservation)
            @php($createInfo = $this->refundEligibility($createReservation))
            <form wire:submit="createRefund" novalidate class="space-y-5">
                <div class="pe-8">
                    <flux:heading size="lg">Create refund request</flux:heading>
                    <flux:text class="mt-1">
                        You approve and give this refund yourself, so it is recorded right away and reduces revenue from today.
                        Attempt {{ $createInfo['next_attempt'] }} of {{ $createInfo['max'] }}.
                    </flux:text>
                </div>

                @error('createRefund')
                    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200">{{ $message }}</div>
                @enderror
                @error('createReservationId')
                    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200">{{ $message }}</div>
                @enderror

                <div class="space-y-2 rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Reservation</span><span class="font-medium">{{ $createReservation->r_ref_no }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Guest</span><span class="text-right font-medium">{{ $this->guestName($createReservation->guest) }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Contact</span><span>{{ $createReservation->guest?->contact_no ?: '—' }}</span></div>
                    <div class="flex justify-between gap-4">
                        <span class="text-zinc-500">Facilities</span>
                        <span class="text-right">
                            @foreach ($createReservation->details as $createDetail)
                                <span class="block">{{ $createDetail->facility?->facility_name ?? 'Unassigned facility' }} · {{ $createDetail->check_in_date?->format('M d, Y') }} → {{ $createDetail->check_out_date?->format('M d, Y') }}</span>
                            @endforeach
                        </span>
                    </div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Total price</span><span>₱{{ number_format((float) $createReservation->total_price, 2) }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Verified payments</span><span class="font-medium">₱{{ number_format((float) $createInfo['paid'], 2) }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500">Cancelled on</span><span>{{ $createReservation->cancelled_at?->format('M d, Y') }}</span></div>
                    <div>
                        <div class="text-zinc-500">Cancellation reason</div>
                        <div class="mt-0.5">{{ $createReservation->cancellation_reason ?: 'No reason recorded' }}</div>
                    </div>
                </div>

                <flux:field class="relative">
                    <flux:label>Amount refunded to the guest <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model="createAmount" type="number" step="0.01" min="0.01" max="{{ $createInfo['paid'] }}" :invalid="$errors->has('createAmount')" />
                    <flux:text class="text-xs">Up to ₱{{ number_format((float) $createInfo['paid'], 2) }} (what the guest paid).</flux:text>
                    @if ($msg = $this->visibleError('createAmount'))
                        <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                    @endif
                </flux:field>

                <flux:field class="relative">
                    <flux:label>Mode of payment <span class="text-red-500">*</span></flux:label>
                    <flux:select wire:model.live="createModeId" :invalid="$errors->has('createModeId')">
                        @foreach ($refundModes as $mode)
                            <option value="{{ $mode->mode_of_payment_id }}">{{ $mode->mode_of_payment }}</option>
                        @endforeach
                    </flux:select>
                    @if ($msg = $this->visibleError('createModeId'))
                        <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                    @endif
                </flux:field>

                @if ($this->isGcashCreate())
                    <flux:field class="relative">
                        <flux:label>GCash reference number <span class="text-red-500">*</span></flux:label>
                        <flux:input wire:model="createReference" placeholder="Reference number of the GCash refund" :invalid="$errors->has('createReference')" />
                        @if ($msg = $this->visibleError('createReference'))
                            <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                        @endif
                    </flux:field>

                    <flux:field class="relative">
                        <flux:label>GCash proof image <span class="text-red-500">*</span></flux:label>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            wire:model="createProof"
                            class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium dark:text-zinc-300 dark:file:bg-zinc-800"
                        />
                        <flux:text class="text-xs">Screenshot of the GCash refund. JPG, PNG or WebP, up to 5 MB.</flux:text>
                        <p wire:loading wire:target="createProof" class="text-xs font-medium text-blue-600 dark:text-blue-400">Uploading…</p>
                        @if ($msg = $this->visibleError('createProof'))
                            <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                        @endif
                    </flux:field>

                    @if ($createProof && ! $errors->has('createProof'))
                        @php($createPreview = rescue(fn () => $createProof->temporaryUrl(), null, false))
                        @if ($createPreview)
                            <img src="{{ $createPreview }}" alt="GCash proof preview" class="max-h-56 w-full rounded-lg border border-zinc-200 object-contain dark:border-zinc-700" />
                        @endif
                    @endif
                @endif

                <flux:field class="relative">
                    <flux:label>Note (optional)</flux:label>
                    <flux:textarea wire:model="createNote" rows="3" placeholder="Why this refund is being given." :invalid="$errors->has('createNote')" />
                    @error('createNote')
                        <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </flux:field>

                <div class="flex justify-between gap-3">
                    <flux:button type="button" variant="ghost" wire:click="backToLookup">Back to search</flux:button>
                    <div class="flex gap-3">
                        <flux:button type="button" variant="ghost" wire:click="closeCreate">Close</flux:button>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="createRefund,createProof">Create and give refund</flux:button>
                    </div>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
