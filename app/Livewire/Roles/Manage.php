<?php

namespace App\Livewire\Roles;

use App\Models\City;
use App\Models\Country;
use App\Models\Franchise;
use App\Models\Module;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Models\Zone;
use Livewire\Component;
use Livewire\WithPagination;

// RBAC admin screen: grant a Role to a User at a chosen scope. The Role /
// Permission catalog itself is seeded (see the create_roles/permissions
// migrations) and read-only here — v1 is "assign the seven system roles at
// a scope", not a role/permission builder UI. Enforcement lives in
// AuthorizationService::can(), called from each gated action
// (Bookings\Show::cancel()/reassign(), Franchises\Manage::save() so far).
class Manage extends Component
{
    use WithPagination;

    /**
     * No view-level check existed at all — only write actions checked
     * roles.manage (Phase 11 audit finding, same bug class addendum #1
     * already fixed on 15 other screens). A particularly serious instance:
     * the entire RBAC role-assignment matrix was browsable by any
     * admin-panel actor of any role. No separate roles.view permission was
     * ever seeded, so this reuses roles.manage.
     */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('roles.manage'), 403, 'You do not have permission to view roles & permissions.');
    }

    public string $userSearch = '';
    public ?int $selectedUserId = null;
    public ?int $roleId = null;
    public string $scopeType = 'global'; // global|country|city|zone|franchise|module
    public ?int $scopeCountryId = null;
    public ?int $scopeCityId = null;
    public ?int $scopeZoneId = null;
    public ?int $scopeFranchiseId = null;
    public ?int $scopeModuleId = null;

    public string $flashMessage = '';
    public string $flashType = 'success';

    public ?int $confirmingRevokeId = null;

    // --- Inline "+ New staff user" quick-add — there was nowhere else in
    // the admin panel to create the User record in the first place before
    // it could be granted a role here. Same inline-quick-add-since-there's-
    // nowhere-else-yet pattern as Franchises\Manage's Country/City forms.
    // Gated at global scope only (not per-scope like assign/revoke) since
    // minting a brand-new staff identity is a coarser act than granting an
    // existing one a scoped role — the account alone grants no admin
    // access until a RoleAssignment is made, but only a global roles.manage
    // holder can mint one at all.
    public bool $showNewUserForm = false;
    public string $newUserName = '';
    public string $newUserPhone = '';
    public string $newUserEmail = '';
    public string $newUserPassword = '';
    public string $newUserAccountType = 'zone_manager';

    public function getMatchingUsersProperty()
    {
        if (mb_strlen($this->userSearch) < 2) {
            return collect();
        }

        return User::where('name', 'like', "%{$this->userSearch}%")
            ->orWhere('phone', 'like', "%{$this->userSearch}%")
            ->orderBy('name')
            ->limit(8)
            ->get();
    }

    public function selectUser(int $userId): void
    {
        $this->selectedUserId = $userId;
        $this->userSearch = User::find($userId)?->name ?? '';
    }

    public function toggleNewUserForm(): void
    {
        $this->showNewUserForm = ! $this->showNewUserForm;
        $this->reset(['newUserName', 'newUserPhone', 'newUserEmail', 'newUserPassword']);
        $this->newUserAccountType = 'zone_manager';
        $this->resetValidation(['newUserName', 'newUserPhone', 'newUserEmail', 'newUserPassword', 'newUserAccountType']);
    }

    public function createUser(): void
    {
        if (! auth()->user()->hasPermission('roles.manage')) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to create staff accounts.';
            return;
        }

        $this->validate([
            'newUserName' => ['required', 'string', 'max:255'],
            'newUserPhone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'newUserEmail' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'newUserPassword' => ['required', 'string', 'min:8'],
            'newUserAccountType' => ['required', 'in:franchise_owner,zone_manager,super_admin'],
        ], [], [
            'newUserName' => 'name', 'newUserPhone' => 'phone', 'newUserEmail' => 'email',
            'newUserPassword' => 'password', 'newUserAccountType' => 'account type',
        ]);

        $user = User::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => $this->newUserName,
            'phone' => $this->newUserPhone,
            'email' => $this->newUserEmail ?: null,
            'password' => bcrypt($this->newUserPassword),
            'role' => $this->newUserAccountType,
            'status' => 'active',
        ]);

        // Feed straight into the assign form below — creating a staff
        // account with no role_assignment yet is a dead end on its own.
        $this->selectedUserId = $user->id;
        $this->userSearch = $user->name;
        $this->showNewUserForm = false;
        $this->reset(['newUserName', 'newUserPhone', 'newUserEmail', 'newUserPassword']);
        $this->newUserAccountType = 'zone_manager';

        $this->flashType = 'success';
        $this->flashMessage = 'Staff account created — now assign a role below.';
    }

    public function updatedScopeType(): void
    {
        $this->reset(['scopeCountryId', 'scopeCityId', 'scopeZoneId', 'scopeFranchiseId', 'scopeModuleId']);
    }

    private function scopeTypeAndId(): array
    {
        return match ($this->scopeType) {
            'country' => ['country', $this->scopeCountryId],
            'city' => ['city', $this->scopeCityId],
            'zone' => ['zone', $this->scopeZoneId],
            'franchise' => ['franchise', $this->scopeFranchiseId],
            'module' => ['module', $this->scopeModuleId],
            default => ['global', null],
        };
    }

    /** ['franchise_id' => 5] shape hasPermission()/AuthorizationService expect — [] for global. */
    private function scopeArrayFor(string $scopeType, ?int $scopeId): array
    {
        return $scopeType === 'global' ? [] : array_filter(["{$scopeType}_id" => $scopeId]);
    }

    public function assign(): void
    {
        $this->validate([
            'selectedUserId' => ['required', 'exists:users,id'],
            'roleId' => ['required', 'exists:roles,id'],
        ], [], ['selectedUserId' => 'user', 'roleId' => 'role']);

        [$scopeType, $scopeId] = $this->scopeTypeAndId();

        if ($scopeType !== 'global' && ! $scopeId) {
            $this->flashType = 'error';
            $this->flashMessage = 'Pick a '.$scopeType.' to scope this assignment to.';
            return;
        }

        // Was completely unguarded — any admin-panel actor (any single
        // role_assignment at any scope) could grant anyone super_admin at
        // global scope. Same pattern as every other gated action in this
        // app: check roles.manage at the scope the NEW assignment targets,
        // so a franchise-scoped roles.manage holder can only grant roles
        // within their own franchise, never escalate to global.
        if (! auth()->user()->hasPermission('roles.manage', $this->scopeArrayFor($scopeType, $scopeId))) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to assign roles at this scope.';
            return;
        }

        $exists = RoleAssignment::where([
            'user_id' => $this->selectedUserId,
            'role_id' => $this->roleId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
        ])->exists();

        if ($exists) {
            $this->flashType = 'error';
            $this->flashMessage = 'This user already holds that role at that exact scope.';
            return;
        }

        RoleAssignment::create([
            'user_id' => $this->selectedUserId,
            'role_id' => $this->roleId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
        ]);

        $this->reset(['userSearch', 'selectedUserId', 'roleId', 'scopeType', 'scopeCountryId', 'scopeCityId', 'scopeZoneId', 'scopeFranchiseId', 'scopeModuleId']);
        $this->flashType = 'success';
        $this->flashMessage = 'Role assigned.';
    }

    public function confirmRevoke(int $assignmentId): void { $this->confirmingRevokeId = $assignmentId; }
    public function cancelRevoke(): void { $this->confirmingRevokeId = null; }

    public function revoke(): void
    {
        if (! $this->confirmingRevokeId) {
            return;
        }

        $assignment = RoleAssignment::findOrFail($this->confirmingRevokeId);

        // Same check as assign(), against the assignment's own scope — a
        // franchise-scoped roles.manage holder can revoke roles inside
        // their own franchise, never a global or another franchise's grant.
        if (! auth()->user()->hasPermission('roles.manage', $this->scopeArrayFor($assignment->scope_type, $assignment->scope_id))) {
            $this->confirmingRevokeId = null;
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to revoke a role assignment at this scope.';
            return;
        }

        $assignment->delete();
        $this->confirmingRevokeId = null;
        $this->flashType = 'success';
        $this->flashMessage = 'Role assignment revoked.';
    }

    public function render()
    {
        return view('livewire.roles.manage', [
            'roles' => Role::with('permissions')->orderBy('name')->get(),
            'assignments' => RoleAssignment::with(['user', 'role'])->latest()->paginate(15),
            'countries' => Country::where('is_active', true)->orderBy('name')->get(),
            'cities' => $this->scopeCountryId ? City::where('country_id', $this->scopeCountryId)->where('is_active', true)->orderBy('name')->get() : collect(),
            'zones' => $this->scopeFranchiseId ? Zone::where('franchise_id', $this->scopeFranchiseId)->where('is_active', true)->orderBy('name')->get() : collect(),
            'franchises' => Franchise::where('status', 'active')->orderBy('name')->get(),
            'modules' => Module::where('is_active', true)->orderBy('sort_order')->get(),
        ])->layout('layouts.admin', ['title' => 'Roles & Permissions']);
    }
}
