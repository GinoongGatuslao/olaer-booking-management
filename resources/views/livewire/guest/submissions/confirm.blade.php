<?php

use App\Services\GuestSubmissionConfirmationService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.public')] #[Title('Confirm Your Request - Olaer Spring Resort')] class extends Component
{
    public ?string $error = null;

    /**
     * Opening the link is what creates the reservation/booking and records the payment.
     */
    public function mount(string $token, GuestSubmissionConfirmationService $confirmations): void
    {
        try {
            $submission = $confirmations->confirm($token);
        } catch (\InvalidArgumentException $exception) {
            $this->error = $exception->getMessage();

            return;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error = 'We could not complete your request just now. Please open the link again, or contact the resort.';

            return;
        }

        if ($submission->type === 'booking') {
            session()->put('guest.booking_confirmation_id', (int) $submission->booking_id);
            $this->redirect(route('guest.bookings.success'));

            return;
        }

        session()->put('guest.reservation_confirmation_id', (int) $submission->reservation_id);
        $this->redirect(route('guest.reservations.success'));
    }
};

?>

<section class="relative overflow-hidden bg-emerald-950 py-12 text-white sm:py-16">
    <div class="relative mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
        <div class="rounded-3xl bg-white p-8 text-zinc-950 shadow-2xl shadow-black/20 dark:bg-zinc-900 dark:text-white">
            @if ($error)
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-red-700 dark:text-red-300">Link problem</p>
                <h1 class="mt-3 text-2xl font-bold tracking-tight">We could not confirm your request</h1>
                <p class="mt-3 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ $error }}</p>

                <div class="mt-6 flex flex-wrap gap-3">
                    <flux:button :href="route('guest.reservations.create')" variant="primary" wire:navigate>Make a reservation</flux:button>
                    <flux:button :href="route('guest.bookings.create')" variant="ghost" wire:navigate>Make a booking</flux:button>
                </div>
            @else
                <p class="text-sm text-zinc-600 dark:text-zinc-300">Confirming your request…</p>
            @endif
        </div>
    </div>
</section>
