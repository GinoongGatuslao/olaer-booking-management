<?php

use App\Models\Booking;
use App\Models\EntranceSlip;
use App\Models\ModeOfPayment;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\OutstandingBreakdownService;
use App\Services\PaymentWorkflowService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Payment Management - Olaer Spring Resort')] class extends Component
{

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

    #[Url(as: 'payable_q', except: '')]
    public string $payableSearch = '';

    #[Url(as: 'payable_type', except: 'booking')]
    public string $targetType = 'booking';

    #[Url(as: 'payable_per_page', except: 10)]
    public int $payablePerPage = 10;

    #[Url(as: 'payment_q', except: '')]
    public string $historySearch = '';

    #[Url(as: 'payment_status', except: '')]
    public string $historyStatusFilter = '';

    #[Url(as: 'payment_mode', except: '')]
    public string $historyModeFilter = '';

    #[Url(as: 'payment_target', except: '')]
    public string $historyTargetFilter = '';

    #[Url(as: 'date_from', except: '')]
    public string $historyDateFrom = '';

    #[Url(as: 'date_to', except: '')]
    public string $historyDateTo = '';

    #[Url(as: 'payment_sort', except: 'date_paid')]
    public string $historySortField = 'date_paid';

    #[Url(as: 'payment_direction', except: 'desc')]
    public string $historySortDirection = 'desc';

    #[Url(as: 'payment_per_page', except: 10)]
    public int $historyPerPage = 10;

    public ?int $selectedTargetId = null;
    public string $selectedTargetLabel = '';
    public string $selectedAmountDue = '0.00';

    public string $amountPaid = '';
    public string $modeOfPaymentId = '';
    public string $referenceNumber = '';
    public string $cashPaid = '';

    public ?int $selectedPaymentId = null;

    public function mount(): void
    {
        $firstMode = ModeOfPayment::query()
            ->orderBy('mode_of_payment_id')
            ->first();

        if ($firstMode !== null) {
            $this->modeOfPaymentId = (string) $firstMode->mode_of_payment_id;
        }

        // Preserves the Booking Workspace action:
        // /cashier/payments?booking={booking_id}
        $bookingId = request()->integer('booking');

        if ($bookingId > 0) {
            $this->selectPayable('booking', $bookingId);
        }
    }

    public function with(): array
    {
        return [
            'payables' => $this->payables(),
            'payments' => $this->payments(),
            'modeOfPayments' => $this->modeOfPayments(),
            'selectedPayment' => $this->selectedPayment(),
            'breakdown' => $this->selectedBreakdown(),
        ];
    }

    /** Unpaid parts of the selected transaction (fully paid charges are left out). */
    public function selectedBreakdown(): ?array
    {
        if (! $this->showPaymentModal || $this->selectedTargetId === null) {
            return null;
        }

        try {
            return app(OutstandingBreakdownService::class)->for($this->targetType, $this->selectedTargetId);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function updatedPayableSearch(): void
    {
        $this->resetPage('payablesPage');
    }

    public function updatedTargetType(): void
    {
        $this->clearSelection();
        $this->resetPage('payablesPage');
    }

    public function updatedPayablePerPage(): void
    {
        if (! in_array($this->payablePerPage, [10, 25, 50, 100], true)) {
            $this->payablePerPage = 10;
        }

        $this->resetPage('payablesPage');
    }

    public function updatedHistorySearch(): void
    {
        $this->resetPage('paymentsPage');
    }

    public function updatedHistoryStatusFilter(): void
    {
        $this->resetPage('paymentsPage');
    }

    public function updatedHistoryModeFilter(): void
    {
        $this->resetPage('paymentsPage');
    }

    public function updatedHistoryTargetFilter(): void
    {
        $this->resetPage('paymentsPage');
    }

    public function updatedHistoryDateFrom(): void
    {
        $this->resetPage('paymentsPage');
    }

    public function updatedHistoryDateTo(): void
    {
        $this->resetPage('paymentsPage');
    }

    public function updatedHistoryPerPage(): void
    {
        if (! in_array($this->historyPerPage, [10, 25, 50, 100], true)) {
            $this->historyPerPage = 10;
        }

        $this->resetPage('paymentsPage');
    }

    public function clearPayableFilters(): void
    {
        $this->payableSearch = '';
        $this->targetType = 'booking';
        $this->payablePerPage = 10;
        $this->clearSelection();
        $this->resetPage('payablesPage');
    }

    public function clearHistoryFilters(): void
    {
        $this->historySearch = '';
        $this->historyStatusFilter = '';
        $this->historyModeFilter = '';
        $this->historyTargetFilter = '';
        $this->historyDateFrom = '';
        $this->historyDateTo = '';
        $this->historySortField = 'date_paid';
        $this->historySortDirection = 'desc';
        $this->historyPerPage = 10;

        $this->resetPage('paymentsPage');
    }

    public bool $showPaymentModal = false;

    public function selectPayable(string $type, int $id): void
    {
        if (! in_array($type, ['booking', 'reservation', 'entrance_slip'], true)) {
            return;
        }

        $record = $this->findPayableRecord($type, $id);

        if ($record === null) {
            session()->flash(
                'error',
                'Selected payable record was not found or has no unpaid balance.',
            );

            return;
        }

        $this->targetType = $type;
        $this->selectedTargetId = $id;
        $this->selectedAmountDue = (string) $record->amount_due;
        $this->selectedTargetLabel = $this->payableLabel($type, $record);
        $this->amountPaid = $this->selectedAmountDue;
        $this->referenceNumber = '';
        $this->cashPaid = '';

        $this->resetValidation();
        $this->showPaymentModal = true;
    }

    public function clearSelection(): void
    {
        $this->showPaymentModal = false;
        $this->selectedTargetId = null;
        $this->selectedTargetLabel = '';
        $this->selectedAmountDue = '0.00';
        $this->amountPaid = '';
        $this->referenceNumber = '';
        $this->cashPaid = '';

        $this->resetValidation();
    }

    public function isCashMode(): bool
    {
        return strtolower(trim((string) ModeOfPayment::query()
            ->whereKey((int) $this->modeOfPaymentId)
            ->value('mode_of_payment'))) === 'cash';
    }

    public function updatedModeOfPaymentId(): void
    {
        // Cash received only belongs to a cash payment.
        $this->cashPaid = '';
    }

    public function recordPayment(PaymentWorkflowService $paymentWorkflow): void
    {
        $validated = $this->validate([
            'targetType' => [
                'required',
                Rule::in(['booking', 'reservation', 'entrance_slip']),
            ],
            'selectedTargetId' => ['required', 'integer', 'min:1'],
            'amountPaid' => ['required', 'numeric', 'gt:0'],
            'modeOfPaymentId' => [
                'required',
                'integer',
                'exists:tbl_mode_of_payment,mode_of_payment_id',
            ],
            'referenceNumber' => ['nullable', 'string', 'max:50'],
            'cashPaid' => [$this->isCashMode() ? 'required' : 'nullable', 'numeric', 'gte:amountPaid'],
        ], [
            'cashPaid.required' => 'Enter the cash received.',
            'cashPaid.numeric' => 'Cash paid must be a number.',
            'cashPaid.gte' => 'Cash paid must be at least the amount to pay.',
        ]);

        try {
            $payment = $paymentWorkflow->recordCashierPayment([
                'target_type' => $validated['targetType'],
                'target_id' => (int) $validated['selectedTargetId'],
                'amount_paid' => $validated['amountPaid'],
                'mode_of_payment_id' => (int) $validated['modeOfPaymentId'],
                'reference_number' => $validated['referenceNumber'] ?? null,
                'cash_paid' => $this->isCashMode() ? ($validated['cashPaid'] ?? null) : null,
                'user_id' => (int) Auth::id(),
            ]);

            $this->selectedPaymentId = (int) $payment->payment_id;
            $this->clearSelection();
            $this->resetPage('payablesPage');
            $this->resetPage('paymentsPage');

            session()->flash(
                'success',
                'Payment recorded successfully. The receipt is ready.',
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addError('payment', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('payment', 'The payment could not be recorded. Please refresh and try again.');
        }
    }

    public function viewReceipt(int $paymentId): void
    {
        if (! Payment::query()->whereKey($paymentId)->exists()) {
            session()->flash('error', 'Payment record not found.');

            return;
        }

        $this->selectedPaymentId = $paymentId;
    }

    public function clearReceipt(): void
    {
        $this->selectedPaymentId = null;
    }

    public function sortHistoryBy(string $field): void
    {
        $allowed = [
            'p_ref_no',
            'date_paid',
            'amount_paid',
            'payment_status',
        ];

        if (! in_array($field, $allowed, true)) {
            return;
        }

        if ($this->historySortField === $field) {
            $this->historySortDirection = $this->historySortDirection === 'asc'
                ? 'desc'
                : 'asc';
        } else {
            $this->historySortField = $field;
            $this->historySortDirection = 'asc';
        }

        $this->resetPage('paymentsPage');
    }

    public function sortIndicator(string $field): string
    {
        if ($this->historySortField !== $field) {
            return '↕';
        }

        return $this->historySortDirection === 'asc' ? '↑' : '↓';
    }

    public function paymentTargetLabel(?Payment $payment): string
    {
        if ($payment === null) {
            return 'Unknown transaction';
        }

        if ($payment->booking !== null) {
            return 'Booking '
                .$payment->booking->b_ref_no
                .' — '
                .$this->guestName($payment->booking->guest);
        }

        if ($payment->reservation !== null) {
            return 'Reservation '
                .$payment->reservation->r_ref_no
                .' — '
                .$this->guestName($payment->reservation->guest);
        }

        if ($payment->entranceSlip !== null) {
            return 'Entrance Slip #'
                .$payment->entranceSlip->entrance_slip_id
                .' — '
                .$this->guestName($payment->entranceSlip->guest, 'Walk-in guests');
        }

        return 'Unknown transaction';
    }

    public function payableTypeLabel(string $type): string
    {
        return match ($type) {
            'booking' => 'Booking',
            'reservation' => 'Reservation',
            'entrance_slip' => 'Entrance Slip',
            default => 'Unknown',
        };
    }

    public function payableLabel(string $type, mixed $record): string
    {
        if ($type === 'booking') {
            return 'Booking '
                .$record->b_ref_no
                .' — '
                .$this->guestName($record->guest);
        }

        if ($type === 'reservation') {
            return 'Reservation '
                .$record->r_ref_no
                .' — '
                .$this->guestName($record->guest);
        }

        return 'Entrance Slip #'
            .$record->entrance_slip_id
            .' — '
            .$this->guestName($record->guest, 'Walk-in guests');
    }

    public function paymentStatusColor(string $status): string
    {
        return match (strtolower($status)) {
            'verified', 'paid' => 'green',
            'pending' => 'amber',
            'rejected' => 'red',
            default => 'zinc',
        };
    }

    public function handledBy(Payment $payment): string
    {
        $staff = $payment->verifier ?? $payment->user;

        if ($staff === null) {
            return 'Guest submission';
        }

        return $staff->full_name
            ?? trim(implode(' ', array_filter([
                $staff->first_name,
                $staff->middle_name,
                $staff->last_name,
            ])))
            ?: $staff->username;
    }

    public function targetTypeOf(Payment $payment): string
    {
        if ($payment->booking_id !== null) {
            return 'Booking';
        }

        if ($payment->reservation_id !== null) {
            return 'Reservation';
        }

        if ($payment->entrance_slip_id !== null) {
            return 'Entrance Slip';
        }

        return 'Unknown';
    }

    private function guestName(mixed $guest, string $fallback = 'Unknown guest'): string
    {
        if ($guest === null) {
            return $fallback;
        }

        return $guest->full_name
            ?? trim(implode(' ', array_filter([
                $guest->first_name,
                $guest->middle_name,
                $guest->last_name,
            ])))
            ?: $fallback;
    }

    private function modeOfPayments(): Collection
    {
        return ModeOfPayment::query()
            ->orderBy('mode_of_payment')
            ->get();
    }

    private function payments(): LengthAwarePaginator
    {
        $query = Payment::query()
            ->select('tbl_payment.*')
            ->leftJoin(
                'tbl_booking as payment_booking',
                'payment_booking.booking_id',
                '=',
                'tbl_payment.booking_id',
            )
            ->leftJoin(
                'tbl_guest as booking_guest',
                'booking_guest.guest_id',
                '=',
                'payment_booking.guest_id',
            )
            ->leftJoin(
                'tbl_reservation as payment_reservation',
                'payment_reservation.reservation_id',
                '=',
                'tbl_payment.reservation_id',
            )
            ->leftJoin(
                'tbl_guest as reservation_guest',
                'reservation_guest.guest_id',
                '=',
                'payment_reservation.guest_id',
            )
            ->leftJoin(
                'tbl_entrance_slip as payment_entrance_slip',
                'payment_entrance_slip.entrance_slip_id',
                '=',
                'tbl_payment.entrance_slip_id',
            )
            ->leftJoin(
                'tbl_guest as entrance_guest',
                'entrance_guest.guest_id',
                '=',
                'payment_entrance_slip.guest_id',
            )
            ->with([
                'booking.guest',
                'reservation.guest',
                'entranceSlip.guest',
                'modeOfPayment',
                'user',
                'verifier',
            ]);

        $searchText = trim($this->historySearch);

        if ($searchText !== '') {
            $needle = '%'.$searchText.'%';
            $numeric = ctype_digit($searchText)
                ? (int) $searchText
                : null;

            $query->where(function ($query) use ($needle, $numeric): void {
                $query->where('tbl_payment.p_ref_no', 'like', $needle)
                    ->orWhere('tbl_payment.reference_number', 'like', $needle)
                    ->orWhere('payment_booking.b_ref_no', 'like', $needle)
                    ->orWhere('payment_reservation.r_ref_no', 'like', $needle)
                    ->orWhere('booking_guest.first_name', 'like', $needle)
                    ->orWhere('booking_guest.middle_name', 'like', $needle)
                    ->orWhere('booking_guest.last_name', 'like', $needle)
                    ->orWhereRaw(\App\Support\NameSearch::sql('booking_guest.first_name', 'booking_guest.middle_name', 'booking_guest.last_name'), \App\Support\NameSearch::bindings($needle))
                    ->orWhere('booking_guest.contact_no', 'like', $needle)
                    ->orWhere('booking_guest.email', 'like', $needle)
                    ->orWhere('reservation_guest.first_name', 'like', $needle)
                    ->orWhere('reservation_guest.middle_name', 'like', $needle)
                    ->orWhere('reservation_guest.last_name', 'like', $needle)
                    ->orWhereRaw(\App\Support\NameSearch::sql('reservation_guest.first_name', 'reservation_guest.middle_name', 'reservation_guest.last_name'), \App\Support\NameSearch::bindings($needle))
                    ->orWhere('reservation_guest.contact_no', 'like', $needle)
                    ->orWhere('reservation_guest.email', 'like', $needle)
                    ->orWhere('entrance_guest.first_name', 'like', $needle)
                    ->orWhere('entrance_guest.middle_name', 'like', $needle)
                    ->orWhere('entrance_guest.last_name', 'like', $needle)
                    ->orWhereRaw(\App\Support\NameSearch::sql('entrance_guest.first_name', 'entrance_guest.middle_name', 'entrance_guest.last_name'), \App\Support\NameSearch::bindings($needle))
                    ->orWhere('entrance_guest.contact_no', 'like', $needle)
                    ->orWhere('entrance_guest.email', 'like', $needle);

                if ($numeric !== null) {
                    $query->orWhere(
                        'payment_entrance_slip.entrance_slip_id',
                        $numeric,
                    );
                }
            });
        }

        if ($this->historyStatusFilter !== '') {
            $query->where(
                'tbl_payment.payment_status',
                $this->historyStatusFilter,
            );
        }

        if ($this->historyModeFilter !== '') {
            $query->where(
                'tbl_payment.mode_of_payment_id',
                $this->historyModeFilter,
            );
        }

        match ($this->historyTargetFilter) {
            'booking' => $query->whereNotNull('tbl_payment.booking_id'),
            'reservation' => $query->whereNotNull('tbl_payment.reservation_id'),
            'entrance_slip' => $query->whereNotNull('tbl_payment.entrance_slip_id'),
            default => null,
        };

        if ($this->historyDateFrom !== '') {
            $query->whereDate(
                'tbl_payment.date_paid',
                '>=',
                $this->historyDateFrom,
            );
        }

        if ($this->historyDateTo !== '') {
            $query->whereDate(
                'tbl_payment.date_paid',
                '<=',
                $this->historyDateTo,
            );
        }

        $sortMap = [
            'p_ref_no' => 'tbl_payment.p_ref_no',
            'date_paid' => 'tbl_payment.date_paid',
            'amount_paid' => 'tbl_payment.amount_paid',
            'payment_status' => 'tbl_payment.payment_status',
        ];

        $sortColumn = $sortMap[$this->historySortField]
            ?? 'tbl_payment.date_paid';

        $direction = $this->historySortDirection === 'asc'
            ? 'asc'
            : 'desc';

        $perPage = in_array($this->historyPerPage, [10, 25, 50, 100], true)
            ? $this->historyPerPage
            : 10;

        return $query
            ->orderBy($sortColumn, $direction)
            ->orderBy('tbl_payment.payment_id', 'desc')
            ->paginate($perPage, ['*'], 'paymentsPage');
    }

    private function payables(): LengthAwarePaginator
    {
        return match ($this->targetType) {
            'booking' => $this->payableBookings(),
            'reservation' => $this->payableReservations(),
            'entrance_slip' => $this->payableEntranceSlips(),
            default => $this->payableBookings(),
        };
    }

    private function payableBookings(): LengthAwarePaginator
    {
        $query = Booking::query()
            ->select('tbl_booking.*')
            ->join(
                'tbl_guest',
                'tbl_guest.guest_id',
                '=',
                'tbl_booking.guest_id',
            )
            ->with(['guest', 'details.facility'])
            ->where('tbl_booking.amount_due', '>', 0)
            ->whereNotIn(
                'tbl_booking.status',
                ['Cancelled', 'Payment Rejected', 'Checked-out'],
            );

        $searchText = trim($this->payableSearch);

        if ($searchText !== '') {
            $needle = '%'.$searchText.'%';

            $query->where(function ($query) use ($needle): void {
                $query->where('tbl_booking.b_ref_no', 'like', $needle)
                    ->orWhere('tbl_guest.first_name', 'like', $needle)
                    ->orWhere('tbl_guest.middle_name', 'like', $needle)
                    ->orWhere('tbl_guest.last_name', 'like', $needle)
                    ->orWhereRaw(\App\Support\NameSearch::sql('tbl_guest.first_name', 'tbl_guest.middle_name', 'tbl_guest.last_name'), \App\Support\NameSearch::bindings($needle))
                    ->orWhere('tbl_guest.contact_no', 'like', $needle)
                    ->orWhere('tbl_guest.email', 'like', $needle);
            });
        }

        return $query
            ->orderByDesc('tbl_booking.booking_id')
            ->paginate(
                $this->validPayablePerPage(),
                ['tbl_booking.*'],
                'payablesPage',
            );
    }

    private function payableReservations(): LengthAwarePaginator
    {
        $query = Reservation::query()
            ->select('tbl_reservation.*')
            ->join(
                'tbl_guest',
                'tbl_guest.guest_id',
                '=',
                'tbl_reservation.guest_id',
            )
            ->with(['guest', 'details.facility'])
            ->where('tbl_reservation.amount_due', '>', 0)
            ->whereNotIn(
                'tbl_reservation.status',
                ['Cancelled', 'Converted', 'No-show'],
            );

        $searchText = trim($this->payableSearch);

        if ($searchText !== '') {
            $needle = '%'.$searchText.'%';

            $query->where(function ($query) use ($needle): void {
                $query->where('tbl_reservation.r_ref_no', 'like', $needle)
                    ->orWhere('tbl_guest.first_name', 'like', $needle)
                    ->orWhere('tbl_guest.middle_name', 'like', $needle)
                    ->orWhere('tbl_guest.last_name', 'like', $needle)
                    ->orWhereRaw(\App\Support\NameSearch::sql('tbl_guest.first_name', 'tbl_guest.middle_name', 'tbl_guest.last_name'), \App\Support\NameSearch::bindings($needle))
                    ->orWhere('tbl_guest.contact_no', 'like', $needle)
                    ->orWhere('tbl_guest.email', 'like', $needle);
            });
        }

        return $query
            ->orderByDesc('tbl_reservation.reservation_id')
            ->paginate(
                $this->validPayablePerPage(),
                ['tbl_reservation.*'],
                'payablesPage',
            );
    }

    private function payableEntranceSlips(): LengthAwarePaginator
    {
        $query = EntranceSlip::query()
            ->select('tbl_entrance_slip.*')
            ->leftJoin(
                'tbl_guest',
                'tbl_guest.guest_id',
                '=',
                'tbl_entrance_slip.guest_id',
            )
            ->with('guest')
            ->where('tbl_entrance_slip.amount_due', '>', 0)
            ->where('tbl_entrance_slip.status', '!=', 'Paid');

        $searchText = trim($this->payableSearch);

        if ($searchText !== '') {
            $needle = '%'.$searchText.'%';
            $numeric = ctype_digit($searchText)
                ? (int) $searchText
                : null;

            $query->where(function ($query) use ($needle, $numeric): void {
                $query->where('tbl_guest.first_name', 'like', $needle)
                    ->orWhere('tbl_guest.middle_name', 'like', $needle)
                    ->orWhere('tbl_guest.last_name', 'like', $needle)
                    ->orWhereRaw(\App\Support\NameSearch::sql('tbl_guest.first_name', 'tbl_guest.middle_name', 'tbl_guest.last_name'), \App\Support\NameSearch::bindings($needle))
                    ->orWhere('tbl_guest.contact_no', 'like', $needle)
                    ->orWhere('tbl_guest.email', 'like', $needle);

                if ($numeric !== null) {
                    $query->orWhere(
                        'tbl_entrance_slip.entrance_slip_id',
                        $numeric,
                    );
                }
            });
        }

        return $query
            ->orderByDesc('tbl_entrance_slip.entrance_slip_id')
            ->paginate(
                $this->validPayablePerPage(),
                ['tbl_entrance_slip.*'],
                'payablesPage',
            );
    }

    private function validPayablePerPage(): int
    {
        return in_array($this->payablePerPage, [10, 25, 50, 100], true)
            ? $this->payablePerPage
            : 10;
    }

    private function findPayableRecord(string $type, int $id): mixed
    {
        if ($type === 'booking') {
            return Booking::query()
                ->with('guest')
                ->where('amount_due', '>', 0)
                ->whereNotIn(
                    'status',
                    ['Cancelled', 'Payment Rejected', 'Checked-out'],
                )
                ->find($id);
        }

        if ($type === 'reservation') {
            return Reservation::query()
                ->with('guest')
                ->where('amount_due', '>', 0)
                ->whereNotIn(
                    'status',
                    ['Cancelled', 'Converted', 'No-show'],
                )
                ->find($id);
        }

        return EntranceSlip::query()
            ->with('guest')
            ->where('amount_due', '>', 0)
            ->where('status', '!=', 'Paid')
            ->find($id);
    }

    private function selectedPayment(): ?Payment
    {
        if ($this->selectedPaymentId === null) {
            return null;
        }

        return Payment::query()
            ->with([
                'booking.guest',
                'reservation.guest',
                'entranceSlip.guest',
                'modeOfPayment',
                'user',
                'verifier',
            ])
            ->find($this->selectedPaymentId);
    }
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Payment Management</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Record cashier payments and review paginated payment history.
            </p>
        </div>

        @if (Route::has('cashier.dashboard'))
            <flux:button
                href="{{ route('cashier.dashboard') }}"
                wire:navigate
                variant="ghost"
            >
                Back to Dashboard
            </flux:button>
        @endif
    </div>

    <x-toast :message="session('success')" fresh />

    @if (session('error'))
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            {{ session('error') }}
        </div>
    @endif

    @if (! $showPaymentModal)
    @error('payment')
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            {{ $message }}
        </div>
    @enderror
    @endif

    @if ($selectedPayment !== null)
        <flux:card class="print:border-0 print:shadow-none">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold">Payment Receipt</h2>
                    <p class="text-sm text-zinc-500">
                        Receipt: <span class="font-medium">{{ $selectedPayment->p_ref_no }}</span>
                    </p>
                </div>

                <div class="flex flex-wrap gap-2 print:hidden">
                    @if (Route::has('print.payment'))
                        <flux:button
                            href="{{ route('print.payment', $selectedPayment) }}"
                            target="_blank"
                            variant="primary"
                        >
                            Print Receipt
                        </flux:button>
                    @else
                        <flux:button onclick="window.print()" variant="primary">
                            Print
                        </flux:button>
                    @endif

                    <flux:button wire:click="clearReceipt" variant="ghost">
                        Close
                    </flux:button>
                </div>
            </div>

            <dl class="mt-5 grid gap-4 text-sm md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <dt class="text-zinc-500">Transaction</dt>
                    <dd class="mt-1 font-medium">{{ $this->paymentTargetLabel($selectedPayment) }}</dd>
                </div>

                <div>
                    <dt class="text-zinc-500">Amount paid</dt>
                    <dd class="mt-1 font-medium">₱{{ number_format((float) $selectedPayment->amount_paid, 2) }}</dd>
                </div>

                <div>
                    <dt class="text-zinc-500">Mode</dt>
                    <dd class="mt-1 font-medium">{{ $selectedPayment->modeOfPayment?->mode_of_payment ?? 'N/A' }}</dd>
                </div>

                <div>
                    <dt class="text-zinc-500">Status</dt>
                    <dd class="mt-1">
                        <flux:badge
                            color="{{ $this->paymentStatusColor((string) $selectedPayment->payment_status) }}"
                            size="sm"
                        >
                            {{ $selectedPayment->payment_status }}
                        </flux:badge>
                    </dd>
                </div>

                <div>
                    <dt class="text-zinc-500">External reference</dt>
                    <dd class="mt-1 font-medium">{{ $selectedPayment->reference_number ?: 'N/A' }}</dd>
                </div>

                <div>
                    <dt class="text-zinc-500">Date paid</dt>
                    <dd class="mt-1 font-medium">{{ $selectedPayment->date_paid?->format('M d, Y') ?? 'N/A' }}</dd>
                </div>

                <div>
                    <dt class="text-zinc-500">Handled or verified by</dt>
                    <dd class="mt-1 font-medium">{{ $this->handledBy($selectedPayment) }}</dd>
                </div>

                <div>
                    <dt class="text-zinc-500">Target type</dt>
                    <dd class="mt-1 font-medium">{{ $this->targetTypeOf($selectedPayment) }}</dd>
                </div>
            </dl>
        </flux:card>
    @endif

    <flux:card class="overflow-hidden p-0">
        

            <div class="grid gap-3 border-b border-zinc-200 p-5 md:grid-cols-3 dark:border-zinc-800">
                <flux:input
                    wire:model.live.debounce.300ms="payableSearch"
                    label="Search unpaid records"
                    placeholder="Reference, guest, contact, or email"
                    clearable
                />

                <flux:field class="relative">
                    <flux:label>Payable type <span class="text-red-500">*</span></flux:label>
                    <flux:select wire:model.live="targetType" :invalid="$errors->has('targetType')">
                    <option value="booking">Bookings</option>
                    <option value="reservation">Reservations</option>
                    <option value="entrance_slip">Entrance Slips</option>
                </flux:select>
                    @if ($msg = $this->visibleError('targetType'))
                        <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                    @endif
                </flux:field>

                <div class="flex items-end gap-2">
                <flux:select
                    wire:model.live="payablePerPage"
                    label="Rows"
                >
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </flux:select>

                    <flux:button
                        wire:click="clearPayableFilters"
                        variant="ghost"
                    >
                        Clear Filters
                    </flux:button>
                </div>

            </div>
        

        <div class="overflow-x-auto">
            <table class="w-full min-w-[58rem] text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50">
                    <tr>
                        <th class="px-5 py-3">Type</th>
                        <th class="px-5 py-3">Reference and guest</th>
                        <th class="px-5 py-3">Facility or headcount</th>
                        <th class="px-5 py-3">Balance</th>
                        <th class="px-5 py-3 text-right">Action</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($payables as $payable)
                        @php($payableId = $targetType === 'booking' ? $payable->booking_id : ($targetType === 'reservation' ? $payable->reservation_id : $payable->entrance_slip_id))
                        <tr wire:key="payable-{{ $targetType }}-{{ $payableId }}" class="{{ $selectedTargetId === (int) $payableId && $this->targetType === $targetType ? 'bg-blue-50 ring-1 ring-inset ring-blue-200 dark:bg-blue-950/30 dark:ring-blue-800' : '' }}">
                            <td class="px-5 py-4">
                                {{ $this->payableTypeLabel($targetType) }}
                            </td>

                            <td class="px-5 py-4 font-medium">
                                {{ $this->payableLabel($targetType, $payable) }}
                            </td>

                            <td class="max-w-md px-5 py-4 text-zinc-600 dark:text-zinc-300">
                                @if ($targetType === 'booking' || $targetType === 'reservation')
                                    {{ $payable->details
                                        ->pluck('facility.facility_name')
                                        ->filter()
                                        ->implode(', ') ?: 'No facility' }}
                                @else
                                    Adult {{ $payable->no_of_adult }},
                                    Child {{ $payable->no_of_children }},
                                    Senior/PWD {{ $payable->no_of_PWD_SC }}
                                @endif
                            </td>

                            <td class="px-5 py-4 font-semibold">
                                ₱{{ number_format((float) $payable->amount_due, 2) }}
                            </td>

                            <td class="px-5 py-4 text-right">
                                <flux:button
                                    icon="banknotes"
                                    wire:click="selectPayable(
                                        '{{ $targetType }}',
                                        {{ $targetType === 'booking'
                                            ? $payable->booking_id
                                            : ($targetType === 'reservation'
                                                ? $payable->reservation_id
                                                : $payable->entrance_slip_id) }}
                                    )"
                                    size="sm"
                                    variant="primary"
                                >
                                    Pay
                                </flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-12 text-center text-zinc-500">
                                No unpaid {{ strtolower($this->payableTypeLabel($targetType)) }} records match the current filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex flex-col gap-3 border-t border-zinc-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
            <p class="text-sm text-zinc-500">
                Showing
                {{ $payables->firstItem() ?? 0 }}
                to
                {{ $payables->lastItem() ?? 0 }}
                of
                {{ $payables->total() }}
                unpaid records
            </p>

            {{ $payables->links() }}
        </div>
    </flux:card>

    <flux:card class="overflow-hidden p-0">
        


            <div class="grid gap-3 border-b border-zinc-200 p-5 md:grid-cols-4 dark:border-zinc-800">
                <flux:input
                    wire:model.live.debounce.300ms="historySearch"
                    label="Search history"
                    placeholder="Receipt, transaction, guest, or reference"
                    clearable
                />

                <flux:select
                    wire:model.live="historyStatusFilter"
                    label="Status"
                >
                    <option value="">All statuses</option>
                    <option value="Verified">Verified</option>
                    <option value="Pending">Pending</option>
                    <option value="Rejected">Rejected</option>
                    <option value="Cancelled">Cancelled</option>
                </flux:select>

                <flux:select
                    wire:model.live="historyModeFilter"
                    label="Mode"
                >
                    <option value="">All modes</option>
                    @foreach ($modeOfPayments as $mode)
                        <option value="{{ $mode->mode_of_payment_id }}">
                            {{ $mode->mode_of_payment }}
                        </option>
                    @endforeach
                </flux:select>

                <flux:select
                    wire:model.live="historyTargetFilter"
                    label="Target"
                >
                    <option value="">All targets</option>
                    <option value="booking">Booking</option>
                    <option value="reservation">Reservation</option>
                    <option value="entrance_slip">Entrance Slip</option>
                </flux:select>

                <flux:input
                    wire:model.live="historyDateFrom"
                    type="date"
                    label="From"
                />

                <flux:input
                    wire:model.live="historyDateTo"
                    type="date"
                    label="To"
                />
                <div class="flex items-end gap-2">
                <flux:select
                    wire:model.live="historyPerPage"
                    label="Rows"
                >
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </flux:select>


                    <flux:button
                        wire:click="clearHistoryFilters"
                        variant="ghost"
                    >
                        Clear Filters
                    </flux:button>
                </div>
            </div>
        

        <div class="overflow-x-auto">
            <table class="w-full min-w-[88rem] text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50">
                    <tr>
                        <th class="px-5 py-3">
                            <button
                                wire:click="sortHistoryBy('p_ref_no')"
                                class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                            >
                                RECEIPT {{ $this->sortIndicator('p_ref_no') }}
                            </button>
                        </th>
                        <th class="px-5 py-3">Transaction</th>
                        <th class="px-5 py-3">Target</th>
                        <th class="px-5 py-3">Mode</th>
                        <th class="px-5 py-3">External reference</th>
                        <th class="px-5 py-3">
                            <button
                                wire:click="sortHistoryBy('amount_paid')"
                                class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                            >
                                AMOUNT {{ $this->sortIndicator('amount_paid') }}
                            </button>
                        </th>
                        <th class="px-5 py-3">
                            <button
                                wire:click="sortHistoryBy('date_paid')"
                                class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                            >
                                DATE {{ $this->sortIndicator('date_paid') }}
                            </button>
                        </th>
                        <th class="px-5 py-3">
                            <button
                                wire:click="sortHistoryBy('payment_status')"
                                class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                            >
                                STATUS {{ $this->sortIndicator('payment_status') }}
                            </button>
                        </th>
                        <th class="px-5 py-3">Handled by</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($payments as $payment)
                        <tr wire:key="payment-history-{{ $payment->payment_id }}">
                            <td class="px-5 py-4 font-medium">
                                {{ $payment->p_ref_no }}
                            </td>

                            <td class="max-w-md px-5 py-4">
                                {{ $this->paymentTargetLabel($payment) }}
                            </td>

                            <td class="px-5 py-4">
                                {{ $this->targetTypeOf($payment) }}
                            </td>

                            <td class="px-5 py-4">
                                {{ $payment->modeOfPayment?->mode_of_payment ?? 'N/A' }}
                            </td>

                            <td class="px-5 py-4">
                                {{ $payment->reference_number ?: 'N/A' }}
                            </td>

                            <td class="px-5 py-4 font-semibold">
                                ₱{{ number_format((float) $payment->amount_paid, 2) }}
                            </td>

                            <td class="px-5 py-4">
                                {{ $payment->date_paid?->format('M d, Y') ?? 'N/A' }}
                            </td>

                            <td class="px-5 py-4">
                                <flux:badge
                                    color="{{ $this->paymentStatusColor((string) $payment->payment_status) }}"
                                    size="sm"
                                >
                                    {{ $payment->payment_status }}
                                </flux:badge>
                            </td>

                            <td class="px-5 py-4">
                                {{ $this->handledBy($payment) }}
                            </td>

                            <td class="px-5 py-4 text-right">
                                <div class="flex justify-end">
                                    <x-table-actions label="Actions for payment {{ $payment->p_ref_no }}">
                                        @if ($payment->booking_id && Route::has('cashier.bookings.show'))
                                            <flux:menu.item
                                                icon="eye"
                                                href="{{ route('cashier.bookings.show', $payment->booking_id) }}"
                                                wire:navigate
                                            >
                                                View booking
                                            </flux:menu.item>
                                        @endif

                                        <flux:menu.item
                                            icon="receipt-percent"
                                            wire:click="viewReceipt({{ $payment->payment_id }})"
                                        >
                                            View receipt
                                        </flux:menu.item>
                                    </x-table-actions>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-5 py-12 text-center text-zinc-500">
                                No payment record matches the selected filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex flex-col gap-3 border-t border-zinc-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
            <p class="text-sm text-zinc-500">
                Showing
                {{ $payments->firstItem() ?? 0 }}
                to
                {{ $payments->lastItem() ?? 0 }}
                of
                {{ $payments->total() }}
                payment records
            </p>

            {{ $payments->links() }}
        </div>
    </flux:card>
    <flux:modal wire:model="showPaymentModal" class="md:w-[32rem]">
        <form wire:submit.prevent="recordPayment" novalidate class="space-y-5">
            <div>
                <flux:heading size="lg">Record payment</flux:heading>
                <flux:text class="mt-1">{{ $selectedTargetLabel }}</flux:text>
                <flux:text class="mt-1">
                    Balance: <span class="font-semibold">₱{{ number_format((float) $selectedAmountDue, 2) }}</span>
                </flux:text>
            </div>

            @if ($breakdown && count($breakdown['lines']) > 0)
                <div class="rounded-xl border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Unpaid breakdown</p>
                    <div class="space-y-2">
                        @foreach ($breakdown['lines'] as $line)
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-medium">{{ $line['label'] }}</p>
                                    <p class="text-xs text-zinc-500">
                                        {{ $line['group'] }}@if ($line['detail']) · {{ $line['detail'] }}@endif
                                        @if ((float) $line['paid'] > 0) · ₱{{ number_format((float) $line['paid'], 2) }} of ₱{{ number_format((float) $line['amount'], 2) }} paid @endif
                                    </p>
                                </div>
                                <span class="whitespace-nowrap font-semibold">₱{{ number_format((float) $line['remaining'], 2) }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-3 flex justify-between border-t border-zinc-200 pt-2 font-semibold dark:border-zinc-700">
                        <span>Total unpaid</span>
                        <span>₱{{ number_format((float) $breakdown['balance'], 2) }}</span>
                    </div>
                </div>
            @endif

            @error('payment')
                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ $message }}</div>
            @enderror

            <flux:field class="relative">
                <flux:label>Amount to pay <span class="text-red-500">*</span></flux:label>
                <flux:input wire:model="amountPaid" type="number" min="0.01" step="0.01" :invalid="$errors->has('amountPaid')" />
                @if ($msg = $this->visibleError('amountPaid'))
                    <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                @endif
            </flux:field>

            <flux:field class="relative">
                <flux:label>Mode of payment <span class="text-red-500">*</span></flux:label>
                <flux:select wire:model.live="modeOfPaymentId" :invalid="$errors->has('modeOfPaymentId')">
                    @foreach ($modeOfPayments as $mode)
                        <option value="{{ $mode->mode_of_payment_id }}">
                            {{ $mode->mode_of_payment }}
                        </option>
                    @endforeach
                </flux:select>
                @if ($msg = $this->visibleError('modeOfPaymentId'))
                    <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                @endif
            </flux:field>

            @if ($this->modeOfPaymentId === '2')
                <flux:input
                    wire:model="referenceNumber"
                    label="GCash reference number"
                    placeholder="Required for GCash"
                />
            @endif

            @error('referenceNumber')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror

            @if ($this->isCashMode())
                <flux:field class="relative">
                    <flux:label>Cash paid <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model.live.debounce.300ms="cashPaid" type="number" min="0" step="0.01" :invalid="$errors->has('cashPaid')" />
                    @if ($msg = $this->visibleError('cashPaid'))
                        <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                    @endif
                </flux:field>
                <div class="flex justify-between rounded-xl bg-zinc-50 px-4 py-3 text-sm font-semibold dark:bg-zinc-900">
                    <span>Change</span>
                    <span>₱{{ number_format(is_numeric($cashPaid) && is_numeric($amountPaid) ? max(0, (float) $cashPaid - (float) $amountPaid) : 0, 2) }}</span>
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="clearSelection">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Record Payment</flux:button>
            </div>
        </form>
    </flux:modal>
</div>