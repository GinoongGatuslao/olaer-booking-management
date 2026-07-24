<?php

use App\Models\EntranceSlip;
use App\Services\EntranceSlipCalculator;
use App\Services\EntranceSlipWorkflowService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')]
#[Title('Create Entrance Slip - Olaer Spring Resort')]
class extends Component
{
    public int $adultCount = 0;
    public int $childrenCount = 0;
    public int $pwdScCount = 0;
    public int $maleCount = 0;
    public int $femaleCount = 0;
    public int $touristCount = 0;

    public string $adultDiscountId = '';
    public string $childrenDiscountId = '';
    public string $pwdScDiscountId = '';

    public int $adultDiscountedQuantity = 0;
    public int $childrenDiscountedQuantity = 0;
    public int $pwdScDiscountedQuantity = 0;

    public ?int $createdSlipId = null;

    #[Computed]
    public function calculation(): array
    {
        try {
            return app(EntranceSlipCalculator::class)
                ->calculate(
                    $this->counts(),
                    $this->discountPayload(),
                );
        } catch (\Throwable) {
            return [
                'lines' => [],
                'total_price' => 0.00,
                'amount_due' => 0.00,
                'total_guests' => 0,
            ];
        }
    }

    #[Computed]
    public function createdSlip(): ?EntranceSlip
    {
        if ($this->createdSlipId === null) {
            return null;
        }

        return EntranceSlip::query()
            ->with([
                'details.entranceFee',
                'details.discount',
                'createdBy',
                'handledBy',
                'payments',
            ])
            ->find($this->createdSlipId);
    }

    public function getDiscountsFor(
        string $categoryKey,
    ): Collection {
        return app(EntranceSlipCalculator::class)
            ->activeDiscountsFor($categoryKey);
    }

    public function save(
        EntranceSlipWorkflowService $workflow,
    ): void {
        $validated = $this->validate([
            'adultCount' => [
                'required',
                'integer',
                'min:0',
                'max:5000',
            ],
            'childrenCount' => [
                'required',
                'integer',
                'min:0',
                'max:5000',
            ],
            'pwdScCount' => [
                'required',
                'integer',
                'min:0',
                'max:5000',
            ],
            'maleCount' => [
                'required',
                'integer',
                'min:0',
                'max:5000',
            ],
            'femaleCount' => [
                'required',
                'integer',
                'min:0',
                'max:5000',
            ],
            'touristCount' => [
                'required',
                'integer',
                'min:0',
                'max:5000',
            ],
            'adultDiscountId' => [
                'nullable',
                'integer',
                'exists:tbl_discount,discount_id',
            ],
            'childrenDiscountId' => [
                'nullable',
                'integer',
                'exists:tbl_discount,discount_id',
            ],
            'pwdScDiscountId' => [
                'nullable',
                'integer',
                'exists:tbl_discount,discount_id',
            ],
            'adultDiscountedQuantity' => [
                'required',
                'integer',
                'min:0',
                'max:5000',
            ],
            'childrenDiscountedQuantity' => [
                'required',
                'integer',
                'min:0',
                'max:5000',
            ],
            'pwdScDiscountedQuantity' => [
                'required',
                'integer',
                'min:0',
                'max:5000',
            ],
        ]);

        try {
            $slip = $workflow->issue([
                'user_id' => (int) Auth::id(),
                'adult_count' =>
                    (int) $validated['adultCount'],
                'children_count' =>
                    (int) $validated['childrenCount'],
                'pwd_sc_count' =>
                    (int) $validated['pwdScCount'],
                'male_count' =>
                    (int) $validated['maleCount'],
                'female_count' =>
                    (int) $validated['femaleCount'],
                'tourist_count' =>
                    (int) $validated['touristCount'],
                'adult_discount_id' =>
                    $validated['adultDiscountId'] ?? null,
                'children_discount_id' =>
                    $validated['childrenDiscountId'] ?? null,
                'pwd_sc_discount_id' =>
                    $validated['pwdScDiscountId'] ?? null,
                'adult_discounted_quantity' =>
                    (int) $validated[
                        'adultDiscountedQuantity'
                    ],
                'children_discounted_quantity' =>
                    (int) $validated[
                        'childrenDiscountedQuantity'
                    ],
                'pwd_sc_discounted_quantity' =>
                    (int) $validated[
                        'pwdScDiscountedQuantity'
                    ],
            ]);

            $this->createdSlipId =
                (int) $slip->entrance_slip_id;

            unset(
                $this->calculation,
                $this->createdSlip,
            );

            session()->flash(
                'success',
                'Entrance slip created successfully. Print the slip and direct the guest to the cashier for full payment.',
            );
        } catch (\Throwable $exception) {
            $this->addError(
                'entranceSlip',
                $exception->getMessage(),
            );
        }
    }

    public function resetForm(): void
    {
        $this->reset([
            'adultCount',
            'childrenCount',
            'pwdScCount',
            'maleCount',
            'femaleCount',
            'touristCount',
            'adultDiscountId',
            'childrenDiscountId',
            'pwdScDiscountId',
            'adultDiscountedQuantity',
            'childrenDiscountedQuantity',
            'pwdScDiscountedQuantity',
            'createdSlipId',
        ]);

        unset(
            $this->calculation,
            $this->createdSlip,
        );

        $this->resetValidation();
    }

    public function formatSlipNo(?int $id): string
    {
        if ($id === null) {
            return 'Not yet generated';
        }

        return 'ES-'
            .now()->format('Ymd')
            .'-'
            .str_pad(
                (string) $id,
                5,
                '0',
                STR_PAD_LEFT,
            );
    }

    private function counts(): array
    {
        return [
            'adult' => $this->adultCount,
            'children' => $this->childrenCount,
            'pwd_sc' => $this->pwdScCount,
        ];
    }

    private function discountPayload(): array
    {
        return [
            'adult_discount_id' =>
                $this->adultDiscountId !== ''
                    ? (int) $this->adultDiscountId
                    : null,
            'children_discount_id' =>
                $this->childrenDiscountId !== ''
                    ? (int) $this->childrenDiscountId
                    : null,
            'pwd_sc_discount_id' =>
                $this->pwdScDiscountId !== ''
                    ? (int) $this->pwdScDiscountId
                    : null,
            'adult_discounted_quantity' =>
                $this->adultDiscountedQuantity,
            'children_discounted_quantity' =>
                $this->childrenDiscountedQuantity,
            'pwd_sc_discounted_quantity' =>
                $this->pwdScDiscountedQuantity,
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between print:hidden">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">
                Create Entrance Slip
            </h1>

            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Security Guard records the categorized headcount. Cashier collects the exact full amount after the slip is issued.
            </p>
        </div>

        <a
            href="{{ route('security.dashboard') }}"
            class="text-sm font-medium text-zinc-600 hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-white"
        >
            Back to dashboard
        </a>
    </div>

    @if (session('success'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900/60 dark:bg-green-950/40 dark:text-green-200 print:hidden">
            {{ session('success') }}
        </div>
    @endif

    @error('entranceSlip')
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200 print:hidden">
            {{ $message }}
        </div>
    @enderror

    <div class="grid gap-6 lg:grid-cols-3 print:block">
        <section class="lg:col-span-2 print:hidden">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <form wire:submit="save" class="space-y-6">
                    <div>
                        <h2 class="font-semibold">Entrance categories</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            These quantities determine the entrance charge.
                        </p>

                        <div class="mt-4 grid gap-4 md:grid-cols-3">
                            <flux:input wire:model.live="adultCount" type="number" min="0" label="Adults" />
                            <flux:input wire:model.live="childrenCount" type="number" min="0" label="Children" />
                            <flux:input wire:model.live="pwdScCount" type="number" min="0" label="Senior Citizen / PWD" />
                        </div>
                    </div>

                    <div>
                        <h2 class="font-semibold">Monitoring counts</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Male plus Female must equal the entrance category total. Tourist count cannot exceed that total.
                        </p>

                        <div class="mt-4 grid gap-4 md:grid-cols-3">
                            <flux:input wire:model.live="maleCount" type="number" min="0" label="Male" />
                            <flux:input wire:model.live="femaleCount" type="number" min="0" label="Female" />
                            <flux:input wire:model.live="touristCount" type="number" min="0" label="Tourist" />
                        </div>
                    </div>

                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                        <h2 class="font-semibold">Optional discounts</h2>

                        <div class="mt-4 grid gap-4 md:grid-cols-3">
                            <div class="space-y-3">
                                <flux:select wire:model.live="adultDiscountId" label="Adult discount">
                                    <option value="">No discount</option>
                                    @foreach ($this->getDiscountsFor('adult') as $discount)
                                        <option value="{{ $discount->discount_id }}">
                                            {{ $discount->discount_name }}
                                        </option>
                                    @endforeach
                                </flux:select>

                                <flux:input wire:model.live="adultDiscountedQuantity" type="number" min="0" label="Discounted adult qty" />
                            </div>

                            <div class="space-y-3">
                                <flux:select wire:model.live="childrenDiscountId" label="Children discount">
                                    <option value="">No discount</option>
                                    @foreach ($this->getDiscountsFor('children') as $discount)
                                        <option value="{{ $discount->discount_id }}">
                                            {{ $discount->discount_name }}
                                        </option>
                                    @endforeach
                                </flux:select>

                                <flux:input wire:model.live="childrenDiscountedQuantity" type="number" min="0" label="Discounted children qty" />
                            </div>

                            <div class="space-y-3">
                                <flux:select wire:model.live="pwdScDiscountId" label="Senior/PWD discount">
                                    <option value="">No discount</option>
                                    @foreach ($this->getDiscountsFor('pwd_sc') as $discount)
                                        <option value="{{ $discount->discount_id }}">
                                            {{ $discount->discount_name }}
                                        </option>
                                    @endforeach
                                </flux:select>

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
                        <flux:button type="submit" variant="primary">
                            Create entrance slip
                        </flux:button>

                        <flux:button type="button" variant="ghost" wire:click="resetForm">
                            Reset
                        </flux:button>
                    </div>
                </form>
            </div>
        </section>

        <aside class="space-y-6">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 print:hidden">
                <h2 class="font-semibold">Calculated total</h2>

                <div class="mt-4 space-y-3 text-sm">
                    @forelse ($this->calculation['lines'] as $line)
                        <div class="flex justify-between gap-4">
                            <div>
                                <div class="font-medium">
                                    {{ $line['label'] }} × {{ $line['quantity'] }}
                                </div>

                                @if ($line['discount_name'])
                                    <div class="text-xs text-zinc-500">
                                        {{ $line['discounted_quantity'] }} discounted via {{ $line['discount_name'] }}
                                    </div>
                                @endif
                            </div>

                            <div class="font-medium">
                                ₱{{ number_format((float) $line['line_total'], 2) }}
                            </div>
                        </div>
                    @empty
                        <p class="text-zinc-500">No guests entered yet.</p>
                    @endforelse
                </div>

                <div class="mt-5 border-t border-zinc-200 pt-4 dark:border-zinc-800">
                    <div class="flex items-center justify-between text-base font-semibold">
                        <span>Amount due</span>
                        <span>
                            ₱{{ number_format((float) $this->calculation['amount_due'], 2) }}
                        </span>
                    </div>
                </div>
            </div>

            @if ($this->createdSlip)
                <div
                    id="print-slip"
                    class="rounded-2xl border border-zinc-200 bg-white p-5 text-zinc-900 shadow-sm print:border-0 print:shadow-none"
                >
                    <div class="text-center">
                        <div class="text-lg font-bold">Olaer Spring Resort</div>
                        <div class="text-sm">Entrance Slip</div>
                        <div class="mt-2 text-sm font-semibold">
                            {{ $this->formatSlipNo($this->createdSlip->entrance_slip_id) }}
                        </div>
                    </div>

                    <div class="mt-5 space-y-1 text-sm">
                        <div class="flex justify-between">
                            <span>Date</span>
                            <span>{{ $this->createdSlip->date_created?->format('M d, Y') }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Time</span>
                            <span>{{ $this->createdSlip->time_created }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Created by</span>
                            <span>{{ $this->createdSlip->createdBy?->full_name }}</span>
                        </div>
                    </div>

                    <div class="mt-5 border-t border-zinc-200 pt-4">
                        @foreach ($this->createdSlip->details as $detail)
                            <div class="flex justify-between gap-4 border-b border-zinc-100 py-2 text-sm">
                                <span>
                                    {{ $detail->entranceFee?->entrance_fee_name }}
                                    × {{ $detail->guest_quantity }}
                                </span>
                                <span>
                                    ₱{{ number_format((float) $detail->entranceFee?->entrance_fee_price, 2) }}
                                </span>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-5 border-t border-zinc-200 pt-4">
                        <div class="flex justify-between text-base font-bold">
                            <span>Amount Due</span>
                            <span>
                                ₱{{ number_format((float) $this->createdSlip->amount_due, 2) }}
                            </span>
                        </div>

                        <div class="mt-2 text-xs text-zinc-500">
                            Status: {{ $this->createdSlip->status }}. Present this slip to the Cashier for exact full payment.
                        </div>
                    </div>
                </div>

                <div class="flex gap-3 print:hidden">
                    <flux:button type="button" variant="primary" onclick="window.print()">
                        Print slip
                    </flux:button>

                    <flux:button type="button" variant="ghost" wire:click="resetForm">
                        Create another
                    </flux:button>
                </div>
            @endif
        </aside>
    </div>
</div>
