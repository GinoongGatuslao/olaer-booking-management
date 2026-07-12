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
                        })
                        ->orWhereHas('address', function (Builder $query) use ($like): void {
                            $query->where('province', 'like', $like)
                                ->orWhere('city', 'like', $like)
                                ->orWhere('barangay', 'like', $like)
                                ->orWhere('purok', 'like', $like);
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
            )->orderBy('last_name')->orderBy('first_name'),
            default => $query->orderBy('last_name', $sortDirection)
                ->orderBy('first_name', $sortDirection)
                ->orderBy('middle_name', $sortDirection),
        };

        return $query->paginate($perPage);
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
    }

    public function startEditingUser(int $userId): void
    {
        $user = User::query()
            ->with(['address', 'role'])
            ->findOrFail($userId);

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

        $this->resetValidation();
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
                Rule::unique('tbl_user', 'email')
                    ->ignore($this->editingUserId, 'user_id'),
            ],
            'contactNo' => ['required', 'regex:/^[0-9]{11}$/'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'roleId' => ['required', 'integer', 'exists:tbl_role,role_id'],
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
                $this->editingUserId !== null || $this->password !== ''
                    ? 'required'
                    : 'nullable',
                'same:password',
            ],
        ], [
            'firstName.required' => 'First name is required.',
            'lastName.required' => 'Last name is required.',
            'username.required' => 'Username is required.',
            'username.unique' => 'This username is already taken.',
            'email.required' => 'Email is required.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'This email is already used by another account.',
            'contactNo.required' => 'Contact number is required.',
            'contactNo.regex' => 'Contact number must be exactly 11 digits.',
            'status.in' => 'Status must be Active or Inactive.',
            'roleId.required' => 'Role is required.',
            'roleId.exists' => 'Selected role does not exist.',
            'province.required' => 'Province is required.',
            'city.required' => 'City is required.',
            'password.required' => 'Password is required for new users.',
            'password.min' => 'Password must be at least 8 characters.',
            'passwordConfirmation.required' => 'Password confirmation is required.',
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
                    'You cannot change your own role while logged in. Ask another administrator or manager.',
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

        $user = User::query()->findOrFail($userId);

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
            'Manager' => 'purple',
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
            <h1 class="text-2xl font-bold tracking-tight">User Management</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Paginated staff-account management with role and account-status controls.
            </p>
        </div>

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

    <div class="grid gap-6 2xl:grid-cols-[minmax(0,2fr)_minmax(22rem,1fr)]">
        <section class="min-w-0">
            <flux:card class="overflow-hidden p-0">
                <div class="border-b border-zinc-200 p-5 dark:border-zinc-800">
                    <div class="flex flex-col gap-4">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <h2 class="font-semibold">Staff accounts</h2>
                                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                    Search, filter, sort, edit, activate, or deactivate accounts.
                                </p>
                            </div>

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

                        <div class="grid gap-3 md:grid-cols-3">
                            <flux:input
                                wire:model.live.debounce.300ms="search"
                                label="Search"
                                placeholder="Name, username, email, address"
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
                                        Name {{ $this->sortIcon('full_name') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('username')"
                                        class="inline-flex items-center gap-1 font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Username {{ $this->sortIcon('username') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('role')"
                                        class="inline-flex items-center gap-1 font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Role {{ $this->sortIcon('role') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('status')"
                                        class="inline-flex items-center gap-1 font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Status {{ $this->sortIcon('status') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('email')"
                                        class="inline-flex items-center gap-1 font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Email {{ $this->sortIcon('email') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('contact_no')"
                                        class="inline-flex items-center gap-1 font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Contact {{ $this->sortIcon('contact_no') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3 text-right font-semibold">Actions</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($this->users as $user)
                                <tr wire:key="user-row-{{ $user->user_id }}" class="align-top">
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
                                        <div class="flex justify-end gap-2">
                                            <flux:button
                                                wire:click="startEditingUser({{ $user->user_id }})"
                                                size="sm"
                                                variant="ghost"
                                            >
                                                Edit
                                            </flux:button>

                                            <flux:button
                                                wire:click="toggleStatus({{ $user->user_id }})"
                                                wire:confirm="{{ $user->status === 'Active' ? 'Deactivate this account?' : 'Activate this account?' }}"
                                                size="sm"
                                                variant="ghost"
                                                :disabled="(int) $user->user_id === (int) auth()->id()"
                                            >
                                                {{ $user->status === 'Active' ? 'Deactivate' : 'Activate' }}
                                            </flux:button>
                                        </div>
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

        <aside>
            <flux:card>
                <div class="mb-5 flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">
                            {{ $editingUserId !== null ? 'Edit staff account' : 'Create staff account' }}
                        </h2>

                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Assign a resort role and login credentials.
                        </p>
                    </div>

                    @if ($editingUserId !== null)
                        <flux:button wire:click="createNewUser" size="sm" variant="ghost">
                            New
                        </flux:button>
                    @endif
                </div>

                <form wire:submit="saveUser" class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="firstName" label="First name" />
                        <flux:input wire:model="middleName" label="Middle name" />
                    </div>

                    <flux:input wire:model="lastName" label="Last name" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="username" label="Username" />
                        <flux:input wire:model="email" type="email" label="Email" />
                    </div>

                    <flux:input
                        wire:model="contactNo"
                        label="Contact number"
                        placeholder="09123456789"
                        maxlength="11"
                    />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:select wire:model="roleId" label="Role">
                            <option value="">Select role</option>
                            @foreach ($this->roles as $role)
                                <option value="{{ $role->role_id }}">
                                    {{ $role->role_name }}
                                </option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="status" label="Status">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </flux:select>
                    </div>

                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                        <h3 class="text-sm font-semibold">Address</h3>

                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <flux:input wire:model="province" label="Province" />
                            <flux:input wire:model="city" label="City / Municipality" />
                            <flux:input wire:model="barangay" label="Barangay" />
                            <flux:input wire:model="purok" label="Purok / Street" />
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
                            <flux:input
                                wire:model="password"
                                type="password"
                                label="Password"
                                autocomplete="new-password"
                            />

                            <flux:input
                                wire:model="passwordConfirmation"
                                type="password"
                                label="Confirm password"
                                autocomplete="new-password"
                            />
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <flux:button
                            type="button"
                            wire:click="resetForm"
                            variant="ghost"
                        >
                            Clear
                        </flux:button>

                        <flux:button type="submit" variant="primary">
                            {{ $editingUserId !== null ? 'Save Changes' : 'Create Account' }}
                        </flux:button>
                    </div>
                </form>
            </flux:card>
        </aside>
    </div>
</div>
