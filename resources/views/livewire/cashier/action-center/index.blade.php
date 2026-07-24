<?php

use App\Services\OperationalAlertService;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Cashier Action Center - Olaer Spring Resort')] class extends Component {
    public array $alerts = [];

    public function mount(): void
    {
        $this->refreshActionCenter();
    }

    public function refreshActionCenter(): void
    {
        $this->alerts = app(OperationalAlertService::class)->cashierAlerts();
    }

    /**
     * @return array<int, array{
     *   eyebrow:string,
     *   title:string,
     *   description:string,
     *   url:string,
     *   tone:string
     * }>
     */
    public function shortcuts(): array
    {
        $shortcuts = [
            [
                'route' => 'cashier.reservations.index',
                'eyebrow' => 'Front desk',
                'title' => 'Reservations',
                'description' => 'Create and manage guest reservations.',
                'tone' => 'primary',
            ],
            [
                'route' => 'cashier.reservation-conversions.index',
                'eyebrow' => 'Front desk',
                'title' => 'Reservation conversion',
                'description' => 'Move eligible reservations into bookings.',
                'tone' => 'secondary',
            ],
            [
                'route' => 'cashier.bookings.index',
                'eyebrow' => 'Guest stay',
                'title' => 'Bookings',
                'description' => 'Review and manage active booking records.',
                'tone' => 'info',
            ],
            [
                'route' => 'cashier.payments.index',
                'eyebrow' => 'Payments',
                'title' => 'Record payment',
                'description' => 'Settle booking, reservation, or entrance balances.',
                'tone' => 'success',
            ],
            [
                'route' => 'cashier.gcash-verifications.index',
                'eyebrow' => 'Payments',
                'title' => 'GCash verification',
                'description' => 'Review guest-uploaded proof and references.',
                'tone' => 'warning',
            ],
            [
                'route' => 'cashier.check-ins.index',
                'eyebrow' => 'Guest stay',
                'title' => 'Check-in',
                'description' => 'Admit eligible, fully paid bookings.',
                'tone' => 'info',
            ],
            [
                'route' => 'cashier.check-outs.index',
                'eyebrow' => 'Checkout',
                'title' => 'Check-out',
                'description' => 'Complete inspected, zero-balance stays.',
                'tone' => 'warning',
            ],
            [
                'route' => 'cashier.billings.index',
                'eyebrow' => 'Checkout',
                'title' => 'Billing statements',
                'description' => 'Review current guest charges and balances.',
                'tone' => 'secondary',
            ],
        ];

        return collect($shortcuts)
            ->filter(
                fn (array $shortcut): bool =>
                    Route::has($shortcut['route']),
            )
            ->map(
                function (array $shortcut): array {
                    $shortcut['url'] = route(
                        $shortcut['route'],
                    );

                    unset($shortcut['route']);

                    return $shortcut;
                },
            )
            ->values()
            ->all();
    }
};

?>

<div wire:poll.10s.visible="refreshActionCenter" class="space-y-6">
    <x-staff-page-header
        eyebrow="Front desk operations"
        title="Cashier action center"
        description="Open common workflows and respond to the most urgent live resort tasks from one place."
    >
        <x-slot:actions>
            <flux:button
                wire:click="refreshActionCenter"
                wire:loading.attr="disabled"
                wire:target="refreshActionCenter"
                variant="primary"
            >
                <span wire:loading.remove wire:target="refreshActionCenter">Refresh now</span>
                <span wire:loading wire:target="refreshActionCenter">Refreshing…</span>
            </flux:button>
        </x-slot:actions>
    </x-staff-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($this->shortcuts() as $shortcut)
            <x-staff-shortcut-card
                :eyebrow="$shortcut['eyebrow']"
                :title="$shortcut['title']"
                :description="$shortcut['description']"
                :href="$shortcut['url']"
                :tone="$shortcut['tone']"
            />
        @endforeach
    </div>

    <section class="rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-3 border-b border-brand-border pb-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-700">
            <div>
                <h2 class="text-lg font-semibold text-brand-text dark:text-white">Live cashier alerts</h2>
                <p class="mt-1 text-sm text-brand-text-muted dark:text-zinc-400">
                    {{ count($alerts) }} {{ count($alerts) === 1 ? 'active alert' : 'active alerts' }}
                </p>
            </div>

            @if (Route::has('cashier.notifications.index'))
                <flux:button
                    href="{{ route('cashier.notifications.index') }}"
                    wire:navigate
                    size="sm"
                    variant="ghost"
                >
                    Full notifications
                </flux:button>
            @endif
        </div>

        @if (count($alerts) === 0)
            <x-dashboard-empty-state
                class="mt-5"
                title="No cashier action needed"
                description="New payment, arrival, rental, and checkout alerts will appear here automatically."
            />
        @else
            <div class="mt-5 grid gap-3">
                @foreach ($alerts as $alert)
                    <x-operational-alert-card
                        :alert="$alert"
                        wire:key="cashier-action-alert-{{ $loop->index }}"
                    />
                @endforeach
            </div>
        @endif
    </section>
</div>
