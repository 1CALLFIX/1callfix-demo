<?php

namespace App\Livewire\Badges;

use App\Models\Badge;
use App\Models\BadgeAssignment;
use App\Models\Country;
use App\Models\City;
use App\Models\Franchise;
use App\Models\Service;
use App\Models\Zone;
use App\Services\AuthorizationService;
use App\Services\BadgeService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Universal Badge Engine admin screen — two sections: badge DEFINITIONS
 * (the catalog of types: NEW, FEATURED, ...; active/inactive toggle only,
 * styling/rule editing is a direct-DB concern for now, same "not every
 * field needs a form control on day one" restraint as other admin-panel
 * additions this session) and MANUAL assignments (assign one to a Service,
 * with scope + optional expiry override; revoke without deleting history).
 * Automatic-mode badges (NEW) never appear in the assignment form — see
 * BadgeService::assign()'s own guard.
 */
class Manage extends Component
{
    use WithPagination;

    public string $section = 'definitions'; // definitions|assignments

    // --- Assign form ---
    public ?int $assignBadgeId = null;
    public string $serviceSearch = '';
    public ?int $selectedServiceId = null;
    public string $scopeType = 'global';
    public ?int $scopeCountryId = null;
    public ?int $scopeCityId = null;
    public ?int $scopeFranchiseId = null;
    public ?int $scopeZoneId = null;
    public string $customExpiresAt = '';

    public string $flashMessage = '';
    public string $flashType = 'success';

    /** badges.view was seeded (2026_08_14_006000) specifically for this screen. */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('badges.view'), 403, 'You do not have permission to view badges.');
    }

    /** Badge DEFINITIONS are global config (no natural scope of their own — a "type" isn't per-franchise), so toggling one only needs the permission anywhere, same as canReview()-style screens gate their own list. */
    private function canManage(): bool
    {
        return auth()->user()->hasPermissionAnywhere('badges.manage');
    }

    /** ASSIGNMENTS, unlike definitions, carry a real scope -- checked against it specifically, the same way bookings.cancel/providers.review_kyc are checked against their own target's scope rather than "holds it anywhere". */
    private function canManageScope(array $scope): bool
    {
        return auth()->user()->hasPermission('badges.manage', $scope);
    }

    public function setSection(string $section): void
    {
        $this->section = in_array($section, ['definitions', 'assignments'], true) ? $section : 'definitions';
    }

    public function updatedScopeType(): void { $this->reset(['scopeCountryId', 'scopeCityId', 'scopeFranchiseId', 'scopeZoneId']); }
    public function updatedScopeCountryId(): void { $this->scopeCityId = null; }
    public function updatedScopeFranchiseId(): void { $this->scopeZoneId = null; }

    public function toggleBadgeActive(int $badgeId): void
    {
        if (! $this->canManage()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage badges.';
            return;
        }

        $badge = Badge::findOrFail($badgeId);
        $badge->update(['is_active' => ! $badge->is_active]);
        $this->flashType = 'success';
        $this->flashMessage = 'Badge '.($badge->fresh()->is_active ? 'activated' : 'deactivated').'.';
    }

    public function getMatchingServicesProperty()
    {
        if ($this->selectedServiceId || mb_strlen(trim($this->serviceSearch)) < 2) {
            return collect();
        }

        return Service::where('name', 'like', '%'.$this->serviceSearch.'%')->orderBy('name')->limit(8)->get();
    }

    public function selectService(int $serviceId): void
    {
        $this->selectedServiceId = $serviceId;
        $this->serviceSearch = Service::find($serviceId)?->name ?? '';
    }

    private function resolvedScopeId(): ?int
    {
        return match ($this->scopeType) {
            'country' => $this->scopeCountryId,
            'city' => $this->scopeCityId,
            'franchise' => $this->scopeFranchiseId,
            'zone' => $this->scopeZoneId,
            default => null,
        };
    }

    public function assign(BadgeService $service): void
    {
        // Checked against the SCOPE BEING ASSIGNED TO (its full ancestry,
        // same as Plan's own scopeHint()) -- not just "holds badges.manage
        // anywhere" -- so a Zone Admin can assign within their own zone but
        // not silently plant a franchise- or country-wide badge.
        $targetScope = app(AuthorizationService::class)->ancestryFor($this->scopeType, $this->resolvedScopeId());
        if (! $this->canManageScope($targetScope)) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to assign badges at this scope.';
            return;
        }

        $this->validate([
            'assignBadgeId' => ['required', 'exists:badges,id'],
            'selectedServiceId' => ['required', 'exists:services,id'],
            'scopeType' => ['required', 'in:global,country,city,zone,franchise'],
            'customExpiresAt' => ['nullable', 'date', 'after:now'],
        ], [], ['assignBadgeId' => 'badge', 'selectedServiceId' => 'service']);

        $badge = Badge::findOrFail($this->assignBadgeId);
        $entity = Service::findOrFail($this->selectedServiceId);

        try {
            $service->assign(
                $badge,
                $entity,
                $this->scopeType,
                $this->resolvedScopeId(),
                $this->customExpiresAt ? \Illuminate\Support\Carbon::parse($this->customExpiresAt) : null,
                auth()->user(),
            );
            $this->flashType = 'success';
            $this->flashMessage = "Badge [{$badge->label}] assigned to {$entity->name}.";
            $this->reset(['assignBadgeId', 'serviceSearch', 'selectedServiceId', 'scopeType', 'scopeCountryId', 'scopeCityId', 'scopeFranchiseId', 'scopeZoneId', 'customExpiresAt']);
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function revoke(int $assignmentId, BadgeService $service): void
    {
        $assignment = BadgeAssignment::findOrFail($assignmentId);

        if (! $this->canManageScope($assignment->authorizationScopeHint())) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to revoke this badge assignment.';
            return;
        }

        $service->revoke($assignment);
        $this->flashType = 'success';
        $this->flashMessage = 'Badge assignment revoked.';
    }

    /**
     * Admin Command Center completion session, Growth Command Center phase
     * (2026-08-20) -- same shape as item 44 (FlashSales/PerformanceCampaigns):
     * BadgeAssignment carries the identical scope_type/scope_id flat pair as
     * Plan/NotificationCampaign/Payout, so visibleAmong() ran against the
     * FULL, eager-loaded table on every render() (no paginate() ever wired,
     * despite the class already `use`-ing WithPagination) before filtering
     * in-memory. Converted to the same lightweight-id-projection +
     * whereIn() + real paginate() pattern NotificationCenter\Manage/
     * Payouts\Manage/FlashSales\Manage already established for this exact
     * scope shape.
     */
    private function visibleAssignmentIds(): array
    {
        $candidates = BadgeAssignment::query()->select('id', 'scope_type', 'scope_id')->get();

        return app(AuthorizationService::class)
            ->visibleAmong($candidates, auth()->user(), 'badges.view')
            ->pluck('id')->all();
    }

    public function render()
    {
        $badges = Badge::orderByDesc('priority')->get();

        $assignments = BadgeAssignment::whereIn('id', $this->visibleAssignmentIds())
            ->with(['badge', 'badgeable'])
            ->latest()
            ->paginate(15, ['*'], 'assignmentsPage');

        return view('livewire.badges.manage', [
            'badges' => $badges,
            'assignments' => $assignments,
            'countries' => Country::where('is_active', true)->orderBy('name')->get(),
            'cities' => $this->scopeCountryId ? City::where('country_id', $this->scopeCountryId)->orderBy('name')->get() : collect(),
            'franchises' => Franchise::orderBy('name')->get(),
            'zones' => $this->scopeFranchiseId ? Zone::where('franchise_id', $this->scopeFranchiseId)->orderBy('name')->get() : collect(),
            'canManage' => $this->canManage(),
        ])->layout('layouts.admin', ['title' => 'Badges']);
    }
}
