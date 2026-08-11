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

        RoleAssignment::findOrFail($this->confirmingRevokeId)->delete();
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
