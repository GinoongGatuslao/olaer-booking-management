<?php

use App\Models\Address;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app'), Title('User Management - Olaer Spring Resort')] class extends Component {
    public string $search = '';

    public string $roleFilter = '';

    public string $statusFilter = '';

    public string $sortField = 'full_name';

    public string $sortDirection = 'asc';

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

    #[Computed]
    public function roles(): EloquentCollection
    {
    return Role::query()
        ->orderBy('role_name')
        ->get();
    }

    #[Computed]
    public function users(): Collection
    {
    $search = trim($this->search);
    $sortDirection = $this->sortDirection === 'desc' ? 'desc' : 'asc';

    $users = User::query()
        ->with(['role', 'address'])
        ->when($this->roleFilter !== '', function ($query) {
            $query->where('role_id', $this->roleFilter);
        })
        ->when($this->statusFilter !== '', function ($query) {
            $query->where('status', $this->statusFilter);
        })
        ->when($search !== '', function ($query) use ($search) {
            $like = '%' . $search . '%';

            $query->where(function ($query) use ($like) {
                $query->where('first_name', 'like', $like)
                    ->orWhere('middle_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('username', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('contact_no', 'like', $like)
                    ->orWhere('status', 'like', $like)
                    ->orWhereHas('role', function ($query) use ($like) {
                        $query->where('role_name', 'like', $like);
                    })
                    ->orWhereHas('address', function ($query) use ($like) {
                        $query->where('province', 'like', $like)
                            ->orWhere('city', 'like', $like)
                            ->orWhere('barangay', 'like', $like)
                            ->orWhere('purok', 'like', $like);
                    });
            });
        })
        ->get();

    return match ($this->sortField) {
        'username' => $sortDirection === 'asc'
            ? $users->sortBy('username', SORT_NATURAL | SORT_FLAG_CASE)->values()
            : $users->sortByDesc('username', SORT_NATURAL | SORT_FLAG_CASE)->values(),
        'email' => $sortDirection === 'asc'
            ? $users->sortBy('email', SORT_NATURAL | SORT_FLAG_CASE)->values()
            : $users->sortByDesc('email', SORT_NATURAL | SORT_FLAG_CASE)->values(),
        'contact_no' => $sortDirection === 'asc'
            ? $users->sortBy('contact_no', SORT_NATURAL | SORT_FLAG_CASE)->values()
            : $users->sortByDesc('contact_no', SORT_NATURAL | SORT_FLAG_CASE)->values(),
        'role' => $sortDirection === 'asc'
            ? $users->sortBy(fn (User $user) => $user->role?->role_name ?? '', SORT_NATURAL | SORT_FLAG_CASE)->values()
            : $users->sortByDesc(fn (User $user) => $user->role?->role_name ?? '', SORT_NATURAL | SORT_FLAG_CASE)->values(),
        'status' => $sortDirection === 'asc'
            ? $users->sortBy('status', SORT_NATURAL | SORT_FLAG_CASE)->values()
            : $users->sortByDesc('status', SORT_NATURAL | SORT_FLAG_CASE)->values(),
        default => $sortDirection === 'asc'
            ? $users->sortBy(fn (User $user) => $this->getFullName($user), SORT_NATURAL | SORT_FLAG_CASE)->values()
            : $users->sortByDesc(fn (User $user) => $this->getFullName($user), SORT_NATURAL | SORT_FLAG_CASE)->values(),
    };
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

    $this->editingUserId = $user->user_id;
    $this->firstName = $user->first_name;
    $this->middleName = $user->middle_name ?? '';
    $this->lastName = $user->last_name;
    $this->username = $user->username;
    $this->email = $user->email;
    $this->contactNo = $user->contact_no;
    $this->status = $user->status;
    $this->roleId = (string) $user->role_id;
    $this->province = $user->address?->province ?? '';
    $this->city = $user->address?->city ?? '';
    $this->barangay = $user->address?->barangay ?? '';
    $this->purok = $user->address?->purok ?? '';
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
    $this->roleId = $this->roles->first()?->role_id ? (string) $this->roles->first()->role_id : '';
    $this->province = '';
    $this->city = '';
    $this->barangay = '';
    $this->purok = '';
    $this->password = '';
    $this->passwordConfirmation = '';

    $this->resetValidation();
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
        return;
    }

    $this->sortField = $field;
    $this->sortDirection = 'asc';
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
            Rule::unique('tbl_user', 'username')->ignore($this->editingUserId, 'user_id'),
        ],
        'email' => [
            'required',
            'email',
            'max:50',
            Rule::unique('tbl_user', 'email')->ignore($this->editingUserId, 'user_id'),
        ],
        'contactNo' => ['required', 'regex:/^[0-9]{11}$/'],
        'status' => ['required', Rule::in(['Active', 'Inactive'])],
        'roleId' => ['required', 'integer', 'exists:tbl_role,role_id'],
        'province' => ['required', 'string', 'max:50'],
        'city' => ['required', 'string', 'max:50'],
        'barangay' => ['nullable', 'string', 'max:50'],
        'purok' => ['nullable', 'string', 'max:50'],
        'password' => [$this->editingUserId ? 'nullable' : 'required', 'string', 'min:8', 'max:255'],
        'passwordConfirmation' => [! $this->editingUserId || $this->password !== '' ? 'required' : 'nullable', 'same:password'],
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

    $clean = function (?string $value): ?string {
        $value = trim(preg_replace('/\s+/', ' ', (string) $value));
        return $value === '' ? null : $value;
    };

    if ($this->editingUserId && (int) $this->editingUserId === (int) Auth::id()) {
        $currentUser = User::query()->findOrFail(Auth::id());

        if ((int) $validated['roleId'] !== (int) $currentUser->role_id) {
            $this->addError('roleId', 'You cannot change your own role while logged in. Ask another admin/manager to do it.');
            return;
        }

        if ($validated['status'] !== $currentUser->status) {
            $this->addError('status', 'You cannot activate/deactivate your own account while logged in.');
            return;
        }
    }

    DB::transaction(function () use ($validated, $clean): void {
        if ($this->editingUserId) {
            $user = User::query()->with('address')->findOrFail($this->editingUserId);

            $user->address()->update([
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

        if ((string) $validated['password'] !== '') {
            // User model has Laravel's hashed cast, so assigning plain text here is intentionally hashed by Eloquent.
            $payload['password'] = $validated['password'];
        }

        $user->fill($payload);
        $user->save();
    });

    session()->flash('success', $this->editingUserId ? 'User account updated successfully.' : 'User account created successfully.');
    $this->resetForm();
    }

    public function toggleStatus(int $userId): void
    {
    if ((int) $userId === (int) Auth::id()) {
        session()->flash('error', 'You cannot activate/deactivate your own account while logged in.');
        return;
    }

    $user = User::query()->findOrFail($userId);
    $user->update([
        'status' => $user->status === 'Active' ? 'Inactive' : 'Active',
    ]);

    session()->flash('success', $user->full_name . ' is now ' . $user->status . '.');
    }

    public function getFullName(User $user): string
    {
    return trim(implode(' ', array_filter([
        $user->first_name,
        $user->middle_name,
        $user->last_name,
    ])));
    }

    public function getAddressLine(User $user): string
    {
    if (! $user->address) {
        return 'No address set';
    }

    return trim(implode(', ', array_filter([
        $user->address->purok,
        $user->address->barangay,
        $user->address->city,
        $user->address->province,
    ])));
    }

    public function getSortIcon(string $field): string
    {
    if ($this->sortField !== $field) {
        return '↕';
    }

    return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    public function getStatusBadgeClass(string $status): string
    {
    return match ($status) {
        'Active' => 'bg-green-50 text-green-700 ring-1 ring-green-600/20 dark:bg-green-950/40 dark:text-green-300',
        'Inactive' => 'bg-red-50 text-red-700 ring-1 ring-red-600/20 dark:bg-red-950/40 dark:text-red-300',
        default => 'bg-zinc-100 text-zinc-700 ring-1 ring-zinc-600/10 dark:bg-zinc-800 dark:text-zinc-300',
    };
    }

    public function getRoleBadgeClass(?string $roleName): string
    {
    return match ($roleName) {
        'Admin' => 'bg-zinc-900 text-white ring-1 ring-zinc-700 dark:bg-white dark:text-zinc-900',
        'Manager' => 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-600/20 dark:bg-indigo-950/40 dark:text-indigo-300',
        'Cashier' => 'bg-blue-50 text-blue-700 ring-1 ring-blue-600/20 dark:bg-blue-950/40 dark:text-blue-300',
        'Maintenance Staff' => 'bg-amber-50 text-amber-700 ring-1 ring-amber-600/20 dark:bg-amber-950/40 dark:text-amber-300',
        'Security Guard' => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-300',
        default => 'bg-zinc-100 text-zinc-700 ring-1 ring-zinc-600/10 dark:bg-zinc-800 dark:text-zinc-300',
    };
    }
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">User Management</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Manage staff accounts, roles, and account status.
            </p>
        </div>

        <a href="{{ route('admin.dashboard') }}" class="text-sm font-medium text-zinc-600 hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-white">
            Back to dashboard
        </a>
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
        This module is for resort staff accounts only. Guest records are handled separately in the reservation and booking flows.
    </div>

    <div class="grid gap-6 2xl:grid-cols-3">
        <section class="2xl:col-span-2">
            <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <h2 class="font-semibold">User list</h2>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                Search, filter, sort, edit, or activate/deactivate accounts.
                            </p>
                        </div>

                        <div class="grid gap-3 md:grid-cols-3 lg:w-[48rem]">
                            <flux:input
                                wire:model.live.debounce.300ms="search"
                                label="Search"
                                placeholder="Name, username, email, role"
                                clearable
                            />

                            <div>
                                <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Role</label>
                                <select
                                    wire:model.live="roleFilter"
                                    class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                                >
                                    <option value="">All roles</option>
                                    @foreach ($this->roles as $role)
                                        <option value="{{ $role->role_id }}">{{ $role->role_name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Status</label>
                                <select
                                    wire:model.live="statusFilter"
                                    class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                                >
                                    <option value="">All statuses</option>
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 text-left text-sm dark:divide-zinc-800">
                        <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-950/60 dark:text-zinc-400">
                            <tr>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('full_name')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Name <span>{{ $this->getSortIcon('full_name') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('username')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Username <span>{{ $this->getSortIcon('username') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('role')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Role <span>{{ $this->getSortIcon('role') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('status')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Status <span>{{ $this->getSortIcon('status') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('contact_no')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Contact <span>{{ $this->getSortIcon('contact_no') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 text-right font-semibold">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($this->users as $user)
                                <tr wire:key="user-{{ $user->user_id }}">
                                    <td class="max-w-md px-5 py-4">
                                        <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ $this->getFullName($user) }}
                                            @if ((int) $user->user_id === (int) auth()->id())
                                                <span class="ml-1 text-xs font-normal text-zinc-500">(you)</span>
                                            @endif
                                        </div>
                                        <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $user->email }}
                                        </div>
                                        <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $this->getAddressLine($user) }}
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        {{ $user->username }}
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $this->getRoleBadgeClass($user->role?->role_name) }}">
                                            {{ $user->role?->role_name ?? 'No role' }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $this->getStatusBadgeClass($user->status) }}">
                                            {{ $user->status }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        {{ $user->contact_no }}
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <div class="flex justify-end gap-2">
                                            <flux:button
                                                type="button"
                                                size="sm"
                                                variant="subtle"
                                                wire:click="startEditingUser({{ $user->user_id }})"
                                            >
                                                Edit
                                            </flux:button>

                                            <flux:button
                                                type="button"
                                                size="sm"
                                                variant="subtle"
                                                wire:click="toggleStatus({{ $user->user_id }})"
                                                wire:confirm="{{ $user->status === 'Active' ? 'Deactivate this account?' : 'Activate this account?' }}"
                                                :disabled="(int) $user->user_id === (int) auth()->id()"
                                            >
                                                {{ $user->status === 'Active' ? 'Deactivate' : 'Activate' }}
                                            </flux:button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                        No users found.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section>
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-semibold">{{ $editingUserId ? 'Edit user account' : 'Create user account' }}</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Assign staff role and login credentials.
                        </p>
                    </div>

                    @if ($editingUserId)
                        <flux:button type="button" size="sm" variant="subtle" wire:click="createNewUser">
                            New
                        </flux:button>
                    @endif
                </div>

                <form wire:submit="saveUser" class="mt-5 space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="firstName" label="First name" />
                        <flux:input wire:model="middleName" label="Middle name" />
                    </div>

                    <flux:input wire:model="lastName" label="Last name" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="username" label="Username" />
                        <flux:input wire:model="email" type="email" label="Email" />
                    </div>

                    <flux:input wire:model="contactNo" label="Contact number" placeholder="09123456789" maxlength="11" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Role</label>
                            <select
                                wire:model="roleId"
                                class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                            >
                                <option value="">Select role</option>
                                @foreach ($this->roles as $role)
                                    <option value="{{ $role->role_id }}">{{ $role->role_name }}</option>
                                @endforeach
                            </select>
                            @error('roleId')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Status</label>
                            <select
                                wire:model="status"
                                class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                            >
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                            @error('status')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                        <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Address</h3>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <flux:input wire:model="province" label="Province" />
                            <flux:input wire:model="city" label="City/Municipality" />
                            <flux:input wire:model="barangay" label="Barangay" />
                            <flux:input wire:model="purok" label="Purok/Street" />
                        </div>
                    </div>

                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                        <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Password</h3>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $editingUserId ? 'Leave password fields blank to keep the existing password.' : 'Password is required for new accounts.' }}
                        </p>

                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <flux:input wire:model="password" type="password" label="Password" autocomplete="new-password" viewable />
                            <flux:input wire:model="passwordConfirmation" type="password" label="Confirm password" autocomplete="new-password" viewable />
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <flux:button type="button" variant="subtle" wire:click="resetForm">
                            Clear
                        </flux:button>

                        <flux:button type="submit" variant="primary">
                            {{ $editingUserId ? 'Save changes' : 'Create user' }}
                        </flux:button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</div>
