<?php

use App\Models\Booking;
use App\Models\EntranceSlip;
use App\Models\ModeOfPayment;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\PaymentWorkflowService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Payment Management - Olaer Spring Resort')] class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $targetType = 'booking';
    public string $historySortField = 'date_paid';
    public string $historySortDirection = 'desc';

    public ?int $selectedTargetId = null;
    public string $selectedTargetLabel = '';
    public float $selectedAmountDue = 0.00;

    public string $amountPaid = '';
    public string $modeOfPaymentId = '';
    public string $referenceNumber = '';

    public ?int $selectedPaymentId = null;

    public function mount(): void
    {
        $firstMode = ModeOfPayment::query()->orderBy('mode_of_payment_id')->first();

        if ($firstMode !== null) {
            $this->modeOfPaymentId = (string) $firstMode->mode_of_payment_id;
        }
    }

    public function with(): array
    {
        return [
            'payables' => $this->payables(),
            'payments' => $this->payments(),
            'modeOfPayments' => $this->modeOfPayments(),
            'selectedPayment' => $this->selectedPayment(),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTargetType(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    public function selectPayable(string $type, int $id): void
    {
        if (! in_array($type, ['booking', 'reservation', 'entrance_slip'], true)) {
            return;
        }

        $record = $this->findPayableRecord($type, $id);

        if ($record === null) {
            session()->flash('error', 'Selected payable record was not found or has no unpaid balance.');
            return;
        }

        $this->targetType = $type;
        $this->selectedTargetId = $id;
        $this->selectedAmountDue = round((float) $record->amount_due, 2);
        $this->selectedTargetLabel = $this->payableLabel($type, $record);
        $this->amountPaid = number_format($this->selectedAmountDue, 2, '.', '');
        $this->referenceNumber = '';
        $this->resetValidation();
    }

    public function clearSelection(): void
    {
        $this->selectedTargetId = null;
        $this->selectedTargetLabel = '';
        $this->selectedAmountDue = 0.00;
        $this->amountPaid = '';
        $this->referenceNumber = '';
        $this->resetValidation();
    }

    public function recordPayment(PaymentWorkflowService $paymentWorkflow): void
    {
        $validated = $this->validate([
            'targetType' => ['required', Rule::in(['booking', 'reservation', 'entrance_slip'])],
            'selectedTargetId' => ['required', 'integer', 'min:1'],
            'amountPaid' => ['required', 'numeric', 'gt:0'],
            'modeOfPaymentId' => ['required', 'integer', 'exists:tbl_mode_of_payment,mode_of_payment_id'],
            'referenceNumber' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $payment = $paymentWorkflow->recordCashierPayment([
                'target_type' => $validated['targetType'],
                'target_id' => (int) $validated['selectedTargetId'],
                'amount_paid' => (float) $validated['amountPaid'],
                'mode_of_payment_id' => (int) $validated['modeOfPaymentId'],
                'reference_number' => $validated['referenceNumber'] ?? null,
                'user_id' => (int) Auth::id(),
            ]);

            $this->selectedPaymentId = (int) $payment->payment_id;
            $this->clearSelection();
            $this->resetPage();
            session()->flash('success', 'Payment recorded successfully. Receipt is ready for viewing/printing.');
        } catch (\Throwable $exception) {
            $this->addError('payment', $exception->getMessage());
        }
    }

    public function viewReceipt(int $paymentId): void
    {
        $payment = Payment::query()->find($paymentId);

        if ($payment === null) {
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
        $allowed = ['p_ref_no', 'date_paid', 'amount_paid', 'payment_status'];

        if (! in_array($field, $allowed, true)) {
            return;
        }

        if ($this->historySortField === $field) {
            $this->historySortDirection = $this->historySortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->historySortField = $field;
            $this->historySortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function sortIndicator(string $field): string
    {
        if ($this->historySortField !== $field) {
            return '';
        }

        return $this->historySortDirection === 'asc' ? '↑' : '↓';
    }

    public function paymentTargetLabel(?Payment $payment): string
    {
        if ($payment === null) {
            return 'Unknown';
        }

        if ($payment->booking !== null) {
            $guest = $payment->booking->guest;
            return 'Booking ' . $payment->booking->b_ref_no . ' - ' . trim($guest?->first_name . ' ' . $guest?->last_name);
        }

        if ($payment->reservation !== null) {
            $guest = $payment->reservation->guest;
            return 'Reservation ' . $payment->reservation->r_ref_no . ' - ' . trim($guest?->first_name . ' ' . $guest?->last_name);
        }

        if ($payment->entranceSlip !== null) {
            $guest = $payment->entranceSlip->guest;
            $guestName = $guest !== null ? trim($guest->first_name . ' ' . $guest->last_name) : 'Walk-in guests';
            return 'Entrance Slip #' . $payment->entranceSlip->entrance_slip_id . ' - ' . $guestName;
        }

        return 'Unknown target';
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
            $guest = $record->guest;
            return 'Booking ' . $record->b_ref_no . ' - ' . trim($guest?->first_name . ' ' . $guest?->last_name);
        }

        if ($type === 'reservation') {
            $guest = $record->guest;
            return 'Reservation ' . $record->r_ref_no . ' - ' . trim($guest?->first_name . ' ' . $guest?->last_name);
        }

        $guest = $record->guest;
        $guestName = $guest !== null ? trim($guest->first_name . ' ' . $guest->last_name) : 'Walk-in guests';
        return 'Entrance Slip #' . $record->entrance_slip_id . ' - ' . $guestName;
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
            ->leftJoin('tbl_booking as payment_booking', 'payment_booking.booking_id', '=', 'tbl_payment.booking_id')
            ->leftJoin('tbl_guest as booking_guest', 'booking_guest.guest_id', '=', 'payment_booking.guest_id')
            ->leftJoin('tbl_reservation as payment_reservation', 'payment_reservation.reservation_id', '=', 'tbl_payment.reservation_id')
            ->leftJoin('tbl_guest as reservation_guest', 'reservation_guest.guest_id', '=', 'payment_reservation.guest_id')
            ->leftJoin('tbl_entrance_slip as payment_entrance_slip', 'payment_entrance_slip.entrance_slip_id', '=', 'tbl_payment.entrance_slip_id')
            ->leftJoin('tbl_guest as entrance_guest', 'entrance_guest.guest_id', '=', 'payment_entrance_slip.guest_id')
            ->with(['booking.guest', 'reservation.guest', 'entranceSlip.guest', 'modeOfPayment', 'user']);

        $searchText = trim($this->search);

        if ($searchText !== '') {
            $needle = '%' . $searchText . '%';

            $query->whereRaw(
                '(tbl_payment.p_ref_no LIKE ? OR tbl_payment.reference_number LIKE ? OR payment_booking.b_ref_no LIKE ? OR payment_reservation.r_ref_no LIKE ? OR booking_guest.first_name LIKE ? OR booking_guest.last_name LIKE ? OR reservation_guest.first_name LIKE ? OR reservation_guest.last_name LIKE ? OR entrance_guest.first_name LIKE ? OR entrance_guest.last_name LIKE ?)',
                [$needle, $needle, $needle, $needle, $needle, $needle, $needle, $needle, $needle, $needle]
            );
        }

        $sortMap = [
            'p_ref_no' => 'tbl_payment.p_ref_no',
            'date_paid' => 'tbl_payment.date_paid',
            'amount_paid' => 'tbl_payment.amount_paid',
            'payment_status' => 'tbl_payment.payment_status',
        ];

        $sortColumn = $sortMap[$this->historySortField] ?? 'tbl_payment.date_paid';
        $direction = $this->historySortDirection === 'asc' ? 'asc' : 'desc';

        return $query
            ->orderBy($sortColumn, $direction)
            ->orderBy('tbl_payment.payment_id', 'desc')
            ->paginate(10, ['*'], 'paymentsPage');
    }

    private function payables(): Collection
    {
        return match ($this->targetType) {
            'booking' => $this->payableBookings(),
            'reservation' => $this->payableReservations(),
            'entrance_slip' => $this->payableEntranceSlips(),
            default => collect(),
        };
    }

    private function payableBookings(): Collection
    {
        $query = Booking::query()
            ->select('tbl_booking.*')
            ->join('tbl_guest', 'tbl_guest.guest_id', '=', 'tbl_booking.guest_id')
            ->with(['guest', 'details.facility'])
            ->where('tbl_booking.amount_due', '>', 0)
            ->whereNotIn('tbl_booking.status', ['Cancelled', 'Checked-out']);

        $searchText = trim($this->search);

        if ($searchText !== '') {
            $needle = '%' . $searchText . '%';

            $query->whereRaw(
                '(tbl_booking.b_ref_no LIKE ? OR tbl_guest.first_name LIKE ? OR tbl_guest.last_name LIKE ? OR tbl_guest.contact_no LIKE ?)',
                [$needle, $needle, $needle, $needle]
            );
        }

        return $query
            ->orderByDesc('tbl_booking.booking_id')
            ->limit(50)
            ->get();
    }

    private function payableReservations(): Collection
    {
        $query = Reservation::query()
            ->select('tbl_reservation.*')
            ->join('tbl_guest', 'tbl_guest.guest_id', '=', 'tbl_reservation.guest_id')
            ->with(['guest', 'details.facility'])
            ->where('tbl_reservation.amount_due', '>', 0)
            ->whereNotIn('tbl_reservation.status', ['Cancelled', 'Converted', 'No-show']);

        $searchText = trim($this->search);

        if ($searchText !== '') {
            $needle = '%' . $searchText . '%';

            $query->whereRaw(
                '(tbl_reservation.r_ref_no LIKE ? OR tbl_guest.first_name LIKE ? OR tbl_guest.last_name LIKE ? OR tbl_guest.contact_no LIKE ?)',
                [$needle, $needle, $needle, $needle]
            );
        }

        return $query
            ->orderByDesc('tbl_reservation.reservation_id')
            ->limit(50)
            ->get();
    }

    private function payableEntranceSlips(): Collection
    {
        $query = EntranceSlip::query()
            ->select('tbl_entrance_slip.*')
            ->leftJoin('tbl_guest', 'tbl_guest.guest_id', '=', 'tbl_entrance_slip.guest_id')
            ->with(['guest'])
            ->where('tbl_entrance_slip.amount_due', '>', 0)
            ->where('tbl_entrance_slip.status', '!=', 'Paid');

        $searchText = trim($this->search);

        if ($searchText !== '') {
            $needle = '%' . $searchText . '%';
            $numeric = ctype_digit($searchText) ? (int) $searchText : -1;

            $query->whereRaw(
                '(tbl_entrance_slip.entrance_slip_id = ? OR tbl_guest.first_name LIKE ? OR tbl_guest.last_name LIKE ? OR tbl_guest.contact_no LIKE ?)',
                [$numeric, $needle, $needle, $needle]
            );
        }

        return $query
            ->orderByDesc('tbl_entrance_slip.entrance_slip_id')
            ->limit(50)
            ->get();
    }

    private function findPayableRecord(string $type, int $id): mixed
    {
        if ($type === 'booking') {
            return Booking::query()->with('guest')->where('amount_due', '>', 0)->find($id);
        }

        if ($type === 'reservation') {
            return Reservation::query()->with('guest')->where('amount_due', '>', 0)->find($id);
        }

        return EntranceSlip::query()->with('guest')->where('amount_due', '>', 0)->find($id);
    }

    private function selectedPayment(): ?Payment
    {
        if ($this->selectedPaymentId === null) {
            return null;
        }

        return Payment::query()
            ->with(['booking.guest', 'reservation.guest', 'entranceSlip.guest', 'modeOfPayment', 'user'])
            ->find($this->selectedPaymentId);
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">Cashier Payment Management</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">Record verified cashier payments for bookings, reservations, and entrance slips.</p>
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            {{ session('error') }}
        </div>
    @endif

    @error('payment')
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            {{ $message }}
        </div>
    @enderror

    @if ($selectedPayment !== null)
        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-950 print:border-0 print:shadow-none">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Payment Receipt</h2>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">Receipt No: <span class="font-medium">{{ $selectedPayment->p_ref_no }}</span></p>
                </div>
                <div class="flex gap-2 print:hidden">
                    <flux:button onclick="window.print()">Print</flux:button>
                    <flux:button variant="ghost" wire:click="clearReceipt">Close</flux:button>
                </div>
            </div>

            <div class="mt-4 grid gap-3 text-sm md:grid-cols-2">
                <div>
                    <p class="text-zinc-500 dark:text-zinc-400">Transaction</p>
                    <p class="font-medium text-zinc-900 dark:text-zinc-100">{{ $this->paymentTargetLabel($selectedPayment) }}</p>
                </div>
                <div>
                    <p class="text-zinc-500 dark:text-zinc-400">Amount Paid</p>
                    <p class="font-medium text-zinc-900 dark:text-zinc-100">₱{{ number_format((float) $selectedPayment->amount_paid, 2) }}</p>
                </div>
                <div>
                    <p class="text-zinc-500 dark:text-zinc-400">Mode of Payment</p>
                    <p class="font-medium text-zinc-900 dark:text-zinc-100">{{ $selectedPayment->modeOfPayment?->mode_of_payment ?? 'N/A' }}</p>
                </div>
                <div>
                    <p class="text-zinc-500 dark:text-zinc-400">Reference Number</p>
                    <p class="font-medium text-zinc-900 dark:text-zinc-100">{{ $selectedPayment->reference_number ?: 'N/A' }}</p>
                </div>
                <div>
                    <p class="text-zinc-500 dark:text-zinc-400">Date Paid</p>
                    <p class="font-medium text-zinc-900 dark:text-zinc-100">{{ $selectedPayment->date_paid?->format('M d, Y') }}</p>
                </div>
                <div>
                    <p class="text-zinc-500 dark:text-zinc-400">Handled By</p>
                    <p class="font-medium text-zinc-900 dark:text-zinc-100">{{ $selectedPayment->user?->first_name }} {{ $selectedPayment->user?->last_name }}</p>
                </div>
            </div>
        </div>
    @endif

    @if ($selectedTargetId !== null)
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-sm font-medium text-blue-900 dark:text-blue-100">Selected payable</p>
                    <p class="text-sm text-blue-800 dark:text-blue-200">{{ $selectedTargetLabel }}</p>
                    <p class="mt-1 text-sm text-blue-800 dark:text-blue-200">Balance: <span class="font-semibold">₱{{ number_format($selectedAmountDue, 2) }}</span></p>
                </div>
                <flux:button variant="ghost" wire:click="clearSelection">Cancel</flux:button>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-4">
                <flux:input label="Amount paid" type="number" min="0.01" step="0.01" wire:model="amountPaid" />
                <flux:select label="Mode of payment" wire:model="modeOfPaymentId">
                    @foreach ($modeOfPayments as $mode)
                        <option value="{{ $mode->mode_of_payment_id }}">{{ $mode->mode_of_payment }}</option>
                    @endforeach
                </flux:select>
                <flux:input label="GCash reference no." placeholder="Required for GCash" wire:model="referenceNumber" />
                <div class="flex items-end">
                    <flux:button variant="primary" wire:click="recordPayment" class="w-full">Record Payment</flux:button>
                </div>
            </div>

            @error('amountPaid') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            @error('modeOfPaymentId') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            @error('referenceNumber') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
    @endif

    <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
        <div class="grid gap-3 md:grid-cols-3">
            <flux:input label="Search" placeholder="Guest, reference, contact..." wire:model.live.debounce.300ms="search" />
            <flux:select label="Payable type" wire:model.live="targetType">
                <option value="booking">Bookings</option>
                <option value="reservation">Reservations</option>
                <option value="entrance_slip">Entrance Slips</option>
            </flux:select>
        </div>

        <div class="mt-4 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-3 py-2 text-left">Type</th>
                        <th class="px-3 py-2 text-left">Reference / Guest</th>
                        <th class="px-3 py-2 text-left">Facility / Details</th>
                        <th class="px-3 py-2 text-left">Balance</th>
                        <th class="px-3 py-2 text-left">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($payables as $payable)
                        <tr>
                            <td class="px-3 py-2">{{ $this->payableTypeLabel($targetType) }}</td>
                            <td class="px-3 py-2 font-medium">{{ $this->payableLabel($targetType, $payable) }}</td>
                            <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">
                                @if ($targetType === 'booking')
                                    {{ $payable->details->pluck('facility.facility_name')->filter()->implode(', ') ?: 'No facility' }}
                                @elseif ($targetType === 'reservation')
                                    {{ $payable->details->pluck('facility.facility_name')->filter()->implode(', ') ?: 'No facility' }}
                                @else
                                    Adult: {{ $payable->no_of_adult }}, Child: {{ $payable->no_of_children }}, Senior/PWD: {{ $payable->no_of_PWD_SC }}
                                @endif
                            </td>
                            <td class="px-3 py-2">₱{{ number_format((float) $payable->amount_due, 2) }}</td>
                            <td class="px-3 py-2">
                                <flux:button size="sm" wire:click="selectPayable('{{ $targetType }}', {{ $targetType === 'booking' ? $payable->booking_id : ($targetType === 'reservation' ? $payable->reservation_id : $payable->entrance_slip_id) }})">Pay</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-8 text-center text-zinc-500">No unpaid {{ strtolower($this->payableTypeLabel($targetType)) }} records found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
        <div class="mb-4">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Payment History</h2>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">Verified cashier payments and recorded GCash reference numbers.</p>
        </div>

        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-3 py-2 text-left"><button wire:click="sortHistoryBy('p_ref_no')">Receipt {{ $this->sortIndicator('p_ref_no') }}</button></th>
                        <th class="px-3 py-2 text-left">Transaction</th>
                        <th class="px-3 py-2 text-left">Mode</th>
                        <th class="px-3 py-2 text-left">Reference</th>
                        <th class="px-3 py-2 text-left"><button wire:click="sortHistoryBy('amount_paid')">Amount {{ $this->sortIndicator('amount_paid') }}</button></th>
                        <th class="px-3 py-2 text-left"><button wire:click="sortHistoryBy('date_paid')">Date {{ $this->sortIndicator('date_paid') }}</button></th>
                        <th class="px-3 py-2 text-left">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($payments as $payment)
                        <tr>
                            <td class="px-3 py-2 font-medium">{{ $payment->p_ref_no }}</td>
                            <td class="px-3 py-2">{{ $this->paymentTargetLabel($payment) }}</td>
                            <td class="px-3 py-2">{{ $payment->modeOfPayment?->mode_of_payment ?? 'N/A' }}</td>
                            <td class="px-3 py-2">{{ $payment->reference_number ?: 'N/A' }}</td>
                            <td class="px-3 py-2">₱{{ number_format((float) $payment->amount_paid, 2) }}</td>
                            <td class="px-3 py-2">{{ $payment->date_paid?->format('M d, Y') }}</td>
                            <td class="px-3 py-2">
                                <flux:button size="sm" wire:click="viewReceipt({{ $payment->payment_id }})">Receipt</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-8 text-center text-zinc-500">No payment records found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $payments->links() }}
        </div>
    </div>
</div>
