<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component {
    public string $displayName = '';

    public string $username = '';

    public string $email = '';

    public string $contactNumber = '';

    public string $roleName = 'Staff';

    public string $status = 'Unknown';

    public bool $canManageStaff = false;

    public function mount(): void
    {
        $user = Auth::user()?->loadMissing('role');

        abort_if($user === null, 403);

        $this->displayName = $user->full_name ?: 'Staff user';
        $this->username = (string) $user->username;
        $this->email = (string) $user->email;
        $this->contactNumber = (string) ($user->contact_no ?: 'Not provided');
        $this->roleName = (string) ($user->role?->role_name ?? 'Staff');
        $this->status = (string) ($user->status ?: 'Unknown');
        $this->canManageStaff = in_array(
            $this->roleName,
            ['Admin', 'Manager'],
            true,
        );
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout
        :heading="__('Profile')"
        :subheading="__('View your Olaer staff account and assigned role')"
    >
        <div class="my-6 space-y-6">
            <div class="rounded-xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                    <flux:avatar
                        :name="$displayName"
                        size="lg"
                        class="bg-brand-spring text-brand-primary"
                    />

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading size="lg">{{ $displayName }}</flux:heading>
                            <x-status-badge :status="$status" />
                        </div>
                        <flux:text class="mt-1">
                            {{ $roleName }} · {{ '@'.$username }}
                        </flux:text>
                    </div>
                </div>
            </div>

            <dl class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-xl border border-brand-border bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-brand-text-muted">
                        Email address
                    </dt>
                    <dd class="mt-1 break-words text-sm font-medium text-brand-text dark:text-zinc-100">
                        {{ $email }}
                    </dd>
                </div>

                <div class="rounded-xl border border-brand-border bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-brand-text-muted">
                        Contact number
                    </dt>
                    <dd class="mt-1 text-sm font-medium text-brand-text dark:text-zinc-100">
                        {{ $contactNumber }}
                    </dd>
                </div>

                <div class="rounded-xl border border-brand-border bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-brand-text-muted">
                        Username
                    </dt>
                    <dd class="mt-1 text-sm font-medium text-brand-text dark:text-zinc-100">
                        {{ $username }}
                    </dd>
                </div>

                <div class="rounded-xl border border-brand-border bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-brand-text-muted">
                        Assigned role
                    </dt>
                    <dd class="mt-1 text-sm font-medium text-brand-text dark:text-zinc-100">
                        {{ $roleName }}
                    </dd>
                </div>
            </dl>

            <flux:callout
                icon="information-circle"
                heading="Profile changes are managed centrally"
            >
                Staff names, contact information, roles, and account status are maintained by an Admin or Manager.
            </flux:callout>

            @if ($canManageStaff)
                <div class="flex justify-end">
                    <flux:button
                        :href="route('admin.users.index')"
                        icon="users"
                        variant="primary"
                        wire:navigate
                    >
                        Manage Staff Accounts
                    </flux:button>
                </div>
            @endif
        </div>
    </x-pages::settings.layout>
</section>
