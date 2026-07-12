<?php

use App\Models\ActivityLog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Activity Logs - Olaer Spring Resort')] class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $module = '';
    public string $action = '';
    public string $dateFrom = '';
    public string $dateTo = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedModule(): void
    {
        $this->resetPage();
    }

    public function updatedAction(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset([
            'search',
            'module',
            'action',
            'dateFrom',
            'dateTo',
        ]);

        $this->resetPage();
    }

    #[Computed]
    public function logs()
    {
        return ActivityLog::query()
            ->with('user')
            ->when($this->search !== '', function ($query): void {
                $search = '%'.trim($this->search).'%';

                $query->where(function ($query) use ($search): void {
                    $query->where('description', 'like', $search)
                        ->orWhere('subject_label', 'like', $search)
                        ->orWhere('module', 'like', $search)
                        ->orWhereHas('user', function ($query) use ($search): void {
                            $query->where('username', 'like', $search)
                                ->orWhere('first_name', 'like', $search)
                                ->orWhere('last_name', 'like', $search)
                                ->orWhere('email', 'like', $search);
                        });
                });
            })
            ->when($this->module !== '', fn ($query) => $query->where('module', $this->module))
            ->when($this->action !== '', fn ($query) => $query->where('action', $this->action))
            ->when($this->dateFrom !== '', fn ($query) => $query->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($query) => $query->whereDate('created_at', '<=', $this->dateTo))
            ->latest('activity_log_id')
            ->paginate(25);
    }

    #[Computed]
    public function modules()
    {
        return ActivityLog::query()
            ->select('module')
            ->distinct()
            ->orderBy('module')
            ->pluck('module');
    }

    public function actorName(ActivityLog $log): string
    {
        if ($log->user === null) {
            return 'System';
        }

        return $log->user->full_name
            ?? trim(implode(' ', array_filter([
                $log->user->first_name,
                $log->user->middle_name,
                $log->user->last_name,
            ])))
            ?: $log->user->username;
    }

    public function actionColor(string $action): string
    {
        return match ($action) {
            'Created' => 'green',
            'Updated' => 'blue',
            'Deleted' => 'red',
            default => 'zinc',
        };
    }
};

?>

<div wire:poll.30s class="space-y-6">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Activity Logs</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Read-only audit history of important system records. Updates every 30 seconds.
            </p>
        </div>

        <flux:badge color="zinc">
            Logs cannot be edited or deleted here
        </flux:badge>
    </div>

    <flux:card>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <flux:input
                wire:model.live.debounce.300ms="search"
                label="Search"
                placeholder="Reference, description, or staff"
            />

            <flux:select wire:model.live="module" label="Module">
                <option value="">All modules</option>
                @foreach ($this->modules as $moduleOption)
                    <option value="{{ $moduleOption }}">{{ $moduleOption }}</option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="action" label="Action">
                <option value="">All actions</option>
                <option value="Created">Created</option>
                <option value="Updated">Updated</option>
                <option value="Deleted">Deleted</option>
            </flux:select>

            <flux:input wire:model.live="dateFrom" type="date" label="From" />
            <flux:input wire:model.live="dateTo" type="date" label="To" />
        </div>

        <div class="mt-4 flex justify-end">
            <flux:button wire:click="clearFilters" variant="ghost">
                Clear Filters
            </flux:button>
        </div>
    </flux:card>

    <flux:card class="overflow-hidden p-0">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[72rem] text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50">
                    <tr>
                        <th class="px-4 py-3">Date and time</th>
                        <th class="px-4 py-3">Staff</th>
                        <th class="px-4 py-3">Module</th>
                        <th class="px-4 py-3">Action</th>
                        <th class="px-4 py-3">Description</th>
                        <th class="px-4 py-3">IP address</th>
                        <th class="px-4 py-3">Changes</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($this->logs as $log)
                        <tr wire:key="activity-log-{{ $log->activity_log_id }}" class="align-top">
                            <td class="whitespace-nowrap px-4 py-4">
                                <p class="font-medium">{{ $log->created_at?->format('M d, Y') }}</p>
                                <p class="text-xs text-zinc-500">{{ $log->created_at?->format('h:i:s A') }}</p>
                            </td>

                            <td class="px-4 py-4">
                                <p class="font-medium">{{ $this->actorName($log) }}</p>
                                <p class="text-xs text-zinc-500">{{ $log->user?->username ?? 'Automated process' }}</p>
                            </td>

                            <td class="px-4 py-4">{{ $log->module }}</td>

                            <td class="px-4 py-4">
                                <flux:badge color="{{ $this->actionColor($log->action) }}" size="sm">
                                    {{ $log->action }}
                                </flux:badge>
                            </td>

                            <td class="max-w-xl px-4 py-4">
                                <p class="font-medium">{{ $log->description }}</p>
                                @if ($log->subject_label)
                                    <p class="mt-1 text-xs text-zinc-500">{{ $log->subject_label }}</p>
                                @endif
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-zinc-500">
                                {{ $log->ip_address ?? '—' }}
                            </td>

                            <td class="px-4 py-4">
                                @if ($log->old_values || $log->new_values)
                                    <details class="group">
                                        <summary class="cursor-pointer font-medium text-zinc-700 hover:underline dark:text-zinc-300">
                                            View details
                                        </summary>

                                        <div class="mt-3 grid min-w-[24rem] gap-3">
                                            @if ($log->old_values)
                                                <div>
                                                    <p class="mb-1 text-xs font-semibold uppercase text-zinc-500">Before</p>
                                                    <pre class="max-h-56 overflow-auto rounded-lg bg-zinc-950 p-3 text-xs text-zinc-100">{{ json_encode($log->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                </div>
                                            @endif

                                            @if ($log->new_values)
                                                <div>
                                                    <p class="mb-1 text-xs font-semibold uppercase text-zinc-500">After</p>
                                                    <pre class="max-h-56 overflow-auto rounded-lg bg-zinc-950 p-3 text-xs text-zinc-100">{{ json_encode($log->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                </div>
                                            @endif
                                        </div>
                                    </details>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-zinc-500">
                                No activity logs match the selected filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-zinc-200 px-4 py-4 dark:border-zinc-800">
            {{ $this->logs->links() }}
        </div>
    </flux:card>
</div>
