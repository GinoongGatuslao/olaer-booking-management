<?php

use App\Models\Facility;
use App\Models\FacilityConditionPhoto;
use App\Services\FacilityConditionPhotoService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Facility Readiness - Olaer Spring Resort')] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** all | needs_photos | ready | occupied */
    #[Url(as: 'state', except: 'needs_photos')]
    public string $stateFilter = 'needs_photos';

    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    public ?int $selectedFacilityId = null;

    /** Photos picked by the staff member; saved as soon as they are chosen. */
    public array $photos = [];

    /** One file at a time: the bucket (S3) upload driver does not accept multiple files. */
    public $photo = null;

    public function with(): array
    {
        return [
            'facilities' => $this->facilities(),
            'selectedFacility' => $this->selectedFacility(),
            'activePhotos' => $this->activePhotos(),
            'outdatedCount' => $this->outdatedCount(),
            'occupied' => $this->selectedFacilityId !== null
                && app(FacilityConditionPhotoService::class)->isOccupied($this->selectedFacilityId),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStateFilter(): void
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
        $this->stateFilter = 'needs_photos';
        $this->perPage = 10;
        $this->resetPage();
    }

    public function selectFacility(int $facilityId): void
    {
        $this->selectedFacilityId = Facility::query()->whereKey($facilityId)->exists() ? $facilityId : null;
        $this->photos = [];
        $this->resetValidation();
    }

    public function clearSelection(): void
    {
        $this->selectedFacilityId = null;
        $this->photos = [];
        $this->resetValidation();
    }

    public function updatedPhoto(FacilityConditionPhotoService $photoService): void
    {
        if ($this->photo === null) {
            return;
        }

        $this->photos = [$this->photo];
        $this->photo = null;

        $this->updatedPhotos($photoService);
    }

    /** Max 10 photos per facility, 64 MB each; allowed only while no guest is checked in. */
    public function updatedPhotos(FacilityConditionPhotoService $photoService): void
    {
        $this->resetErrorBag(['photos', 'photos.*']);

        if ($this->selectedFacilityId === null || $this->photos === []) {
            $this->photos = [];

            return;
        }

        try {
            $this->validate([
                'photos' => ['array', 'max:'.FacilityConditionPhotoService::MAX_PHOTOS],
                'photos.*' => [
                    'file',
                    'mimes:jpg,jpeg,png,webp',
                    'max:'.FacilityConditionPhotoService::MAX_KILOBYTES,
                ],
            ], [
                'photos.max' => 'Choose up to '.FacilityConditionPhotoService::MAX_PHOTOS.' photos at a time.',
                'photos.*.mimes' => 'Photos must be JPG, PNG or WebP images.',
                'photos.*.max' => 'Each photo must be 64 MB or smaller.',
                'photos.*.file' => 'One of the chosen files could not be uploaded.',
            ]);

            $photoService->store(
                $this->selectedFacilityId,
                array_values($this->photos),
                (int) Auth::id(),
            );

            session()->flash('success', 'Facility photo(s) uploaded.');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->addError('photos', $exception->getMessage());
        } finally {
            $this->photos = [];
        }
    }

    public function deletePhoto(int $photoId, FacilityConditionPhotoService $photoService): void
    {
        try {
            $photoService->delete($photoId);

            session()->flash('success', 'Photo removed.');
        } catch (\Throwable $exception) {
            $this->addError('photos', $exception->getMessage());
        }
    }

    /** needs_photos | ready | occupied, for one row. */
    public function stateOf(Facility $facility): string
    {
        if ((int) $facility->checked_in_count > 0) {
            return 'occupied';
        }

        return (int) $facility->current_photos > 0 ? 'ready' : 'needs_photos';
    }

    private function facilities(): LengthAwarePaginator
    {
        $photo = 'tbl_facility_condition_photo';

        $query = Facility::query()
            ->select('tbl_facility.*')
            ->selectRaw("(select count(*) from tbl_booking_details d where d.facility_id = tbl_facility.facility_id and d.status = 'Checked-in') as checked_in_count")
            ->selectRaw("(select count(*) from {$photo} p where p.facility_id = tbl_facility.facility_id and p.status = 'Current') as current_photos")
            ->selectRaw("(select count(*) from {$photo} p where p.facility_id = tbl_facility.facility_id and p.status = 'In Use') as in_use_photos")
            ->selectRaw("(select max(p.outdated_at) from {$photo} p where p.facility_id = tbl_facility.facility_id and p.status = 'Outdated') as last_outdated_at")
            ->with('facilityType');

        if (filled($this->search)) {
            $query->where('tbl_facility.facility_name', 'like', '%'.trim($this->search).'%');
        }

        $occupiedSql = "exists (select 1 from tbl_booking_details d where d.facility_id = tbl_facility.facility_id and d.status = 'Checked-in')";
        $currentSql = "exists (select 1 from {$photo} p where p.facility_id = tbl_facility.facility_id and p.status = 'Current')";

        if ($this->stateFilter === 'occupied') {
            $query->whereRaw($occupiedSql);
        } elseif ($this->stateFilter === 'ready') {
            $query->whereRaw("not {$occupiedSql}")->whereRaw($currentSql);
        } elseif ($this->stateFilter === 'needs_photos') {
            $query->whereRaw("not {$occupiedSql}")->whereRaw("not {$currentSql}");
        }

        $perPage = in_array($this->perPage, [10, 25, 50, 100], true) ? $this->perPage : 10;

        return $query->orderBy('tbl_facility.facility_name')->paginate($perPage);
    }

    private function selectedFacility(): ?Facility
    {
        return $this->selectedFacilityId === null
            ? null
            : Facility::query()->with('facilityType')->find($this->selectedFacilityId);
    }

    private function activePhotos(): Collection
    {
        if ($this->selectedFacilityId === null) {
            return new Collection();
        }

        return FacilityConditionPhoto::query()
            ->with('uploadedBy')
            ->where('facility_id', $this->selectedFacilityId)
            ->whereIn('status', [FacilityConditionPhoto::CURRENT, FacilityConditionPhoto::IN_USE])
            ->orderBy('facility_condition_photo_id')
            ->get();
    }

    private function outdatedCount(): int
    {
        return $this->selectedFacilityId === null
            ? 0
            : FacilityConditionPhoto::query()
                ->where('facility_id', $this->selectedFacilityId)
                ->where('status', FacilityConditionPhoto::OUTDATED)
                ->count();
    }
};
?>


<div class="space-y-6">
    <x-staff-page-header
        eyebrow="Maintenance operations"
        title="Facility Readiness"
        description="Take photos of a facility while it is empty and ready. They become the “before” record when a guest checks in,
            and go out of date after check-out, so new photos are needed for the next guest."
    >
        <x-slot:actions>
            @if (Route::has('maintenance.dashboard'))
                <flux:button
                    href="{{ route('maintenance.dashboard') }}"
                    wire:navigate
                    variant="ghost"
                >
                    Back to Dashboard
                </flux:button>
            @endif
        </x-slot:actions>
    </x-staff-page-header>


    <x-toast :message="session('success')" fresh />

    <flux:card class="overflow-hidden p-0">
        <div class="grid items-end gap-3 border-b border-zinc-200 p-5 md:grid-cols-[1fr_1fr_auto] dark:border-zinc-800">
            <flux:input label="Facility" placeholder="Search facility name" wire:model.live.debounce.300ms="search" clearable />
            <flux:select label="Show" wire:model.live="stateFilter">
                <option value="needs_photos">Needs new photos</option>
                <option value="ready">Ready (photos current)</option>
                <option value="occupied">Occupied (guest checked in)</option>
                <option value="all">All facilities</option>
            </flux:select>
            <div class="flex items-end gap-2">
                <flux:select wire:model.live="perPage" label="Rows">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </flux:select>

                <flux:button type="button" wire:click="clearFilters" variant="ghost">Clear Filters</flux:button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-950/60">
                    <tr>
                        <th class="px-4 py-3 text-left">Facility</th>
                        <th class="px-4 py-3 text-left">Type</th>
                        <th class="px-4 py-3 text-left">Photo status</th>
                        <th class="px-4 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($facilities as $facility)
                        @php($state = $this->stateOf($facility))
                        <tr wire:key="facility-photo-row-{{ $facility->facility_id }}" @class(['bg-blue-50 dark:bg-blue-950/30' => $selectedFacilityId === (int) $facility->facility_id])>
                            <td class="px-4 py-3 font-medium">{{ $facility->facility_name }}</td>
                            <td class="px-4 py-3">{{ $facility->facilityType?->facility_type }}</td>
                            <td class="px-4 py-3">
                                @if ($state === 'occupied')
                                    <flux:badge color="blue">Occupied · {{ $facility->in_use_photos }} baseline photo(s)</flux:badge>
                                @elseif ($state === 'ready')
                                    <flux:badge color="green">Ready · {{ $facility->current_photos }} photo(s)</flux:badge>
                                @else
                                    <flux:badge color="amber">
                                        {{ $facility->last_outdated_at ? 'Photos outdated' : 'No photos yet' }}
                                    </flux:badge>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <flux:button size="sm" variant="{{ $state === 'needs_photos' ? 'primary' : 'ghost' }}" icon="camera" wire:click="selectFacility({{ $facility->facility_id }})">
                                    {{ $state === 'occupied' ? 'View' : ($state === 'ready' ? 'Manage' : 'Add photos') }}
                                </flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-10 text-center text-zinc-500">No facility matches the filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-800">
            {{ $facilities->links() }}
        </div>
    </flux:card>

    @if ($selectedFacility)
        <flux:card>
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">{{ $selectedFacility->facility_name }}</h2>
                    <p class="text-sm text-zinc-500">
                        {{ $activePhotos->count() }} / {{ \App\Services\FacilityConditionPhotoService::MAX_PHOTOS }} photos
                        @if ($outdatedCount > 0) · {{ $outdatedCount }} older photo(s) kept as record @endif
                    </p>
                </div>
                <flux:button size="sm" variant="ghost" wire:click="clearSelection">Close</flux:button>
            </div>

            @if ($occupied)
                <div class="mt-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200">
                    A guest is checked in. These photos are the baseline for this stay and can’t be changed. After check-out they become outdated and new photos will be needed.
                </div>
            @else
                @if ($activePhotos->count() < \App\Services\FacilityConditionPhotoService::MAX_PHOTOS)
                    <div class="mt-4">
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            wire:model="photo" x-data x-on:livewire-upload-finish="$el.value = ''"
                            class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium dark:text-zinc-300 dark:file:bg-zinc-800"
                        />
                        <p class="mt-1 text-xs text-zinc-500">
                            JPG, PNG or WebP · one photo at a time · up to 64 MB each · up to {{ \App\Services\FacilityConditionPhotoService::MAX_PHOTOS }} photos per facility.
                        </p>
                        <p wire:loading wire:target="photo" class="mt-1 text-xs font-medium text-blue-600 dark:text-blue-400">Uploading…</p>
                    </div>
                @else
                    <p class="mt-4 text-xs text-amber-700 dark:text-amber-300">Photo limit reached. Remove a photo to add another.</p>
                @endif

                @error('photos') <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $message }}</p> @enderror
                @error('photos.*') <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $message }}</p> @enderror
            @endif

            @if ($activePhotos->isNotEmpty())
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    @foreach ($activePhotos as $photo)
                        <div wire:key="facility-photo-{{ $photo->facility_condition_photo_id }}" class="relative overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <a href="{{ route('facility-condition-photos.show', $photo) }}" target="_blank" rel="noopener">
                                <img
                                    src="{{ route('facility-condition-photos.show', $photo) }}"
                                    alt="{{ $photo->original_name ?? 'Facility photo' }}"
                                    loading="lazy"
                                    class="aspect-square w-full object-cover"
                                />
                            </a>
                            @if ($photo->status === 'Current')
                                <button
                                    type="button"
                                    wire:click="deletePhoto({{ $photo->facility_condition_photo_id }})"
                                    wire:confirm="Remove this photo?"
                                    class="absolute right-1 top-1 rounded-full bg-black/60 px-2 py-0.5 text-xs font-medium text-white hover:bg-red-600"
                                    aria-label="Remove photo"
                                >
                                    Remove
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-4 text-sm text-zinc-500">No current photos. Add photos so the next check-in has a “before” record.</p>
            @endif
        </flux:card>
    @endif
</div>
