<?php

use App\Models\EntranceSlip;
use App\Services\EntranceSlipCalculator;
use Illuminate\Support\Facades\DB;
use function Livewire\Volt\{computed, layout, state, title};

layout('layouts.app');
title('Create Entrance Slip - Olaer Spring Resort');

state([
    'adultCount' => 0,
    'childrenCount' => 0,
    'pwdScCount' => 0,
    'maleCount' => 0,
    'femaleCount' => 0,
    'touristCount' => 0,

    'adultDiscountId' => '',
    'childrenDiscountId' => '',
    'pwdScDiscountId' => '',
    'adultDiscountedQuantity' => 0,
    'childrenDiscountedQuantity' => 0,
    'pwdScDiscountedQuantity' => 0,

    'createdSlipId' => null,
]);

$counts = function (): array {
    return [
        'adult' => (int) $this->adultCount,
        'children' => (int) $this->childrenCount,
        'pwd_sc' => (int) $this->pwdScCount,
    ];
};

$discountPayload = function (): array {
    return [
        'adult_discount_id' => $this->adultDiscountId !== '' ? (int) $this->adultDiscountId : null,
        'children_discount_id' => $this->childrenDiscountId !== '' ? (int) $this->childrenDiscountId : null,
        'pwd_sc_discount_id' => $this->pwdScDiscountId !== '' ? (int) $this->pwdScDiscountId : null,
        'adult_discounted_quantity' => (int) $this->adultDiscountedQuantity,
        'children_discounted_quantity' => (int) $this->childrenDiscountedQuantity,
        'pwd_sc_discounted_quantity' => (int) $this->pwdScDiscountedQuantity,
    ];
};

$calculation = computed(function () {
    try {
        return app(EntranceSlipCalculator::class)->calculate(
            $this->counts(),
            $this->discountPayload(),
        );
    } catch (Throwable) {
        return [
            'lines' => [],
            'total_price' => 0.0,
            'amount_due' => 0.0,
            'total_guests' => 0,
        ];
    }
});

$createdSlip = computed(function () {
    if (! $this->createdSlipId) {
        return null;
    }

    return EntranceSlip::query()
        ->with(['details.entranceFee', 'details.discount', 'createdBy'])
        ->find($this->createdSlipId);
});

$getDiscountsFor = function (string $categoryKey) {
    return app(EntranceSlipCalculator::class)->activeDiscountsFor($categoryKey);
};

$resetForm = function (): void {
    $this->adultCount = 0;
    $this->childrenCount = 0;
    $this->pwdScCount = 0;
    $this->maleCount = 0;
    $this->femaleCount = 0;
    $this->touristCount = 0;
    $this->adultDiscountId = '';
    $this->childrenDiscountId = '';
    $this->pwdScDiscountId = '';
    $this->adultDiscountedQuantity = 0;
    $this->childrenDiscountedQuantity = 0;
    $this->pwdScDiscountedQuantity = 0;
    $this->createdSlipId = null;
    $this->resetValidation();
};

$save = function (): void {
    $validated = $this->validate([
        'adultCount' => ['required', 'integer', 'min:0', 'max:5000'],
        'childrenCount' => ['required', 'integer', 'min:0', 'max:5000'],
        'pwdScCount' => ['required', 'integer', 'min:0', 'max:5000'],
        'maleCount' => ['required', 'integer', 'min:0', 'max:5000'],
        'femaleCount' => ['required', 'integer', 'min:0', 'max:5000'],
        'touristCount' => ['required', 'integer', 'min:0', 'max:5000'],
        'adultDiscountId' => ['nullable', 'exists:tbl_discount,discount_id'],
        'childrenDiscountId' => ['nullable', 'exists:tbl_discount,discount_id'],
        'pwdScDiscountId' => ['nullable', 'exists:tbl_discount,discount_id'],
        'adultDiscountedQuantity' => ['required', 'integer', 'min:0', 'max:5000'],
        'childrenDiscountedQuantity' => ['required', 'integer', 'min:0', 'max:5000'],
        'pwdScDiscountedQuantity' => ['required', 'integer', 'min:0', 'max:5000'],
    ], [
        'adultDiscountId.exists' => 'Selected adult discount does not exist.',
        'childrenDiscountId.exists' => 'Selected children discount does not exist.',
        'pwdScDiscountId.exists' => 'Selected Senior/PWD discount does not exist.',
    ]);

    $totalGuests = (int) $validated['adultCount'] + (int) $validated['childrenCount'] + (int) $validated['pwdScCount'];
    $genderTotal = (int) $validated['maleCount'] + (int) $validated['femaleCount'];

    if ($totalGuests <= 0) {
        $this->addError('adultCount', 'At least one guest is required to create an entrance slip.');
        return;
    }

    if ($genderTotal !== $totalGuests) {
        $this->addError('maleCount', 'Male + Female count must equal the total entrance guests.');
        $this->addError('femaleCount', 'Male + Female count must equal the total entrance guests.');
        return;
    }

    if ((int) $validated['touristCount'] > $totalGuests) {
        $this->addError('touristCount', 'Tourist count cannot exceed the total entrance guests.');
        return;
    }

    try {
        $calculation = app(EntranceSlipCalculator::class)->calculate(
            $this->counts(),
            $this->discountPayload(),
        );
    } catch (Throwable $exception) {
        $this->addError('adultCount', $exception->getMessage());
        return;
    }

    $this->createdSlipId = DB::transaction(function () use ($validated, $calculation): int {
        $slip = EntranceSlip::query()->create([
            'no_of_adult' => (int) $validated['adultCount'],
            'no_of_children' => (int) $validated['childrenCount'],
            'no_of_PWD_SC' => (int) $validated['pwdScCount'],
            'no_of_Male' => (int) $validated['maleCount'],
            'no_of_Female' => (int) $validated['femaleCount'],
            'no_of_Tourist' => (int) $validated['touristCount'],
            'created_by_user_id' => auth()->id(),
            'date_created' => now()->toDateString(),
            'time_created' => now()->format('H:i:s'),
            'total_price' => $calculation['total_price'],
            'amount_due' => $calculation['amount_due'],
            'status' => 'Unpaid',
        ]);

        foreach ($calculation['lines'] as $line) {
            $slip->details()->create([
                'entrance_fee_id' => $line['entrance_fee_id'],
                'guest_quantity' => $line['quantity'],
                'discount_id' => $line['discount_id'],
                'discounted_quantity' => $line['discounted_quantity'],
            ]);
        }

        return $slip->entrance_slip_id;
    });

    session()->flash('success', 'Entrance slip created successfully. Print the slip and direct the guest to the cashier for payment.');
};

$formatSlipNo = function (?int $id): string {
    if (! $id) {
        return 'Not yet generated';
    }

    return 'ES-' . now()->format('Ymd') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between print:hidden">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Create Entrance Slip</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Security guard records guest headcount. Cashier handles payment after the slip is created.
            </p>
        </div>

        <a href="{{ route('security.dashboard') }}" class="text-sm font-medium text-zinc-600 hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-white">
            Back to dashboard
        </a>
    </div>

    @if (session('success'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900/60 dark:bg-green-950/40 dark:text-green-200 print:hidden">
            {{ session('success') }}
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3 print:block">
        <section class="lg:col-span-2 print:hidden">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <h2 class="font-semibold">Guest counts</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Entrance category count is for billing. Male/female/tourist counts are for monitoring and tourism reports.
                </p>

                <form wire:submit="save" class="mt-5 space-y-6">
                    <div class="grid gap-4 md:grid-cols-3">
                        <flux:input wire:model.live="adultCount" type="number" min="0" label="Adults" />
                        <flux:input wire:model.live="childrenCount" type="number" min="0" label="Children" />
                        <flux:input wire:model.live="pwdScCount" type="number" min="0" label="Senior Citizen / PWD" />
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <flux:input wire:model.live="maleCount" type="number" min="0" label="Male" />
                        <flux:input wire:model.live="femaleCount" type="number" min="0" label="Female" />
                        <flux:input wire:model.live="touristCount" type="number" min="0" label="Tourist" />
                    </div>

                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                        <h3 class="font-medium">Optional entrance discounts</h3>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Select a discount only when a guest category qualifies. Discounted quantity cannot exceed the category count.
                        </p>

                        <div class="mt-4 grid gap-4 md:grid-cols-3">
                            <div class="space-y-2">
                                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Adult discount</label>
                                <select wire:model.live="adultDiscountId" class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950">
                                    <option value="">No discount</option>
                                    @foreach ($this->getDiscountsFor('adult') as $discount)
                                        <option value="{{ $discount->discount_id }}">{{ $discount->discount_name }} ({{ number_format((float) $discount->discount_amount * 100, 0) }}%)</option>
                                    @endforeach
                                </select>
                                <flux:input wire:model.live="adultDiscountedQuantity" type="number" min="0" label="Discounted adult qty" />
                            </div>

                            <div class="space-y-2">
                                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Children discount</label>
                                <select wire:model.live="childrenDiscountId" class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950">
                                    <option value="">No discount</option>
                                    @foreach ($this->getDiscountsFor('children') as $discount)
                                        <option value="{{ $discount->discount_id }}">{{ $discount->discount_name }} ({{ number_format((float) $discount->discount_amount * 100, 0) }}%)</option>
                                    @endforeach
                                </select>
                                <flux:input wire:model.live="childrenDiscountedQuantity" type="number" min="0" label="Discounted children qty" />
                            </div>

                            <div class="space-y-2">
                                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Senior/PWD discount</label>
                                <select wire:model.live="pwdScDiscountId" class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950">
                                    <option value="">No discount</option>
                                    @foreach ($this->getDiscountsFor('pwd_sc') as $discount)
                                        <option value="{{ $discount->discount_id }}">{{ $discount->discount_name }} ({{ number_format((float) $discount->discount_amount * 100, 0) }}%)</option>
                                    @endforeach
                                </select>
                                <flux:input wire:model.live="pwdScDiscountedQuantity" type="number" min="0" label="Discounted Senior/PWD qty" />
                            </div>
                        </div>
                    </div>

                    @if ($errors->any())
                        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
                            <div class="font-medium">Please fix the following:</div>
                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="flex flex-col gap-3 sm:flex-row">
                        <flux:button type="submit" variant="primary">Create entrance slip</flux:button>
                        <flux:button type="button" variant="subtle" wire:click="resetForm">Reset</flux:button>
                    </div>
                </form>
            </div>
        </section>

        <aside class="space-y-6">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 print:hidden">
                <h2 class="font-semibold">Live total</h2>
                <div class="mt-4 space-y-3 text-sm">
                    @forelse ($this->calculation['lines'] as $line)
                        <div class="flex justify-between gap-4">
                            <div>
                                <div class="font-medium">{{ $line['label'] }} × {{ $line['quantity'] }}</div>
                                @if ($line['discount_name'])
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $line['discounted_quantity'] }} discounted via {{ $line['discount_name'] }}
                                    </div>
                                @endif
                            </div>
                            <div class="font-medium">₱{{ number_format($line['line_total'], 2) }}</div>
                        </div>
                    @empty
                        <p class="text-zinc-500 dark:text-zinc-400">No guests entered yet.</p>
                    @endforelse
                </div>

                <div class="mt-5 border-t border-zinc-200 pt-4 dark:border-zinc-800">
                    <div class="flex items-center justify-between text-base font-semibold">
                        <span>Total due</span>
                        <span>₱{{ number_format($this->calculation['amount_due'], 2) }}</span>
                    </div>
                </div>
            </div>

            @if ($this->createdSlip)
                <div id="print-slip" class="rounded-2xl border border-zinc-200 bg-white p-5 text-zinc-900 shadow-sm dark:border-zinc-800 dark:bg-white print:border-0 print:shadow-none">
                    <div class="text-center">
                        <div class="text-lg font-bold">Olaer Spring Resort</div>
                        <div class="text-sm">Entrance Slip</div>
                        <div class="mt-2 text-sm font-semibold">{{ $this->formatSlipNo($this->createdSlip->entrance_slip_id) }}</div>
                    </div>

                    <div class="mt-5 space-y-1 text-sm">
                        <div class="flex justify-between"><span>Date</span><span>{{ $this->createdSlip->date_created?->format('M d, Y') }}</span></div>
                        <div class="flex justify-between"><span>Time</span><span>{{ $this->createdSlip->time_created }}</span></div>
                        <div class="flex justify-between"><span>Created by</span><span>{{ $this->createdSlip->createdBy?->full_name }}</span></div>
                    </div>

                    <div class="mt-5 border-t border-zinc-200 pt-4">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 text-left">
                                    <th class="py-2">Category</th>
                                    <th class="py-2 text-right">Qty</th>
                                    <th class="py-2 text-right">Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->createdSlip->details as $detail)
                                    <tr class="border-b border-zinc-100">
                                        <td class="py-2">
                                            {{ $detail->entranceFee?->entrance_fee_name }}
                                            @if ($detail->discount)
                                                <div class="text-xs text-zinc-500">{{ $detail->discounted_quantity }} discounted: {{ $detail->discount->discount_name }}</div>
                                            @endif
                                        </td>
                                        <td class="py-2 text-right">{{ $detail->guest_quantity }}</td>
                                        <td class="py-2 text-right">₱{{ number_format((float) $detail->entranceFee?->entrance_fee_price, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-5 space-y-1 text-sm">
                        <div class="flex justify-between"><span>Male</span><span>{{ $this->createdSlip->no_of_Male }}</span></div>
                        <div class="flex justify-between"><span>Female</span><span>{{ $this->createdSlip->no_of_Female }}</span></div>
                        <div class="flex justify-between"><span>Tourist</span><span>{{ $this->createdSlip->no_of_Tourist }}</span></div>
                    </div>

                    <div class="mt-5 border-t border-zinc-200 pt-4">
                        <div class="flex justify-between text-base font-bold"><span>Amount Due</span><span>₱{{ number_format((float) $this->createdSlip->amount_due, 2) }}</span></div>
                        <div class="mt-2 text-xs text-zinc-500">Status: {{ $this->createdSlip->status }}. Present this slip to the cashier for payment.</div>
                    </div>
                </div>

                <div class="flex gap-3 print:hidden">
                    <flux:button type="button" variant="primary" onclick="window.print()">Print slip</flux:button>
                    <flux:button type="button" variant="subtle" wire:click="resetForm">Create another</flux:button>
                </div>
            @endif
        </aside>
    </div>
</div>
