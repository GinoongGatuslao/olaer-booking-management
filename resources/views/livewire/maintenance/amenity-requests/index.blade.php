<?php

use App\Models\AmenityRequest;
use App\Services\AmenityRequestWorkflowService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';

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

    public function acceptRequest(int $amenityRequestId, AmenityRequestWorkflowService $workflow): void
    {
        try {
            $workflow->acceptRequest($amenityRequestId, Auth::id());
            $this->resetPage();
            session()->flash('success', 'Amenity request accepted. Mark it delivered after the items are delivered to the guest.');
        } catch (\Throwable $exception) {
            $this->addError('request', $exception->getMessage());
        }
    }

    public function markDelivered(int $amenityRequestId, AmenityRequestWorkflowService $workflow): void
    {
        try {
            $workflow->markDelivered($amenityRequestId, Auth::id());
            $this->resetPage();
            session()->flash('success', 'Amenity request marked as delivered.');
        } catch (\Throwable $exception) {
            $this->addError('request', $exception->getMessage());
        }
    }

    private function requests()
    {
        return AmenityRequest::query()
            ->with(['booking.guest', 'details.amenity.amenityName', 'details.facility', 'user', 'assignedTo'])
            ->whereIn('amenity_request_status', ['Pending', 'Delivering', 'Delivered'])
            ->when($this->statusFilter !== '', fn ($query) => $query->where('amenity_request_status', $this->statusFilter))
            ->when($this->search !== '', function ($query) {
                $search = '%' . $this->search . '%';

                $query->where(function ($query) use ($search) {
                    $query->whereHas('booking', fn ($bookingQuery) => $bookingQuery->where('b_ref_no', 'like', $search))
                        ->orWhereHas('booking.guest', function ($guestQuery) use ($search) {
                            $guestQuery->where('first_name', 'like', $search)
                                ->orWhere('last_name', 'like', $search)
                                ->orWhere('contact_no', 'like', $search);
                        })
                        ->orWhereHas('details.facility', fn ($facilityQuery) => $facilityQuery->where('facility_name', 'like', $search));
                });
            })
            ->latest('amenity_request_id')
            ->paginate(10);
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Maintenance Amenity Requests</h1>
        <p class="text-sm text-gray-600 dark:text-gray-400">Accept paid pending requests and mark them delivered after the items reach the guest.</p>
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

    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="mb-4 grid gap-3 md:grid-cols-2">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search guest, booking ref, or facility..." label="Search" />
            <flux:select wire:model.live="statusFilter" label="Status">
                <option value="">All delivery statuses</option>
                <option value="Pending">Pending</option>
                <option value="Delivering">Delivering</option>
                <option value="Delivered">Delivered</option>
            </flux:select>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                <thead>
                    <tr class="text-left text-gray-600 dark:text-gray-400">
                        <th class="px-3 py-2">Request</th>
                        <th class="px-3 py-2">Guest</th>
                        <th class="px-3 py-2">Delivery Items</th>
                        <th class="px-3 py-2">Created By</th>
                        <th class="px-3 py-2">Assigned To</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($requests as $request)
                        <tr>
                            <td class="px-3 py-3 font-medium text-gray-900 dark:text-gray-100">
                                #{{ $request->amenity_request_id }}<br>
                                <span class="text-xs text-gray-500">{{ $request->booking?->b_ref_no }}</span>
                            </td>
                            <td class="px-3 py-3">
                                {{ $request->booking?->guest?->first_name }} {{ $request->booking?->guest?->last_name }}
                            </td>
                            <td class="px-3 py-3">
                                <ul class="list-inside list-disc">
                                    @foreach ($request->details as $detail)
                                        <li>{{ $detail->amenity?->amenityName?->amenity_name }} x {{ $detail->amenity_quantity }} → {{ $detail->facility?->facility_name }}</li>
                                    @endforeach
                                </ul>
                            </td>
                            <td class="px-3 py-3">{{ $request->user?->first_name ?? '—' }}</td>
                            <td class="px-3 py-3">{{ $request->assignedTo?->first_name ?? '—' }}</td>
                            <td class="px-3 py-3">{{ $request->amenity_request_status }}</td>
                            <td class="px-3 py-3">
                                @if ($request->amenity_request_status === 'Pending')
                                    <flux:button size="sm" variant="primary" wire:click="acceptRequest({{ $request->amenity_request_id }})">Accept</flux:button>
                                @elseif ($request->amenity_request_status === 'Delivering' && (int) $request->assigned_to_user_id === Auth::id())
                                    <flux:button size="sm" variant="primary" wire:click="markDelivered({{ $request->amenity_request_id }})">Mark Delivered</flux:button>
                                @elseif ($request->amenity_request_status === 'Delivering')
                                    <span class="text-xs text-gray-500">Assigned to another staff</span>
                                @else
                                    <span class="text-xs text-gray-500">Done</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-6 text-center text-gray-500">No paid amenity requests waiting for maintenance.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $requests->links() }}
        </div>
    </div>
</div>
