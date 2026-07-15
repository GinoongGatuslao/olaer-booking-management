<?php

use App\Models\AmenityRequest;
use App\Services\AmenityRequestWorkflowService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Maintenance Amenity Requests - Olaer Spring Resort')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'assignment', except: '')]
    public string $assignmentFilter = '';

    #[Url(as: 'sort', except: 'amenity_request_id')]
    public string $sortField = 'amenity_request_id';

    #[Url(as: 'direction', except: 'desc')]
    public string $sortDirection = 'desc';

    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    public function with(): array
    {
        return [
            'requests' => $this->requests(),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAssignmentFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 25, 50, 100], true)) {
            $this->perPage = 10;
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->assignmentFilter = '';
        $this->sortField = 'amenity_request_id';
        $this->sortDirection = 'desc';
        $this->perPage = 10;

        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        $allowed = [
            'amenity_request_id',
            'date_created',
            'total_price',
            'amenity_request_status',
            'delivered_at',
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
                $field === 'amenity_request_id' ? 'desc' : 'asc';
        }

        $this->resetPage();
    }

    public function acceptRequest(
        int $amenityRequestId,
        AmenityRequestWorkflowService $workflow,
    ): void {
        try {
            $workflow->acceptRequest(
                $amenityRequestId,
                (int) Auth::id(),
            );

            $this->resetPage();

            session()->flash(
                'success',
                'Amenity request accepted. Mark it delivered after the items reach the guest.',
            );
        } catch (\Throwable $exception) {
            $this->addError(
                'request',
                $exception->getMessage(),
            );
        }
    }

    public function markDelivered(
        int $amenityRequestId,
        AmenityRequestWorkflowService $workflow,
    ): void {
        try {
            $workflow->markDelivered(
                $amenityRequestId,
                (int) Auth::id(),
            );

            $this->resetPage();

            session()->flash(
                'success',
                'Amenity request marked as delivered.',
            );
        } catch (\Throwable $exception) {
            $this->addError(
                'request',
                $exception->getMessage(),
            );
        }
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            'Pending' => 'blue',
            'Delivering' => 'purple',
            'Delivered' => 'green',
            default => 'zinc',
        };
    }

    public function sortIndicator(string $field): string
    {
        if ($this->sortField !== $field) {
            return '↕';
        }

        return $this->sortDirection === 'asc'
            ? '↑'
            : '↓';
    }

    public function staffName(
        mixed $staff,
        string $fallback = '—',
    ): string {
        if ($staff === null) {
            return $fallback;
        }

        return $staff->full_name
            ?? trim(implode(' ', array_filter([
                $staff->first_name,
                $staff->middle_name,
                $staff->last_name,
            ])))
            ?: ($staff->username ?? $fallback);
    }

    private function requests()
    {
        $allowedSorts = [
            'amenity_request_id',
            'date_created',
            'total_price',
            'amenity_request_status',
            'delivered_at',
        ];

        $sortField = in_array(
            $this->sortField,
            $allowedSorts,
            true,
        )
            ? $this->sortField
            : 'amenity_request_id';

        $direction =
            $this->sortDirection === 'asc'
                ? 'asc'
                : 'desc';

        $perPage = in_array(
            $this->perPage,
            [10, 25, 50, 100],
            true,
        )
            ? $this->perPage
            : 10;

        return AmenityRequest::query()
            ->with([
                'booking.guest',
                'details.amenity.amenityName',
                'details.facility',
                'user',
                'assignedTo',
            ])
            ->whereIn(
                'amenity_request_status',
                ['Pending', 'Delivering', 'Delivered'],
            )
            ->when(
                $this->statusFilter !== '',
                fn ($query) => $query->where(
                    'amenity_request_status',
                    $this->statusFilter,
                ),
            )
            ->when(
                $this->assignmentFilter === 'unassigned',
                fn ($query) => $query
                    ->where('amenity_request_status', 'Pending')
                    ->whereNull('assigned_to_user_id'),
            )
            ->when(
                $this->assignmentFilter === 'mine',
                fn ($query) => $query->where(
                    'assigned_to_user_id',
                    Auth::id(),
                ),
            )
            ->when(
                $this->assignmentFilter === 'other',
                fn ($query) => $query
                    ->whereNotNull('assigned_to_user_id')
                    ->where(
                        'assigned_to_user_id',
                        '!=',
                        Auth::id(),
                    ),
            )
            ->when(
                trim($this->search) !== '',
                function ($query): void {
                    $search =
                        '%'.trim($this->search).'%';

                    $query->where(
                        function ($query) use ($search): void {
                            $query
                                ->where(
                                    'amenity_request_id',
                                    'like',
                                    $search,
                                )
                                ->orWhereHas(
                                    'booking',
                                    fn ($bookingQuery) =>
                                        $bookingQuery
                                            ->where(
                                                'b_ref_no',
                                                'like',
                                                $search,
                                            ),
                                )
                                ->orWhereHas(
                                    'booking.guest',
                                    function ($guestQuery) use ($search): void {
                                        $guestQuery
                                            ->where(
                                                'first_name',
                                                'like',
                                                $search,
                                            )
                                            ->orWhere(
                                                'middle_name',
                                                'like',
                                                $search,
                                            )
                                            ->orWhere(
                                                'last_name',
                                                'like',
                                                $search,
                                            )
                                            ->orWhere(
                                                'contact_no',
                                                'like',
                                                $search,
                                            )
                                            ->orWhere(
                                                'email',
                                                'like',
                                                $search,
                                            );
                                    },
                                )
                                ->orWhereHas(
                                    'details.facility',
                                    fn ($facilityQuery) =>
                                        $facilityQuery
                                            ->where(
                                                'facility_name',
                                                'like',
                                                $search,
                                            ),
                                )
                                ->orWhereHas(
                                    'details.amenity',
                                    function ($amenityQuery) use ($search): void {
                                        $amenityQuery
                                            ->where(
                                                'amenity_description',
                                                'like',
                                                $search,
                                            )
                                            ->orWhereHas(
                                                'amenityName',
                                                fn ($nameQuery) =>
                                                    $nameQuery
                                                        ->where(
                                                            'amenity_name',
                                                            'like',
                                                            $search,
                                                        ),
                                            );
                                    },
                                )
                                ->orWhereHas(
                                    'assignedTo',
                                    function ($staffQuery) use ($search): void {
                                        $staffQuery
                                            ->where(
                                                'first_name',
                                                'like',
                                                $search,
                                            )
                                            ->orWhere(
                                                'last_name',
                                                'like',
                                                $search,
                                            )
                                            ->orWhere(
                                                'username',
                                                'like',
                                                $search,
                                            );
                                    },
                                );
                        },
                    );
                },
            )
            ->orderBy($sortField, $direction)
            ->orderByDesc('amenity_request_id')
            ->paginate($perPage);
    }
};

?>

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">
            Maintenance Amenity Requests
        </h1>

        <p class="text-sm text-gray-600 dark:text-gray-400">
            Accept paid requests and mark them delivered only after the items reach the guest.
        </p>
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @error('request')
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            {{ $message }}
        </div>
    @enderror

    <flux:card class="overflow-hidden p-0">
        <div class="border-b border-gray-200 p-5 dark:border-gray-800">
            <div>
                <h2 class="font-semibold">Delivery queue and history</h2>

                <p class="mt-1 text-sm text-gray-500">
                    Pending requests are paid and unassigned. Delivering requests belong to one maintenance staff member.
                </p>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    label="Search"
                    placeholder="Request, booking, guest, facility, amenity"
                    clearable
                    class="xl:col-span-2"
                />

                <flux:select
                    wire:model.live="statusFilter"
                    label="Status"
                >
                    <option value="">All delivery statuses</option>
                    <option value="Pending">Pending</option>
                    <option value="Delivering">Delivering</option>
                    <option value="Delivered">Delivered</option>
                </flux:select>

                <flux:select
                    wire:model.live="assignmentFilter"
                    label="Assignment"
                >
                    <option value="">All assignments</option>
                    <option value="unassigned">Unassigned pending</option>
                    <option value="mine">Assigned to me</option>
                    <option value="other">Assigned to another staff</option>
                </flux:select>

                <flux:select
                    wire:model.live="perPage"
                    label="Rows per page"
                >
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </flux:select>
            </div>

            <div class="mt-4 flex justify-end">
                <flux:button
                    wire:click="clearFilters"
                    variant="ghost"
                >
                    Clear Filters
                </flux:button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[84rem] divide-y divide-gray-200 text-sm dark:divide-gray-800">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-600 dark:bg-gray-900 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">
                            <button
                                wire:click="sortBy('amenity_request_id')"
                                class="font-semibold"
                            >
                                Request {{ $this->sortIndicator('amenity_request_id') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">
                            <button
                                wire:click="sortBy('date_created')"
                                class="font-semibold"
                            >
                                Created {{ $this->sortIndicator('date_created') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">Guest / Booking</th>
                        <th class="px-4 py-3">Delivery Items</th>

                        <th class="px-4 py-3">
                            <button
                                wire:click="sortBy('total_price')"
                                class="font-semibold"
                            >
                                Paid Value {{ $this->sortIndicator('total_price') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">Created By</th>
                        <th class="px-4 py-3">Assigned To</th>

                        <th class="px-4 py-3">
                            <button
                                wire:click="sortBy('amenity_request_status')"
                                class="font-semibold"
                            >
                                Status {{ $this->sortIndicator('amenity_request_status') }}
                            </button>
                        </th>

                        <th class="px-4 py-3">
                            <button
                                wire:click="sortBy('delivered_at')"
                                class="font-semibold"
                            >
                                Delivered {{ $this->sortIndicator('delivered_at') }}
                            </button>
                        </th>

                        <th class="px-4 py-3 text-right">Action</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($requests as $request)
                        <tr wire:key="maintenance-amenity-request-{{ $request->amenity_request_id }}" class="align-top">
                            <td class="px-4 py-4 font-medium">
                                #{{ $request->amenity_request_id }}
                            </td>

                            <td class="px-4 py-4">
                                {{ $request->date_created?->format('M d, Y') ?? 'N/A' }}
                            </td>

                            <td class="px-4 py-4">
                                <p class="font-medium">
                                    {{ $request->booking?->guest?->full_name
                                        ?? trim(($request->booking?->guest?->first_name ?? '').' '.($request->booking?->guest?->last_name ?? ''))
                                        ?: 'Unknown guest' }}
                                </p>

                                <p class="mt-1 text-xs text-gray-500">
                                    {{ $request->booking?->b_ref_no ?? 'No booking reference' }}
                                </p>
                            </td>

                            <td class="max-w-xl px-4 py-4">
                                <ul class="space-y-1 text-xs leading-5">
                                    @foreach ($request->details as $detail)
                                        <li>
                                            {{ $detail->amenity?->amenityName?->amenity_name ?? 'Unknown amenity' }}
                                            × {{ $detail->amenity_quantity }}
                                            →
                                            <span class="font-medium">
                                                {{ $detail->facility?->facility_name ?? 'Unknown facility' }}
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </td>

                            <td class="px-4 py-4 font-semibold">
                                ₱{{ number_format((float) $request->total_price, 2) }}
                            </td>

                            <td class="px-4 py-4">
                                {{ $this->staffName($request->user) }}
                            </td>

                            <td class="px-4 py-4">
                                {{ $this->staffName($request->assignedTo) }}
                            </td>

                            <td class="px-4 py-4">
                                <flux:badge
                                    color="{{ $this->statusColor((string) $request->amenity_request_status) }}"
                                    size="sm"
                                >
                                    {{ $request->amenity_request_status }}
                                </flux:badge>
                            </td>

                            <td class="px-4 py-4">
                                {{ $request->delivered_at?->format('M d, Y h:i A') ?? '—' }}
                            </td>

                            <td class="px-4 py-4 text-right">
                                @if ($request->amenity_request_status === 'Pending')
                                    <flux:button
                                        size="sm"
                                        variant="primary"
                                        wire:click="acceptRequest({{ $request->amenity_request_id }})"
                                        wire:confirm="Accept this paid amenity request for delivery?"
                                    >
                                        Accept
                                    </flux:button>
                                @elseif (
                                    $request->amenity_request_status === 'Delivering'
                                    && (int) $request->assigned_to_user_id === (int) Auth::id()
                                )
                                    <flux:button
                                        size="sm"
                                        variant="primary"
                                        wire:click="markDelivered({{ $request->amenity_request_id }})"
                                        wire:confirm="Confirm that all requested items were delivered to the guest?"
                                    >
                                        Mark Delivered
                                    </flux:button>
                                @elseif ($request->amenity_request_status === 'Delivering')
                                    <span class="text-xs text-gray-500">
                                        Assigned to another staff
                                    </span>
                                @else
                                    <span class="text-xs text-gray-500">
                                        Completed
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-12 text-center text-gray-500">
                                No paid amenity requests match the current filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex flex-col gap-3 border-t border-gray-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
            <p class="text-sm text-gray-500">
                Showing
                {{ $requests->firstItem() ?? 0 }}
                to
                {{ $requests->lastItem() ?? 0 }}
                of
                {{ $requests->total() }}
                delivery records
            </p>

            {{ $requests->links() }}
        </div>
    </flux:card>
</div>
