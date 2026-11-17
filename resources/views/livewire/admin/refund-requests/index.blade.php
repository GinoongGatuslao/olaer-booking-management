<?php

use App\Models\Guest;
use App\Models\RefundRequest;
use App\Models\Reservation;
use App\Services\GcashProofStorageService;
use App\Services\RefundRequestWorkflowService;
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

    // Completing a GCash refund the cashier sent back to the admin
    public string $completeAmount = '';
    public string $completeReference = '';
    public $completeProof = null;

    public function with(): array
    {
        return [
            'refunds' => $this->refunds(),
            'selected' => $this->selectedRequest(),
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
            ->when(
                $this->statusFilter === 'GcashToSend',
                fn ($query) => $query
                    ->where('status', 'Approved')
                    ->whereNull('refunded_at')
                    ->whereNotNull('gcash_handoff_at'),
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
                'gcashHandoffBy',
                'refundModeOfPayment',
                'refundedBy',
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
        $this->approvedAmount = number_format((float) $request->requested_amount, 2, '.', '');
        $this->adminNote = '';
        $this->completeAmount = $request->gcash_handoff_amount !== null
            ? number_format((float) $request->gcash_handoff_amount, 2, '.', '')
            : number_format((float) $request->approved_amount, 2, '.', '');
        $this->completeReference = '';
        $this->completeProof = null;
        $this->resetValidation();
        $this->showReviewModal = true;
    }

    public function closeReview(): void
    {
        $this->showReviewModal = false;
        $this->reviewRequestId = null;
        $this->approvedAmount = '';
        $this->adminNote = '';
        $this->completeAmount = '';
        $this->completeReference = '';
        $this->completeProof = null;
        $this->resetValidation();
    }

    public function updatedShowReviewModal(bool $open): void
    {
        if (! $open) {
            $this->closeReview();
        }
    }

    public function approve(RefundRequestWorkflowService $refunds): void
    {
        $this->respond($refunds, 'approve');
    }

    public function reject(RefundRequestWorkflowService $refunds): void
    {
        $this->respond($refunds, 'reject');
    }

    public function refundProofUrl(RefundRequest $refund): ?string
    {
        if (blank($refund->refund_proof_path) || ! \Illuminate\Support\Facades\Route::has('refunds.proof')) {
            return null;
        }

        return route('refunds.proof', $refund);
    }

    /** Admin sent the GCash money: record the reference number and proof to complete the refund. */
    public function completeGcashRefund(
        RefundRequestWorkflowService $refunds,
        GcashProofStorageService $proofStorage,
    ): void {
        $validated = $this->validate([
            'reviewRequestId' => ['required', 'exists:tbl_refund_request,refund_request_id'],
            'completeAmount' => ['required', 'numeric', 'min:0.01'],
            'completeReference' => ['required', 'string', 'max:50'],
            'completeProof' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'reviewRequestId.required' => 'This refund request is no longer available. Refresh the list.',
            'reviewRequestId.exists' => 'This refund request is no longer available. Refresh the list.',
            'completeAmount.required' => 'Enter the amount you sent through GCash.',
            'completeAmount.numeric' => 'The amount must be a number.',
            'completeAmount.min' => 'The amount must be greater than zero.',
            'completeReference.required' => 'Enter the GCash reference number.',
            'completeReference.max' => 'The reference number must not exceed :max characters.',
            'completeProof.required' => 'Upload the GCash proof image.',
            'completeProof.image' => 'The proof must be an image.',
            'completeProof.mimes' => 'The proof must be a JPG, PNG or WebP image.',
            'completeProof.max' => 'The proof image must be 5 MB or smaller.',
        ]);

        $proofPath = null;

        try {
            $proofPath = $proofStorage->store($this->completeProof);

            $refunds->completeGcashRefund(
                (int) $validated['reviewRequestId'],
                (string) $validated['completeAmount'],
                (string) $validated['completeReference'],
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
            $this->addError('reviewRequest', 'The refund could not be completed. Please refresh and try again.');

            return;
        }

        $this->closeReview();
        session()->flash('success', 'GCash refund completed. The cashier can now see the reference number and proof.');
    }

    private function respond(RefundRequestWorkflowService $refunds, string $decision): void
    {
        $rules = [
            'reviewRequestId' => ['required', 'exists:tbl_refund_request,refund_request_id'],
            'adminNote' => ['nullable', 'string', 'max:1000'],
        ];

        if ($decision === 'approve') {
            $rules['approvedAmount'] = ['required', 'numeric', 'min:0.01'];
        }

        $validated = $this->validate($rules, [
            'reviewRequestId.required' => 'This refund request is no longer available. Refresh the list.',
            'reviewRequestId.exists' => 'This refund request is no longer available. Refresh the list.',
            'adminNote.max' => 'The note cannot be longer than :max characters.',
            'approvedAmount.required' => 'Enter the most the cashier may refund.',
            'approvedAmount.numeric' => 'The refund amount must be a number.',
            'approvedAmount.min' => 'The refund amount must be greater than zero.',
        ]);

        try {
            $refunds->review(
                (int) $validated['reviewRequestId'],
                $decision,
                $validated['adminNote'] ?? null,
                $decision === 'approve' ? (string) $validated['approvedAmount'] : null,
                (int) Auth::id(),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addError('reviewRequest', $exception->getMessage());

            return;
        }

        $this->closeReview();
        session()->flash(
            'success',
            $decision === 'approve'
                ? 'Refund approved. The cashier can now see the amount and your note.'
                : 'Refund request rejected. The cashier can now see your note.',
        );
    }
};

?>

<div class="space-y-6">
    <x-staff-page-header
        eyebrow="Admin"
        title="Refund Requests"
        description="Cashiers send refund requests for cancelled reservations. Approve to set the most they may refund, or reject. A note is optional. GCash refunds come back here for you to send and record."
    >
        <x-slot:actions>
            <flux:button :href="route('admin.dashboard')" wire:navigate variant="ghost">Back to dashboard</flux:button>
        </x-slot:actions>
    </x-staff-page-header>

    <x-toast :message="session('success')" fresh />

    <x-error-toast :messages="session('error')" fresh />

    <flux:card class="overflow-hidden p-0">
        <div class="grid gap-3 border-b border-zinc-200 p-5 md:grid-cols-4 dark:border-zinc-800">
            <flux:input wire:model.live.debounce.300ms="search" label="Search" placeholder="Reference, guest, contact" clearable />

            <flux:select wire:model.live="statusFilter" label="Status">
                <option value="All">All</option>
                <option value="Pending">Pending</option>
                <option value="Approved">Approved</option>
                <option value="Rejected">Rejected</option>
                <option value="GcashToSend">GCash to send</option>
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
                                @elseif ($refund->isAwaitingAdminGcash())
                                    <flux:badge size="sm" color="amber">GCash: needs you</flux:badge>
                                @elseif ($refund->status === 'Approved')
                                    <flux:badge size="sm" color="amber">Waiting for cashier</flux:badge>
                                @endif
                            </td>
                            <td class="px-4 py-4 text-right">
                                <flux:button size="sm" variant="{{ $refund->status === 'Pending' || $refund->isAwaitingAdminGcash() ? 'primary' : 'ghost' }}" wire:click="openReview({{ $refund->refund_request_id }})">
                                    {{ $refund->status === 'Pending' ? 'Review' : ($refund->isAwaitingAdminGcash() ? 'Complete' : 'View') }}
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
                $awaitingGcash = $selected->isAwaitingAdminGcash();
                $proofLink = $this->refundProofUrl($selected);
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

                @if ($isPending)
                    <flux:field class="relative">
                        <flux:label>Most the cashier may refund <span class="text-red-500">*</span></flux:label>
                        <flux:input wire:model="approvedAmount" type="number" step="0.01" min="0.01" max="{{ $paid }}" :invalid="$errors->has('approvedAmount')" />
                        <flux:text class="text-xs">Only used when you approve. Up to ₱{{ number_format((float) $paid, 2) }} (what the guest paid).</flux:text>
                        @error('approvedAmount')
                            <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </flux:field>

                    <flux:field class="relative">
                        <flux:label>Note (optional)</flux:label>
                        <flux:textarea wire:model="adminNote" rows="3" placeholder="Optional. Explain your decision, for example why this amount or why it is rejected." :invalid="$errors->has('adminNote')" />
                        @error('adminNote')
                            <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </flux:field>

                    <div class="flex justify-end gap-3">
                        <flux:button type="button" variant="ghost" wire:click="closeReview">Close</flux:button>
                        <flux:button type="button" variant="danger" wire:click="reject" wire:loading.attr="disabled" wire:target="approve,reject">Reject</flux:button>
                        <flux:button type="button" variant="primary" wire:click="approve" wire:loading.attr="disabled" wire:target="approve,reject">Approve refund</flux:button>
                    </div>
                @else
                    <div class="rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                        <div class="flex justify-between gap-4">
                            <span class="text-zinc-500">{{ $selected->status }} by</span>
                            <span class="font-medium">{{ $selected->reviewedBy?->full_name ?? 'Unknown' }} · {{ $selected->reviewed_at?->format('M d, Y h:i A') }}</span>
                        </div>
                        @if ($selected->approved_amount !== null)
                            <div class="mt-2 flex justify-between gap-4">
                                <span class="text-zinc-500">Most the cashier may refund</span>
                                <span class="font-semibold">₱{{ number_format((float) $selected->approved_amount, 2) }}</span>
                            </div>
                        @endif
                        @if ($selected->admin_note)
                            <div class="mt-2 text-xs text-zinc-500">Admin note</div>
                            <div class="mt-0.5">{{ $selected->admin_note }}</div>
                        @endif
                    </div>

                    @if ($selected->gcash_handoff_at)
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100">
                            <div class="text-xs font-semibold uppercase tracking-wide">Sent back by the cashier for GCash</div>
                            <div class="mt-1">
                                ₱{{ number_format((float) $selected->gcash_handoff_amount, 2) }} · {{ $selected->gcash_handoff_at?->format('M d, Y h:i A') }}
                                @if ($selected->gcashHandoffBy) by {{ $selected->gcashHandoffBy->full_name }} @endif
                            </div>
                            @if ($selected->gcash_handoff_contact_number)
                                <div class="mt-2 text-xs">Guest's GCash number</div>
                                <div class="mt-0.5 font-semibold tracking-wide">{{ $selected->gcash_handoff_contact_number }}</div>
                            @endif
                            @if ($selected->gcash_handoff_note)
                                <div class="mt-2 text-xs">Cashier's note</div>
                                <div class="mt-0.5">{{ $selected->gcash_handoff_note }}</div>
                            @endif
                        </div>
                    @endif

                    @if ($awaitingGcash)
                        <div class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                            <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Complete the GCash refund</div>
                            <flux:text class="text-xs">Send the money through GCash first, then enter the reference number and upload the proof. The cashier will see both.</flux:text>

                            <flux:field class="relative">
                                <flux:label>Amount sent <span class="text-red-500">*</span></flux:label>
                                <flux:input wire:model="completeAmount" type="number" step="0.01" min="0.01" max="{{ $selected->approved_amount }}" :invalid="$errors->has('completeAmount')" />
                                <flux:text class="text-xs">Up to ₱{{ number_format((float) $selected->approved_amount, 2) }} (the amount you approved).</flux:text>
                                @error('completeAmount')
                                    <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $message }}</p>
                                @enderror
                            </flux:field>

                            <flux:field class="relative">
                                <flux:label>GCash reference number <span class="text-red-500">*</span></flux:label>
                                <flux:input wire:model="completeReference" placeholder="Reference number of the GCash refund" :invalid="$errors->has('completeReference')" />
                                @error('completeReference')
                                    <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $message }}</p>
                                @enderror
                            </flux:field>

                            <flux:field class="relative">
                                <flux:label>GCash proof image <span class="text-red-500">*</span></flux:label>
                                <input
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    wire:model="completeProof"
                                    class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium dark:text-zinc-300 dark:file:bg-zinc-800"
                                />
                                <flux:text class="text-xs">Screenshot of the GCash refund. JPG, PNG or WebP, up to 5 MB.</flux:text>
                                <p wire:loading wire:target="completeProof" class="text-xs font-medium text-blue-600 dark:text-blue-400">Uploading…</p>
                                @error('completeProof')
                                    <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $message }}</p>
                                @enderror
                            </flux:field>

                            @if ($completeProof && ! $errors->has('completeProof'))
                                @php($proofPreview = rescue(fn () => $completeProof->temporaryUrl(), null, false))
                                @if ($proofPreview)
                                    <img src="{{ $proofPreview }}" alt="GCash proof preview" class="max-h-56 w-full rounded-lg border border-zinc-200 object-contain dark:border-zinc-700" />
                                @endif
                            @endif
                        </div>
                    @elseif ($selected->refunded_at)
                        <div class="space-y-2 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-900 dark:border-green-900/60 dark:bg-green-950/30 dark:text-green-100">
                            <div class="text-xs font-semibold uppercase tracking-wide">Refund given</div>
                            <div>
                                ₱{{ number_format((float) $selected->refunded_amount, 2) }} via {{ $selected->refundModeOfPayment?->mode_of_payment ?? 'Unknown' }}
                                · {{ $selected->refunded_at?->format('M d, Y h:i A') }}
                                @if ($selected->refundedBy) by {{ $selected->refundedBy->full_name }} @endif
                            </div>
                            @if ($selected->refund_reference_number)
                                <div>GCash reference: <span class="font-medium">{{ $selected->refund_reference_number }}</span></div>
                            @endif
                            @if ($proofLink)
                                <a href="{{ $proofLink }}" target="_blank" rel="noopener" class="block w-fit">
                                    <img src="{{ $proofLink }}" alt="GCash refund proof" loading="lazy" class="max-h-56 rounded-lg border border-zinc-200 object-contain dark:border-zinc-700" />
                                </a>
                            @endif
                        </div>
                    @endif

                    <div class="flex justify-end gap-3">
                        <flux:button type="button" variant="ghost" wire:click="closeReview">Close</flux:button>
                        @if ($awaitingGcash)
                            <flux:button type="button" variant="primary" wire:click="completeGcashRefund" wire:loading.attr="disabled" wire:target="completeGcashRefund,completeProof">Complete refund</flux:button>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </flux:modal>
</div>
