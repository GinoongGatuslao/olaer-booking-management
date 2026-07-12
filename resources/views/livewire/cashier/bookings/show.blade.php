<?php

use App\Models\Booking;
use App\Models\GuestFine;
use App\Services\BookingWorkspaceService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Booking Details - Olaer Spring Resort')] class extends Component
{
    public int $bookingId;

    public function mount(int $booking): void
    {
        $this->bookingId = $booking;
    }

    #[Computed]
    public function booking(): Booking
    {
        return app(BookingWorkspaceService::class)
            ->findBooking($this->bookingId);
    }

    #[Computed]
    public function inspectionRequests()
    {
        return app(BookingWorkspaceService::class)
            ->inspectionRequests($this->bookingId);
    }

    #[Computed]
    public function summary(): array
    {
        return app(BookingWorkspaceService::class)
            ->summary($this->booking);
    }

    #[Computed]
    public function actions(): array
    {
        return app(BookingWorkspaceService::class)
            ->actions($this->booking);
    }

    public function bookingStatusColor(string $status): string
    {
        return app(BookingWorkspaceService::class)->bookingStatusColor($status);
    }

    public function detailStatusColor(string $status): string
    {
        return app(BookingWorkspaceService::class)->detailStatusColor($status);
    }

    public function paymentStatusColor(string $status): string
    {
        return app(BookingWorkspaceService::class)->paymentStatusColor($status);
    }

    public function requestStatusColor(string $status): string
    {
        return app(BookingWorkspaceService::class)->requestStatusColor($status);
    }

    public function guestName(): string
    {
        return app(BookingWorkspaceService::class)->guestName($this->booking);
    }

    public function guestAddress(): string
    {
        return app(BookingWorkspaceService::class)->guestAddress($this->booking);
    }

    public function amenitySummary($request): string
    {
        return app(BookingWorkspaceService::class)->amenitySummary($request);
    }

    public function fineDescription(GuestFine $guestFine): string
    {
        return app(BookingWorkspaceService::class)->fineDescription($guestFine);
    }
};

?>

<div wire:poll.15s class="space-y-6">
    <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <p class="text-sm font-medium text-zinc-500">Booking workspace</p>

                <flux:badge color="{{ $this->bookingStatusColor((string) $this->booking->status) }}">
                    {{ $this->booking->status }}
                </flux:badge>
            </div>

            <h1 class="mt-1 text-2xl font-bold tracking-tight">
                {{ $this->booking->b_ref_no }}
            </h1>

            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ $this->guestName() }} · Created {{ optional($this->booking->booking_date)->format('M d, Y') }}
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            @if (Route::has('cashier.bookings.index'))
                <flux:button
                    href="{{ route('cashier.bookings.index') }}"
                    wire:navigate
                    variant="ghost"
                >
                    Back to Bookings
                </flux:button>
            @endif

            @if (Route::has('print.booking'))
                <flux:button
                    href="{{ route('print.booking', $this->booking) }}"
                    target="_blank"
                    variant="ghost"
                >
                    Print Booking
                </flux:button>
            @endif

            @if (Route::has('print.billing'))
                <flux:button
                    href="{{ route('print.billing', $this->booking) }}"
                    target="_blank"
                    variant="primary"
                >
                    Print Billing
                </flux:button>
            @endif
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <flux:card>
            <p class="text-sm text-zinc-500">Total price</p>
            <p class="mt-2 text-2xl font-semibold">₱{{ number_format($this->summary['total_price'], 2) }}</p>
        </flux:card>

        <flux:card>
            <p class="text-sm text-zinc-500">Verified payments</p>
            <p class="mt-2 text-2xl font-semibold">₱{{ number_format($this->summary['total_paid'], 2) }}</p>
        </flux:card>

        <flux:card>
            <p class="text-sm text-zinc-500">Amount due</p>
            <p class="mt-2 text-2xl font-semibold {{ $this->summary['amount_due'] > 0 ? 'text-red-600' : 'text-green-600' }}">
                ₱{{ number_format($this->summary['amount_due'], 2) }}
            </p>
        </flux:card>

        <flux:card>
            <p class="text-sm text-zinc-500">Facilities</p>
            <p class="mt-2 text-2xl font-semibold">{{ $this->summary['facility_count'] }}</p>
        </flux:card>

        <flux:card>
            <p class="text-sm text-zinc-500">Extra guests</p>
            <p class="mt-2 text-2xl font-semibold">{{ $this->summary['extra_guest_count'] }}</p>
        </flux:card>
    </div>

    <flux:card>
        <div class="mb-4">
            <h2 class="text-lg font-semibold">Available actions</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                Actions appear only when the booking's current status and balance allow them.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($this->actions['can_record_payment'] && Route::has('cashier.payments.index'))
                <flux:button
                    href="{{ route('cashier.payments.index', ['booking' => $this->bookingId]) }}"
                    wire:navigate
                    variant="primary"
                >
                    Record Payment
                </flux:button>
            @endif

            @if (Route::has('cashier.billings.index'))
                <flux:button
                    href="{{ route('cashier.billings.index', ['booking' => $this->bookingId]) }}"
                    wire:navigate
                    variant="ghost"
                >
                    View Billing Module
                </flux:button>
            @endif

            @if ($this->actions['can_check_in'] && Route::has('cashier.check-ins.index'))
                <flux:button
                    href="{{ route('cashier.check-ins.index', ['booking' => $this->bookingId]) }}"
                    wire:navigate
                    variant="ghost"
                >
                    Go to Check-in
                </flux:button>
            @endif

            @if ($this->actions['can_request_amenity'] && Route::has('cashier.amenity-requests.index'))
                <flux:button
                    href="{{ route('cashier.amenity-requests.index', ['booking' => $this->bookingId]) }}"
                    wire:navigate
                    variant="ghost"
                >
                    Request Amenity
                </flux:button>
            @endif

            @if ($this->actions['can_check_out'] && Route::has('cashier.check-outs.index'))
                <flux:button
                    href="{{ route('cashier.check-outs.index', ['booking' => $this->bookingId]) }}"
                    wire:navigate
                    variant="ghost"
                >
                    Go to Check-out
                </flux:button>
            @endif
        </div>

        <p class="mt-3 text-xs text-zinc-500">
            The booking ID is passed to the destination page in the URL. Each target module can progressively add automatic preselection without changing this workspace.
        </p>
    </flux:card>

    <div class="grid gap-6 xl:grid-cols-3">
        <flux:card class="xl:col-span-2">
            <div class="mb-4">
                <h2 class="text-lg font-semibold">Guest and booking information</h2>
            </div>

            <dl class="grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Guest</dt>
                    <dd class="mt-1 font-medium">{{ $this->guestName() }}</dd>
                </div>

                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Contact</dt>
                    <dd class="mt-1">{{ $this->booking->guest?->contact_no ?? 'Not recorded' }}</dd>
                </div>

                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Email</dt>
                    <dd class="mt-1">{{ $this->booking->guest?->email ?? 'Not recorded' }}</dd>
                </div>

                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Address</dt>
                    <dd class="mt-1">{{ $this->guestAddress() }}</dd>
                </div>

                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Created by</dt>
                    <dd class="mt-1">{{ $this->booking->user?->full_name ?? $this->booking->user?->username ?? 'Guest online booking' }}</dd>
                </div>

                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Source reservation</dt>
                    <dd class="mt-1">{{ $this->booking->reservation?->r_ref_no ?? 'Direct booking' }}</dd>
                </div>
            </dl>
        </flux:card>

        <flux:card>
            <div class="mb-4">
                <h2 class="text-lg font-semibold">Booking counters</h2>
            </div>

            <dl class="space-y-3 text-sm">
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-zinc-500">Amenity requests</dt>
                    <dd class="font-semibold">{{ $this->summary['amenity_request_count'] }}</dd>
                </div>

                <div class="flex items-center justify-between gap-3">
                    <dt class="text-zinc-500">Recorded fines</dt>
                    <dd class="font-semibold">{{ $this->summary['fine_count'] }}</dd>
                </div>

                <div class="flex items-center justify-between gap-3">
                    <dt class="text-zinc-500">Payment records</dt>
                    <dd class="font-semibold">{{ $this->booking->payments->count() }}</dd>
                </div>

                <div class="flex items-center justify-between gap-3">
                    <dt class="text-zinc-500">Inspection requests</dt>
                    <dd class="font-semibold">{{ $this->inspectionRequests->count() }}</dd>
                </div>
            </dl>
        </flux:card>
    </div>

    <flux:card class="overflow-hidden p-0">
        <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
            <h2 class="text-lg font-semibold">Facilities</h2>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[64rem] text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50">
                    <tr>
                        <th class="px-5 py-3">Facility</th>
                        <th class="px-5 py-3">Rate</th>
                        <th class="px-5 py-3">Schedule</th>
                        <th class="px-5 py-3">Discount</th>
                        <th class="px-5 py-3">Line total</th>
                        <th class="px-5 py-3">Status</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($this->booking->details as $detail)
                        <tr>
                            <td class="px-5 py-4">
                                <p class="font-medium">{{ $detail->facility?->facility_name ?? 'Unavailable facility' }}</p>
                                <p class="text-xs text-zinc-500">{{ $detail->facility?->facilityType?->facility_type ?? 'Unknown type' }}</p>
                            </td>

                            <td class="px-5 py-4">{{ $detail->rate_type }}</td>

                            <td class="px-5 py-4">
                                {{ optional($detail->check_in_date)->format('M d, Y') }}
                                <span class="text-zinc-400">→</span>
                                {{ optional($detail->check_out_date)->format('M d, Y') }}
                            </td>

                            <td class="px-5 py-4">
                                {{ $detail->discount?->discount_name ?? 'None' }}
                                @if ((float) ($detail->discount_amount ?? 0) > 0)
                                    <p class="text-xs text-zinc-500">−₱{{ number_format((float) $detail->discount_amount, 2) }}</p>
                                @endif
                            </td>

                            <td class="px-5 py-4 font-medium">
                                @if ($detail->line_total !== null)
                                    ₱{{ number_format((float) $detail->line_total, 2) }}
                                @else
                                    Included in booking total
                                @endif
                            </td>

                            <td class="px-5 py-4">
                                <flux:badge color="{{ $this->detailStatusColor((string) $detail->status) }}" size="sm">
                                    {{ $detail->status }}
                                </flux:badge>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-zinc-500">
                                This booking has no facility details.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>

    <div class="grid gap-6 xl:grid-cols-2">
        <flux:card>
            <div class="mb-4">
                <h2 class="text-lg font-semibold">Amenity requests</h2>
            </div>

            <div class="space-y-3">
                @forelse ($this->booking->amenityRequests->sortByDesc('amenity_request_id') as $request)
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="font-medium">Request #{{ $request->amenity_request_id }}</p>
                                <p class="mt-1 text-xs leading-5 text-zinc-500">{{ $this->amenitySummary($request) }}</p>
                                <p class="mt-1 text-xs text-zinc-500">
                                    Total: ₱{{ number_format((float) $request->total_price, 2) }}
                                    @if ($request->assignedTo)
                                        · Assigned to {{ $request->assignedTo->full_name ?? $request->assignedTo->username }}
                                    @endif
                                </p>
                            </div>

                            <flux:badge color="{{ $this->requestStatusColor((string) $request->amenity_request_status) }}" size="sm">
                                {{ $request->amenity_request_status }}
                            </flux:badge>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">No amenity request is linked to this booking.</p>
                @endforelse
            </div>
        </flux:card>

        <flux:card>
            <div class="mb-4">
                <h2 class="text-lg font-semibold">Inspection requests</h2>
            </div>

            <div class="space-y-3">
                @forelse ($this->inspectionRequests as $request)
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="font-medium">{{ $request->facility?->facility_name ?? 'Unknown facility' }}</p>
                                <p class="mt-1 text-xs text-zinc-500">
                                    Requested by {{ $request->requestedBy?->full_name ?? $request->requestedBy?->username ?? 'Unknown staff' }}
                                    {{ $request->requested_at?->diffForHumans() }}
                                </p>

                                @if ($request->assignedTo)
                                    <p class="mt-1 text-xs text-zinc-500">
                                        Assigned to {{ $request->assignedTo->full_name ?? $request->assignedTo->username }}
                                    </p>
                                @endif
                            </div>

                            <flux:badge color="{{ $this->requestStatusColor((string) $request->status) }}" size="sm">
                                {{ $request->status }}
                            </flux:badge>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">No inspection request has been sent for this booking.</p>
                @endforelse
            </div>
        </flux:card>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <flux:card>
            <div class="mb-4">
                <h2 class="text-lg font-semibold">Fines</h2>
            </div>

            <div class="space-y-3">
                @forelse ($this->booking->guestFines->sortByDesc('guest_fine_id') as $guestFine)
                    <div class="flex items-start justify-between gap-4 border-b border-zinc-100 pb-3 last:border-0 last:pb-0 dark:border-zinc-800">
                        <div>
                            <p class="font-medium">{{ $this->fineDescription($guestFine) }}</p>
                            <p class="mt-1 text-xs text-zinc-500">
                                {{ $guestFine->facility?->facility_name ?? 'Unknown facility' }}
                                · Quantity {{ $guestFine->quantity }}
                                @if ($guestFine->reportedBy)
                                    · Reported by {{ $guestFine->reportedBy->full_name ?? $guestFine->reportedBy->username }}
                                @endif
                            </p>
                        </div>

                        <p class="font-semibold">₱{{ number_format((float) $guestFine->total_charge, 2) }}</p>
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">No fine has been recorded for this booking.</p>
                @endforelse
            </div>
        </flux:card>

        <flux:card>
            <div class="mb-4">
                <h2 class="text-lg font-semibold">Payments</h2>
            </div>

            <div class="space-y-3">
                @forelse ($this->booking->payments->sortByDesc('payment_id') as $payment)
                    <div class="flex items-start justify-between gap-4 border-b border-zinc-100 pb-3 last:border-0 last:pb-0 dark:border-zinc-800">
                        <div>
                            <p class="font-medium">{{ $payment->p_ref_no ?? 'Payment #'.$payment->payment_id }}</p>
                            <p class="mt-1 text-xs text-zinc-500">
                                {{ $payment->modeOfPayment?->mode_of_payment ?? 'Unknown mode' }}
                                · {{ optional($payment->date_paid)->format('M d, Y') }}
                                @if ($payment->reference_number)
                                    · Ref {{ $payment->reference_number }}
                                @endif
                            </p>
                        </div>

                        <div class="text-right">
                            <p class="font-semibold">₱{{ number_format((float) $payment->amount_paid, 2) }}</p>
                            <flux:badge color="{{ $this->paymentStatusColor((string) $payment->payment_status) }}" size="sm">
                                {{ $payment->payment_status }}
                            </flux:badge>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">No payment record is linked to this booking.</p>
                @endforelse
            </div>
        </flux:card>
    </div>
</div>
