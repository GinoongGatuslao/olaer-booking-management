<?php

use App\Models\ActivityLog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Activity Logs - Olaer Spring Resort')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'module', except: '')]
    public string $module = '';

    #[Url(as: 'action', except: '')]
    public string $action = '';

    #[Url(as: 'subject', except: '')]
    public string $subjectType = '';

    #[Url(as: 'actor', except: '')]
    public string $actorFilter = '';

    #[Url(as: 'date_from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'date_to', except: '')]
    public string $dateTo = '';

    #[Url(as: 'sort', except: 'created_at')]
    public string $sortField = 'created_at';

    #[Url(as: 'direction', except: 'desc')]
    public string $sortDirection = 'desc';

    #[Url(as: 'per_page', except: 25)]
    public int $perPage = 25;

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

    public function updatedSubjectType(): void
    {
        $this->resetPage();
    }

    public function updatedActorFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 25, 50, 100], true)) {
            $this->perPage = 25;
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->module = '';
        $this->action = '';
        $this->subjectType = '';
        $this->actorFilter = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->sortField = 'created_at';
        $this->sortDirection = 'desc';
        $this->perPage = 25;

        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        $allowed = [
            'created_at',
            'actor',
            'module',
            'action',
            'subject_label',
            'ip_address',
        ];

        if (! in_array($field, $allowed, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection =
                $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection =
                $field === 'created_at' ? 'desc' : 'asc';
        }

        $this->resetPage();
    }

    public function sortIndicator(string $field): string
    {
        if ($this->sortField !== $field) {
            return '↕';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    #[Computed]
    public function logs()
    {
        $query = $this->filteredQuery()
            ->select('tbl_activity_log.*')
            ->leftJoin(
                'tbl_user',
                'tbl_user.user_id',
                '=',
                'tbl_activity_log.user_id',
            )
            ->with('user');

        $direction =
            $this->sortDirection === 'asc' ? 'asc' : 'desc';

        match ($this->sortField) {
            'actor' => $query
                ->orderByRaw(
                    "CASE
                        WHEN tbl_activity_log.user_id IS NULL THEN 0
                        ELSE 1
                    END {$direction}"
                )
                ->orderBy('tbl_user.last_name', $direction)
                ->orderBy('tbl_user.first_name', $direction),
            'module' => $query->orderBy(
                'tbl_activity_log.module',
                $direction,
            ),
            'action' => $query->orderBy(
                'tbl_activity_log.action',
                $direction,
            ),
            'subject_label' => $query->orderBy(
                'tbl_activity_log.subject_label',
                $direction,
            ),
            'ip_address' => $query->orderBy(
                'tbl_activity_log.ip_address',
                $direction,
            ),
            default => $query->orderBy(
                'tbl_activity_log.created_at',
                $direction,
            ),
        };

        $perPage = in_array(
            $this->perPage,
            [10, 25, 50, 100],
            true,
        )
            ? $this->perPage
            : 25;

        return $query
            ->orderBy(
                'tbl_activity_log.activity_log_id',
                'desc',
            )
            ->paginate($perPage);
    }

    #[Computed]
    public function modules()
    {
        return ActivityLog::query()
            ->whereNotNull('module')
            ->where('module', '!=', '')
            ->distinct()
            ->orderBy('module')
            ->pluck('module');
    }

    #[Computed]
    public function actions()
    {
        return ActivityLog::query()
            ->whereNotNull('action')
            ->where('action', '!=', '')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');
    }

    #[Computed]
    public function subjectTypes()
    {
        return ActivityLog::query()
            ->whereNotNull('subject_type')
            ->where('subject_type', '!=', '')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type');
    }

    #[Computed]
    public function summary(): array
    {
        $query = $this->filteredQuery();

        return [
            'count' => (clone $query)->count(),
            'staff_count' => (clone $query)
                ->whereNotNull('user_id')
                ->distinct('user_id')
                ->count('user_id'),
            'system_count' => (clone $query)
                ->whereNull('user_id')
                ->count(),
            'changed_count' => (clone $query)
                ->where(function ($query): void {
                    $query->whereNotNull('old_values')
                        ->orWhereNotNull('new_values');
                })
                ->count(),
        ];
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
        return match (strtolower($action)) {
            'created' => 'green',
            'updated' => 'blue',
            'deleted' => 'red',
            'verified', 'completed', 'checked-in', 'checked-out' => 'green',
            'rejected', 'cancelled', 'canceled' => 'red',
            default => 'zinc',
        };
    }

    public function subjectName(ActivityLog $log): string
    {
        $type = class_basename((string) $log->subject_type);

        if ($type === '') {
            $type = 'System record';
        }

        if ($log->subject_id !== null) {
            return $type.' #'.$log->subject_id;
        }

        return $type;
    }

    public function deviceSummary(?string $userAgent): string
    {
        if (! filled($userAgent)) {
            return 'Unknown client';
        }

        $userAgent = (string) $userAgent;

        if (str_contains($userAgent, 'Windows')) {
            return 'Windows browser';
        }

        if (
            str_contains($userAgent, 'Android')
            || str_contains($userAgent, 'Mobile')
        ) {
            return 'Mobile browser';
        }

        if (
            str_contains($userAgent, 'Macintosh')
            || str_contains($userAgent, 'Mac OS')
        ) {
            return 'macOS browser';
        }

        if (str_contains($userAgent, 'Linux')) {
            return 'Linux browser';
        }

        return 'Web client';
    }

    private function filteredQuery()
    {
        return ActivityLog::query()
            ->when(
                trim($this->search) !== '',
                function ($query): void {
                    $searchText = trim($this->search);
                    $like = '%'.$searchText.'%';
                    $numeric = ctype_digit($searchText)
                        ? (int) $searchText
                        : null;

                    $query->where(
                        function ($query) use (
                            $like,
                            $numeric,
                        ): void {
                            $query->where(
                                'description',
                                'like',
                                $like,
                            )
                                ->orWhere(
                                    'subject_label',
                                    'like',
                                    $like,
                                )
                                ->orWhere(
                                    'subject_type',
                                    'like',
                                    $like,
                                )
                                ->orWhere(
                                    'module',
                                    'like',
                                    $like,
                                )
                                ->orWhere(
                                    'action',
                                    'like',
                                    $like,
                                )
                                ->orWhere(
                                    'ip_address',
                                    'like',
                                    $like,
                                )
                                ->orWhere(
                                    'user_agent',
                                    'like',
                                    $like,
                                )
                                ->orWhereHas(
                                    'user',
                                    function ($query) use ($like): void {
                                        $query->where(
                                            'username',
                                            'like',
                                            $like,
                                        )
                                            ->orWhere(
                                                'first_name',
                                                'like',
                                                $like,
                                            )
                                            ->orWhere(
                                                'middle_name',
                                                'like',
                                                $like,
                                            )
                                            ->orWhere(
                                                'last_name',
                                                'like',
                                                $like,
                                            )
                                            ->orWhere(
                                                'email',
                                                'like',
                                                $like,
                                            );
                                    },
                                );

                            if ($numeric !== null) {
                                $query
                                    ->orWhere(
                                        'activity_log_id',
                                        $numeric,
                                    )
                                    ->orWhere(
                                        'subject_id',
                                        $numeric,
                                    );
                            }
                        },
                    );
                },
            )
            ->when(
                $this->module !== '',
                fn ($query) => $query->where(
                    'module',
                    $this->module,
                ),
            )
            ->when(
                $this->action !== '',
                fn ($query) => $query->where(
                    'action',
                    $this->action,
                ),
            )
            ->when(
                $this->subjectType !== '',
                fn ($query) => $query->where(
                    'subject_type',
                    $this->subjectType,
                ),
            )
            ->when(
                $this->actorFilter === 'staff',
                fn ($query) => $query->whereNotNull('user_id'),
            )
            ->when(
                $this->actorFilter === 'system',
                fn ($query) => $query->whereNull('user_id'),
            )
            ->when(
                $this->dateFrom !== '',
                fn ($query) => $query->whereDate(
                    'created_at',
                    '>=',
                    $this->dateFrom,
                ),
            )
            ->when(
                $this->dateTo !== '',
                fn ($query) => $query->whereDate(
                    'created_at',
                    '<=',
                    $this->dateTo,
                ),
            );
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
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-8">
            <flux:input
                wire:model.live.debounce.300ms="search"
                label="Search"
                placeholder="Log ID, record, description, staff, IP, or device"
                clearable
                class="xl:col-span-2"
            />

            <flux:select wire:model.live="module" label="Module">
                <option value="">All modules</option>

                @foreach ($this->modules as $moduleOption)
                    <option value="{{ $moduleOption }}">
                        {{ $moduleOption }}
                    </option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="action" label="Action">
                <option value="">All actions</option>

                @foreach ($this->actions as $actionOption)
                    <option value="{{ $actionOption }}">
                        {{ $actionOption }}
                    </option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="subjectType" label="Record type">
                <option value="">All record types</option>

                @foreach ($this->subjectTypes as $subjectOption)
                    <option value="{{ $subjectOption }}">
                        {{ class_basename($subjectOption) }}
                    </option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="actorFilter" label="Actor">
                <option value="">All actors</option>
                <option value="staff">Staff actions</option>
                <option value="system">System actions</option>
            </flux:select>

            <flux:input
                wire:model.live="dateFrom"
                type="date"
                label="From"
            />

            <flux:input
                wire:model.live="dateTo"
                type="date"
                label="To"
            />
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <flux:select wire:model.live="perPage" label="Rows per page">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </flux:select>

            <div class="flex items-end xl:col-start-4">
                <flux:button
                    wire:click="clearFilters"
                    variant="ghost"
                    class="w-full"
                >
                    Clear Filters
                </flux:button>
            </div>
        </div>
    </flux:card>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-xs uppercase tracking-wide text-zinc-500">
                Matching Logs
            </p>

            <p class="mt-1 text-xl font-semibold">
                {{ $this->summary['count'] }}
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-xs uppercase tracking-wide text-zinc-500">
                Staff Actors
            </p>

            <p class="mt-1 text-xl font-semibold">
                {{ $this->summary['staff_count'] }}
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-xs uppercase tracking-wide text-zinc-500">
                System Events
            </p>

            <p class="mt-1 text-xl font-semibold">
                {{ $this->summary['system_count'] }}
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-xs uppercase tracking-wide text-zinc-500">
                Logs With Changes
            </p>

            <p class="mt-1 text-xl font-semibold">
                {{ $this->summary['changed_count'] }}
            </p>
        </div>
    </div>

    <flux:card class="overflow-hidden p-0">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[72rem] text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50">
                    <tr>
                        <th class="px-4 py-3">
                            <button wire:click="sortBy('created_at')" class="font-semibold">
                                Date and time {{ $this->sortIndicator('created_at') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">
                            <button wire:click="sortBy('actor')" class="font-semibold">
                                Actor {{ $this->sortIndicator('actor') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">
                            <button wire:click="sortBy('module')" class="font-semibold">
                                Module {{ $this->sortIndicator('module') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">
                            <button wire:click="sortBy('action')" class="font-semibold">
                                Action {{ $this->sortIndicator('action') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">
                            <button wire:click="sortBy('subject_label')" class="font-semibold">
                                Record / Description {{ $this->sortIndicator('subject_label') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">
                            <button wire:click="sortBy('ip_address')" class="font-semibold">
                                Client {{ $this->sortIndicator('ip_address') }}
                            </button>
                        </th>

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
                                <p class="font-medium">
                                    {{ $log->description }}
                                </p>

                                <p class="mt-1 text-xs text-zinc-500">
                                    {{ $this->subjectName($log) }}
                                    @if ($log->subject_label)
                                        · {{ $log->subject_label }}
                                    @endif
                                </p>

                                <p class="mt-1 text-[11px] text-zinc-400">
                                    Log #{{ $log->activity_log_id }}
                                </p>
                            </td>

                            <td class="px-4 py-4 text-zinc-500">
                                <p class="whitespace-nowrap">
                                    {{ $log->ip_address ?? 'No IP recorded' }}
                                </p>

                                <p class="mt-1 text-xs">
                                    {{ $this->deviceSummary($log->user_agent) }}
                                </p>

                                @if ($log->user_agent)
                                    <details class="mt-2 text-xs">
                                        <summary class="cursor-pointer hover:underline">
                                            User agent
                                        </summary>

                                        <p class="mt-1 max-w-sm break-all rounded bg-zinc-100 p-2 dark:bg-zinc-800">
                                            {{ $log->user_agent }}
                                        </p>
                                    </details>
                                @endif
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

        <div class="flex flex-col gap-3 border-t border-zinc-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
            <p class="text-sm text-zinc-500">
                Showing
                {{ $this->logs->firstItem() ?? 0 }}
                to
                {{ $this->logs->lastItem() ?? 0 }}
                of
                {{ $this->logs->total() }}
                activity logs
            </p>

            {{ $this->logs->links() }}
        </div>
    </flux:card>
</div>
