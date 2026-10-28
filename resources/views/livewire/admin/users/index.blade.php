<?php

use App\Models\Address;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('User Management - Olaer Spring Resort')] class extends Component
{
    use WithPagination;

    /** Marker for "empty required field": the field turns red, no text is shown. */
    private const REQUIRED_MESSAGE = 'This field is required.';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'role', except: '')]
    public string $roleFilter = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'sort', except: 'full_name')]
    public string $sortField = 'full_name';

    #[Url(as: 'direction', except: 'asc')]
    public string $sortDirection = 'asc';

    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    public ?int $editingUserId = null;
    public ?int $statusChangeUserId = null;
    public bool $showStatusDialog = false;
    public bool $showEditor = false;
    public string $firstName = '';
    public string $middleName = '';
    public string $lastName = '';
    public string $username = '';
    public string $email = '';
    public string $contactNo = '';
    public string $status = 'Active';
    public string $roleId = '';
    public string $province = '';
    public string $city = '';
    public string $barangay = '';
    public string $purok = '';
    public string $password = '';
    public string $passwordConfirmation = '';
    public bool $isDisabled = false;

    public function mount(): void
    {
        if ($this->roleId === '') {
            $this->roleId = (string) ($this->roles->first()?->role_id ?? '');
        }
    }

    #[Computed]
    public function roles()
    {
        return Role::query()
            ->where('role_name', '!=', 'Manager')
            ->orderBy('role_name')
            ->get();
    }

    #[Computed]
    public function users()
    {
        $allowedSorts = [
            'full_name',
            'username',
            'email',
            'contact_no',
            'role',
            'status',
        ];

        $sortField = in_array($this->sortField, $allowedSorts, true)
            ? $this->sortField
            : 'full_name';

        $sortDirection = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        $perPage = in_array($this->perPage, [10, 25, 50, 100], true)
            ? $this->perPage
            : 10;

        $query = User::query()
            ->with(['role', 'address'])
            ->when($this->roleFilter !== '', function (Builder $query): void {
                $query->where('role_id', $this->roleFilter);
            })
            ->when($this->statusFilter !== '', function (Builder $query): void {
                $query->where('status', $this->statusFilter);
            })
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $like = '%'.trim($this->search).'%';

                $query->where(function (Builder $query) use ($like): void {
                    $query->where('first_name', 'like', $like)
                        ->orWhere('middle_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('username', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('contact_no', 'like', $like)
                        ->orWhere('status', 'like', $like)
                        ->orWhereHas('role', function (Builder $query) use ($like): void {
                            $query->where('role_name', 'like', $like);
                        });
                });
            });

        match ($sortField) {
            'username' => $query->orderBy('username', $sortDirection),
            'email' => $query->orderBy('email', $sortDirection),
            'contact_no' => $query->orderBy('contact_no', $sortDirection),
            'status' => $query->orderBy('status', $sortDirection)
                ->orderBy('last_name')
                ->orderBy('first_name'),
            'role' => $query->orderBy(
                Role::query()
                    ->select('role_name')
                    ->whereColumn('tbl_role.role_id', 'tbl_user.role_id'),
                $sortDirection,
            )->orderBy('first_name'),
            default => $query->orderBy('first_name', $sortDirection)
        };

        return $query->paginate($perPage);
    }

    public function updated(string $property): void
    {
        // Clear the red highlight of a field as soon as the user edits it.
        $this->resetValidation($property);
    }

    /** Error text to display under a field; empty required errors only highlight the box. */
    public function visibleError(string $field): ?string
    {
        $message = $this->getErrorBag()->first($field);

        return $message === '' || $message === self::REQUIRED_MESSAGE ? null : $message;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
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
        $this->roleFilter = '';
        $this->statusFilter = '';
        $this->sortField = 'full_name';
        $this->sortDirection = 'asc';
        $this->perPage = 10;

        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        $allowedSorts = [
            'full_name',
            'username',
            'email',
            'contact_no',
            'role',
            'status',
        ];

        if (! in_array($field, $allowedSorts, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function createNewUser(): void
    {
        $this->resetForm();
        $this->showEditor = true;
    }

    public function startEditingUser(int $userId): void
    {
        $user = User::query()
            ->with(['address', 'role'])
            ->find($userId);

        if (! $user) {
            $this->showEditor = false;
            $this->resetForm();
            session()->flash('error', 'This staff account is no longer available. Refresh the list and try again.');

            return;
        }

        $this->editingUserId = (int) $user->user_id;
        $this->firstName = (string) $user->first_name;
        $this->middleName = (string) ($user->middle_name ?? '');
        $this->lastName = (string) $user->last_name;
        $this->username = (string) $user->username;
        $this->email = (string) $user->email;
        $this->contactNo = (string) $user->contact_no;
        $this->status = (string) $user->status;
        $this->roleId = (string) $user->role_id;
        $this->province = (string) ($user->address?->province ?? '');
        $this->city = (string) ($user->address?->city ?? '');
        $this->barangay = (string) ($user->address?->barangay ?? '');
        $this->purok = (string) ($user->address?->purok ?? '');
        $this->password = '';
        $this->passwordConfirmation = '';
        $this->isDisabled = true;

        $this->resetValidation();
        $this->showEditor = true;
    }

    public function cancelEdit(): void
    {
        session()->flash('success', 'Staff account edit cancelled. No changes were saved.');
        $this->showEditor = false;
        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->editingUserId = null;
        $this->firstName = '';
        $this->middleName = '';
        $this->lastName = '';
        $this->username = '';
        $this->email = '';
        $this->contactNo = '';
        $this->status = 'Active';
        $this->roleId = (string) ($this->roles->first()?->role_id ?? '');
        $this->province = '';
        $this->city = '';
        $this->barangay = '';
        $this->purok = '';
        $this->password = '';
        $this->passwordConfirmation = '';
        $this->isDisabled = false;

        $this->resetValidation();
    }

    public function saveUser(): void
    {
        $validated = $this->validate([
            'editingUserId' => ['nullable', 'integer', 'exists:tbl_user,user_id'],
            'firstName' => ['required', 'string', 'max:50'],
            'middleName' => ['nullable', 'string', 'max:50'],
            'lastName' => ['required', 'string', 'max:50'],
            'username' => [
                'required',
                'string',
                'max:50',
                Rule::unique('tbl_user', 'username')
                    ->ignore($this->editingUserId, 'user_id'),
            ],
            'email' => [
                'required',
                'email',
                'max:50',
                'regex:/^[A-Za-z0-9]+(?:[._%+-][A-Za-z0-9]+)*@(?:[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*\.)+[A-Za-z]{2,}$/',
                Rule::unique('tbl_user', 'email')
                    ->ignore($this->editingUserId, 'user_id'),
            ],
            'contactNo' => ['required', 'regex:/^[0-9]{11}$/'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'roleId' => [
                'required',
                'integer',
                Rule::exists('tbl_role', 'role_id')->where(
                    fn ($query) => $query->where('role_name', '!=', 'Manager'),
                ),
            ],
            'province' => ['required', 'string', 'max:50'],
            'city' => ['required', 'string', 'max:50'],
            'barangay' => ['nullable', 'string', 'max:50'],
            'purok' => ['nullable', 'string', 'max:50'],
            'password' => [
                $this->editingUserId !== null ? 'nullable' : 'required',
                'string',
                'min:8',
                'max:255',
            ],
            'passwordConfirmation' => [
                $this->editingUserId === null || $this->password !== ''
                    ? 'required'
                    : 'nullable',
                'same:password',
            ],
        ], [
            'firstName.required' => self::REQUIRED_MESSAGE,
            'lastName.required' => self::REQUIRED_MESSAGE,
            'username.required' => self::REQUIRED_MESSAGE,
            'username.unique' => 'This username is already taken.',
            'email.required' => self::REQUIRED_MESSAGE,
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'This email is already used by another account.',
            'contactNo.required' => self::REQUIRED_MESSAGE,
            'contactNo.regex' => 'Contact number must be exactly 11 digits.',
            'status.required' => self::REQUIRED_MESSAGE,
            'status.in' => 'Status must be Active or Inactive.',
            'roleId.required' => self::REQUIRED_MESSAGE,
            'roleId.exists' => 'Selected role does not exist.',
            'province.required' => self::REQUIRED_MESSAGE,
            'city.required' => self::REQUIRED_MESSAGE,
            'password.required' => self::REQUIRED_MESSAGE,
            'password.min' => 'Password must be at least 8 characters.',
            'passwordConfirmation.required' => self::REQUIRED_MESSAGE,
            'passwordConfirmation.same' => 'Password confirmation does not match.',
        ]);

        if (
            $this->editingUserId !== null
            && (int) $this->editingUserId === (int) Auth::id()
        ) {
            $currentUser = User::query()->findOrFail(Auth::id());

            if ((int) $validated['roleId'] !== (int) $currentUser->role_id) {
                $this->addError(
                    'roleId',
                    'You cannot change your own role while logged in. Ask another administrator.',
                );

                return;
            }

            if ($validated['status'] !== $currentUser->status) {
                $this->addError(
                    'status',
                    'You cannot activate or deactivate your own account while logged in.',
                );

                return;
            }
        }

        $clean = function (?string $value): ?string {
            $value = trim(preg_replace('/\s+/', ' ', (string) $value));

            return $value === '' ? null : $value;
        };

        DB::transaction(function () use ($validated, $clean): void {
            if ($this->editingUserId !== null) {
                $user = User::query()
                    ->with('address')
                    ->findOrFail($this->editingUserId);

                if ($user->address !== null) {
                    $user->address->update([
                        'province' => $clean($validated['province']),
                        'city' => $clean($validated['city']),
                        'barangay' => $clean($validated['barangay']),
                        'purok' => $clean($validated['purok']),
                    ]);
                } else {
                    $address = Address::query()->create([
                        'province' => $clean($validated['province']),
                        'city' => $clean($validated['city']),
                        'barangay' => $clean($validated['barangay']),
                        'purok' => $clean($validated['purok']),
                    ]);

                    $user->address_id = $address->address_id;
                }
            } else {
                $address = Address::query()->create([
                    'province' => $clean($validated['province']),
                    'city' => $clean($validated['city']),
                    'barangay' => $clean($validated['barangay']),
                    'purok' => $clean($validated['purok']),
                ]);

                $user = new User();
                $user->address_id = $address->address_id;
            }

            $payload = [
                'first_name' => $clean($validated['firstName']),
                'middle_name' => $clean($validated['middleName']),
                'last_name' => $clean($validated['lastName']),
                'username' => $clean($validated['username']),
                'email' => mb_strtolower((string) $clean($validated['email'])),
                'contact_no' => $clean($validated['contactNo']),
                'status' => $validated['status'],
                'role_id' => (int) $validated['roleId'],
            ];

            if ((string) ($validated['password'] ?? '') !== '') {
                // The User model's hashed cast hashes this plain-text value.
                $payload['password'] = $validated['password'];
            }

            $user->fill($payload);
            $user->save();
        });

        session()->flash(
            'success',
            $this->editingUserId !== null
                ? 'User account updated successfully.'
                : 'User account created successfully.',
        );

        $this->resetForm();
        $this->showEditor = false;
    }

    public function requestStatusChange(int $userId): void
    {
        if ($userId === (int) Auth::id()) {
            return;
        }

        if (! User::query()->whereKey($userId)->exists()) {
            $this->showStatusDialog = false;
            $this->statusChangeUserId = null;
            session()->flash('error', 'This staff account is no longer available. Refresh the list and try again.');

            return;
        }

        $this->statusChangeUserId = $userId;
        $this->showStatusDialog = true;
    }

    public function confirmStatusChange(): void
    {
        if ($this->statusChangeUserId === null) {
            return;
        }

        $userId = $this->statusChangeUserId;
        $this->showStatusDialog = false;
        $this->statusChangeUserId = null;
        $this->toggleStatus($userId);
    }

    public function toggleStatus(int $userId): void
    {
        if ($userId === (int) Auth::id()) {
            session()->flash(
                'error',
                'You cannot activate or deactivate your own account while logged in.',
            );

            return;
        }

        $user = User::query()->find($userId);

        if (! $user) {
            session()->flash('error', 'This staff account is no longer available. Refresh the list and try again.');

            return;
        }

        $user->update([
            'status' => $user->status === 'Active' ? 'Inactive' : 'Active',
        ]);

        session()->flash(
            'success',
            $this->fullName($user).' is now '.$user->status.'.',
        );
    }

    public function fullName(User $user): string
    {
        return trim(implode(' ', array_filter([
            $user->first_name,
            $user->middle_name,
            $user->last_name,
        ])));
    }

    public function addressLine(User $user): string
    {
        if ($user->address === null) {
            return 'No address set';
        }

        return trim(implode(', ', array_filter([
            $user->address->purok,
            $user->address->barangay,
            $user->address->city,
            $user->address->province,
        ])));
    }

    public function sortIcon(string $field): string
    {
        if ($this->sortField !== $field) {
            return '↕';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    public function statusColor(string $status): string
    {
        return $status === 'Active' ? 'green' : 'red';
    }

    public function roleColor(?string $roleName): string
    {
        return match ($roleName) {
            'Admin' => 'zinc',
            'Cashier' => 'blue',
            'Maintenance Staff' => 'amber',
            'Security Guard' => 'green',
            default => 'zinc',
        };
    }
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">
                User Management
            </h1>
            
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Paginated staff-account management with role and account-status controls.
            </p>
        </div>
        <div class="flex gap-2">
        <flux:button type="button" wire:click="createNewUser" variant="primary">Create Staff Account</flux:button>
        @if (Route::has('admin.dashboard'))
            <flux:button
                href="{{ route('admin.dashboard') }}"
                wire:navigate
                variant="ghost"
            >
                Back to Dashboard
            </flux:button>
        @endif
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900/60 dark:bg-green-950/40 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            {{ session('error') }}
        </div>
    @endif

    <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100">
        This page manages resort staff accounts only. Guest records remain part of the reservation and booking workflows.
    </div>

    <div>
        <section class="min-w-0">
            <flux:card class="overflow-hidden p-0">
                <div class="border-b border-zinc-200 p-5 dark:border-zinc-800">
                    <div class="flex flex-col gap-4">

                        <div class="grid gap-3 md:grid-cols-4">
                            <flux:input
                                wire:model.live.debounce.300ms="search"
                                label="Search"
                                placeholder="Name, username, email, contact, status, or role"
                                clearable
                            />

                            <flux:select wire:model.live="roleFilter" label="Role">
                                <option value="">All roles</option>
                                @foreach ($this->roles as $role)
                                    <option value="{{ $role->role_id }}">
                                        {{ $role->role_name }}
                                    </option>
                                @endforeach
                            </flux:select>

                            <flux:select wire:model.live="statusFilter" label="Status">
                                <option value="">All statuses</option>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </flux:select>

                            <div class="flex flex-wrap items-end gap-2">
                                
                                <div class="w-28">
                                    <flux:select wire:model.live="perPage" label="Rows">
                                        <option value="10">10</option>
                                        <option value="25">25</option>
                                        <option value="50">50</option>
                                        <option value="100">100</option>
                                    </flux:select>
                                </div>

                                <flux:button wire:click="clearFilters" variant="ghost">
                                    Clear Filters
                                </flux:button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[72rem] text-left text-sm">
                        <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50">
                            <tr>
                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('full_name')"
                                        class="inline-flex items-center gap-1 font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        NAME {{ $this->sortIcon('full_name') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('username')"
                                        class="inline-flex items-center gap-1 font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        USERNAME {{ $this->sortIcon('username') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('role')"
                                        class="inline-flex items-center gap-1 font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        ROLE {{ $this->sortIcon('role') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('status')"
                                        class="inline-flex items-center gap-1 font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        STATUS {{ $this->sortIcon('status') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('email')"
                                        class="inline-flex items-center gap-1 font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        EMAIL {{ $this->sortIcon('email') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('contact_no')"
                                        class="inline-flex items-center gap-1 font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        CONTACT {{ $this->sortIcon('contact_no') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3 text-right font-semibold">ACTIONS</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 ">
                            @forelse ($this->users as $user)
                                <tr wire:key="user-row-{{ $user->user_id }}" class="align-top {{ $editingUserId === (int) $user->user_id ? 'bg-blue-50 ring-1 ring-inset ring-blue-200 dark:bg-blue-950/30 dark:ring-blue-800' : ''}} hover:bg-brand-surface-muted/70 dark:hover:bg-zinc-800/50">
                                    <td class="max-w-sm px-5 py-4">
                                        <p class="font-medium">
                                            {{ $this->fullName($user) }}
                                            @if ((int) $user->user_id === (int) auth()->id())
                                                <span class="text-xs font-normal text-zinc-500">(you)</span>
                                            @endif
                                        </p>

                                        <p class="mt-1 text-xs leading-5 text-zinc-500">
                                            {{ $this->addressLine($user) }}
                                        </p>
                                    </td>

                                    <td class="px-5 py-4">{{ $user->username }}</td>

                                    <td class="px-5 py-4">
                                        <flux:badge
                                            color="{{ $this->roleColor($user->role?->role_name) }}"
                                            size="sm"
                                        >
                                            {{ $user->role?->role_name ?? 'No role' }}
                                        </flux:badge>
                                    </td>

                                    <td class="px-5 py-4">
                                        <flux:badge
                                            color="{{ $this->statusColor((string) $user->status) }}"
                                            size="sm"
                                        >
                                            {{ $user->status }}
                                        </flux:badge>
                                    </td>

                                    <td class="px-5 py-4">{{ $user->email }}</td>
                                    <td class="px-5 py-4">{{ $user->contact_no }}</td>

                                    <td class="px-5 py-4 text-right">
                                        <x-table-actions label="Actions for {{ $user->username }}">
                                            <flux:menu.item icon="pencil-square" wire:click="startEditingUser({{ $user->user_id }})">Edit account</flux:menu.item>
                                            <flux:menu.item
                                                icon="{{ $user->status === 'Active' ? 'lock-closed' : 'lock-open' }}"
                                                wire:click="requestStatusChange({{ $user->user_id }})"
                                                :disabled="(int) $user->user_id === (int) auth()->id()"
                                            >
                                                {{ $user->status === 'Active' ? 'Deactivate' : 'Activate' }}
                                            </flux:menu.item>
                                        </x-table-actions>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-5 py-12 text-center text-zinc-500">
                                        No staff account matches the selected filters.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col gap-3 border-t border-zinc-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
                    <p class="text-sm text-zinc-500">
                        Showing
                        {{ $this->users->firstItem() ?? 0 }}
                        to
                        {{ $this->users->lastItem() ?? 0 }}
                        of
                        {{ $this->users->total() }}
                        staff accounts
                    </p>

                    {{ $this->users->links() }}
                </div>
            </flux:card>
        </section>

        <flux:modal wire:model="showEditor" class="md:w-[42rem] max-h-[90vh] overflow-y-auto">
                <div class="mb-5 flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">
                            {{ $editingUserId !== null ? 'Edit staff account' : 'Create staff account' }}
                        </h2>

                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Assign a resort role and login credentials.
                        </p>
                    </div>

                </div>

                <form wire:submit="saveUser" novalidate class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>First name <span class="text-red-500">*</span></flux:label>
                            <flux:input wire:model="firstName" :invalid="$errors->has('firstName')" />
                            @if ($msg = $this->visibleError('firstName'))
                                <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                            @endif
                        </flux:field>
                        <flux:field>
                            <flux:label>Middle name </flux:label>
                            <flux:input wire:model="middleName" :invalid="$errors->has('middleName')" />
                            @if ($msg = $this->visibleError('middleName'))
                                <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                            @endif
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>Last name <span class="text-red-500">*</span></flux:label>
                        <flux:input wire:model="lastName" :invalid="$errors->has('lastName')" />
                        @if ($msg = $this->visibleError('lastName'))
                            <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                        @endif
                    </flux:field>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>Username <span class="text-red-500">*</span></flux:label>
                            <flux:input wire:model="username" :disabled="$isDisabled" :invalid="$errors->has('username')" />
                            @if ($msg = $this->visibleError('username'))
                                <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                            @endif
                        </flux:field>
                        <flux:field>
                            <flux:label>Email <span class="text-red-500">*</span></flux:label>
                            <flux:input wire:model="email" type="email" :invalid="$errors->has('email')" />
                            @if ($msg = $this->visibleError('email'))
                                <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                            @endif
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>Contact number <span class="text-red-500">*</span></flux:label>
                        <flux:input wire:model="contactNo" placeholder="Example: 09123456789" maxlength="11" :invalid="$errors->has('contactNo')" />
                        @if ($msg = $this->visibleError('contactNo'))
                            <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                        @endif
                    </flux:field>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>Role <span class="text-red-500">*</span></flux:label>
                            <flux:select wire:model="roleId" :invalid="$errors->has('roleId')">
                                <option value="">Select role</option>
                                @foreach ($this->roles as $role)
                                    <option value="{{ $role->role_id }}">
                                        {{ $role->role_name }}
                                    </option>
                                @endforeach
                            </flux:select>
                            @if ($msg = $this->visibleError('roleId'))
                                <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                            @endif
                        </flux:field>

                        <flux:field>
                            <flux:label>Status <span class="text-red-500">*</span></flux:label>
                            <flux:select wire:model="status" :invalid="$errors->has('status')">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </flux:select>
                            @if ($msg = $this->visibleError('status'))
                                <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                            @endif
                        </flux:field>
                    </div>

                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                        <h3 class="text-sm font-semibold">Address</h3>

                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>Province <span class="text-red-500">*</span></flux:label>
                                <flux:input wire:model="province" :invalid="$errors->has('province')" />
                                @if ($msg = $this->visibleError('province'))
                                    <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                                @endif
                            </flux:field>
                            <flux:field>
                                <flux:label>City / Municipality <span class="text-red-500">*</span></flux:label>
                                <flux:input wire:model="city" :invalid="$errors->has('city')" />
                                @if ($msg = $this->visibleError('city'))
                                    <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                                @endif
                            </flux:field>
                            <flux:field>
                                <flux:label>Barangay </flux:label>
                                <flux:input wire:model="barangay" :invalid="$errors->has('barangay')" />
                                @if ($msg = $this->visibleError('barangay'))
                                    <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                                @endif
                            </flux:field>
                            <flux:field>
                                <flux:label>Purok / Street </flux:label>
                                <flux:input wire:model="purok" :invalid="$errors->has('purok')" />
                                @if ($msg = $this->visibleError('purok'))
                                    <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                                @endif
                            </flux:field>
                        </div>
                    </div>

                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                        <h3 class="text-sm font-semibold">Password</h3>

                        <p class="mt-1 text-xs text-zinc-500">
                            {{ $editingUserId !== null
                                ? 'Leave both password fields blank to keep the current password.'
                                : 'A password is required for a new account.' }}
                        </p>

                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>Password @if ($editingUserId === null) <span class="text-red-500">*</span> @endif</flux:label>
                                <flux:input wire:model="password" type="password" autocomplete="new-password" viewable :invalid="$errors->has('password')" />
                                @if ($msg = $this->visibleError('password'))
                                    <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                                @endif
                            </flux:field>
                            <flux:field>
                                <flux:label>Confirm password @if ($editingUserId === null || $password !== '') <span class="text-red-500">*</span> @endif</flux:label>
                                <flux:input wire:model="passwordConfirmation" type="password" autocomplete="new-password" viewable :invalid="$errors->has('passwordConfirmation')" />
                                @if ($msg = $this->visibleError('passwordConfirmation'))
                                    <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                                @endif
                            </flux:field>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <flux:button
                            type="button"
                            wire:click="cancelEdit"
                            variant="ghost"
                        >
                            Cancel
                        </flux:button>

                        <flux:button type="submit" variant="primary">
                            {{ $editingUserId !== null ? 'Save Changes' : 'Save Account' }}
                        </flux:button>
                    </div>
                </form>
        </flux:modal>
    </div>
    <flux:modal wire:model="showStatusDialog" class="md:w-[28rem]">
        @php($statusUser = $statusChangeUserId ? \App\Models\User::query()->find($statusChangeUserId) : null)
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Confirm account status change</flux:heading>
                <flux:text class="mt-1">
                    @if ($statusUser)
                        {{ $statusUser->status === 'Active' ? 'Deactivate' : 'Activate' }}
                        {{ $this->fullName($statusUser) }}?
                    @endif
                </flux:text>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showStatusDialog', false)">Cancel</flux:button>
                <flux:button type="button" variant="danger" wire:click="confirmStatusChange">Confirm</flux:button>
            </div>
        </div>
    </flux:modal>

</div>
