<?php

use App\Services\GuestSubmissionConfirmationService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.public')] #[Title('Check Your Email - Olaer Spring Resort')] class extends Component
{
    public function with(): array
    {
        $sent = (array) session('guest.submission_sent', []);

        return [
            'type' => ($sent['type'] ?? 'reservation') === 'booking' ? 'booking' : 'reservation',
            'email' => (string) ($sent['email'] ?? ''),
            'minutes' => GuestSubmissionConfirmationService::LINK_MINUTES,
        ];
    }
};

?>

<section class="relative overflow-hidden bg-emerald-950 py-12 text-white sm:py-16">
    <div class="relative mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
        <div class="rounded-3xl bg-white p-8 text-zinc-950 shadow-2xl shadow-black/20 dark:bg-zinc-900 dark:text-white">
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">One more step</p>
            <h1 class="mt-3 text-3xl font-bold tracking-tight">Check your email</h1>

            <p class="mt-4 text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                We sent a confirmation link to
                @if ($email !== '')
                    <strong>{{ $email }}</strong>.
                @else
                    your email address.
                @endif
                <strong>Your {{ $type }} is not submitted yet.</strong>
                Open the email and click the link. Your {{ $type }} and your GCash payment proof are only sent to the resort after you click it.
            </p>

            <ul class="mt-4 list-disc space-y-1 pl-5 text-sm text-zinc-600 dark:text-zinc-300">
                <li>The link works for {{ $minutes }} minutes.</li>
                <li>Can't find it? Check your spam or junk folder.</li>
                <li>Facilities are assigned when you click the link, not before.</li>
                <li>To change something, go back and send a new request. The newest link replaces the older one.</li>
            </ul>

            <div class="mt-6 flex flex-wrap gap-3">
                <flux:button :href="route('guest.home')" variant="primary" wire:navigate>Back to home</flux:button>
            </div>
        </div>
    </div>
</section>
