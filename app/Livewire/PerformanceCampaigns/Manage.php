<?php

namespace App\Livewire\PerformanceCampaigns;

use App\Models\Badge;
use App\Models\PerformanceCampaign;
use App\Services\AuthorizationService;
use App\Services\Campaigns\CampaignMetricResolver;
use App\Services\PerformanceCampaignService;
use Livewire\Component;

/**
 * Performance/Growth Campaign admin screen — create (draft), lifecycle
 * actions, progress refresh, and the approve->disburse reward gate. Scope-
 * checked from the start (same discipline as Badges/FlashSales this
 * session). Reward disbursement is gated by the separate
 * performance_campaigns.approve permission, never by .manage alone.
 */
class Manage extends Component
{
    // --- Create form ---
    public string $name = '';
    public string $description = '';
    public string $audienceType = 'provider';
    public string $metricKey = 'bookings_completed_count';
    public string $qualificationMode = 'threshold';
    public string $targetValue = '';
    public string $topN = '';
    public string $rewardType = 'wallet_credit';
    public string $rewardValue = '';
    public ?int $badgeId = null;
    public string $startsAt = '';
    public string $endsAt = '';
    public string $scopeType = 'global';
    public ?int $scopeCountryId = null;
    public ?int $scopeCityId = null;
    public ?int $scopeFranchiseId = null;
    public ?int $scopeZoneId = null;

    public string $flashMessage = '';
    public string $flashType = 'success';

    public ?int $expandedCampaignId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('performance_campaigns.view'), 403, 'You do not have permission to view performance campaigns.');
    }

    public function updatedScopeType(): void { $this->reset(['scopeCountryId', 'scopeCityId', 'scopeFranchiseId', 'scopeZoneId']); }
    public function updatedScopeFranchiseId(): void { $this->scopeZoneId = null; }

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

    private function canManageScope(array $scope): bool
    {
        return auth()->user()->hasPermission('performance_campaigns.manage', $scope);
    }

    private function canApproveScope(array $scope): bool
    {
        return auth()->user()->hasPermission('performance_campaigns.approve', $scope);
    }

    public function create(): void
    {
        $targetScope = app(AuthorizationService::class)->ancestryFor($this->scopeType, $this->resolvedScopeId());
        if (! $this->canManageScope($targetScope)) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to create a campaign at this scope.';
            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'audienceType' => ['required', 'in:franchise,provider,field_worker,customer'],
            'metricKey' => ['required', 'in:'.implode(',', CampaignMetricResolver::SUPPORTED)],
            'qualificationMode' => ['required', 'in:threshold,top_n'],
            'rewardType' => ['required', 'in:wallet_credit,loyalty_points,badge'],
            'scopeType' => ['required', 'in:global,country,city,zone,franchise'],
        ]);

        if ($this->qualificationMode === 'threshold' && $this->targetValue === '') {
            $this->addError('targetValue', 'A target value is required for threshold-mode qualification.');
            return;
        }
        if ($this->qualificationMode === 'top_n' && $this->topN === '') {
            $this->addError('topN', 'A top-N count is required for top-N-mode qualification.');
            return;
        }
        if ($this->rewardType === 'badge' && ! $this->badgeId) {
            $this->addError('badgeId', 'A reward badge is required when the reward type is "badge".');
            return;
        }
        if ($this->rewardType !== 'badge' && ($this->rewardValue === '' || (float) $this->rewardValue <= 0)) {
            $this->addError('rewardValue', 'A positive reward value is required for this reward type.');
            return;
        }

        PerformanceCampaign::create([
            'name' => $this->name, 'description' => $this->description ?: null,
            'audience_type' => $this->audienceType, 'metric_key' => $this->metricKey,
            'qualification_mode' => $this->qualificationMode,
            'target_value' => $this->qualificationMode === 'threshold' ? $this->targetValue : null,
            'top_n' => $this->qualificationMode === 'top_n' ? (int) $this->topN : null,
            'reward_type' => $this->rewardType,
            'reward_value' => $this->rewardType !== 'badge' ? $this->rewardValue : null,
            'badge_id' => $this->rewardType === 'badge' ? $this->badgeId : null,
            'starts_at' => $this->startsAt ?: null,
            'ends_at' => $this->endsAt ?: null,
            'scope_type' => $this->scopeType, 'scope_id' => $this->scopeType === 'global' ? null : $this->resolvedScopeId(),
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]);

        $this->reset(['name', 'description', 'targetValue', 'topN', 'rewardValue', 'badgeId', 'startsAt', 'endsAt', 'scopeCountryId', 'scopeCityId', 'scopeFranchiseId', 'scopeZoneId']);
        $this->flashType = 'success';
        $this->flashMessage = 'Campaign created as a draft.';
    }

    public function lifecycleAction(int $campaignId, string $action, PerformanceCampaignService $service): void
    {
        $campaign = PerformanceCampaign::findOrFail($campaignId);
        if (! $this->canManageScope($campaign->authorizationScopeHint())) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage this campaign.';
            return;
        }

        if (! in_array($action, ['schedule', 'activate', 'pause', 'resume', 'cancel', 'complete', 'submitForReview', 'reopen', 'refreshProgress', 'close'], true)) {
            return;
        }

        try {
            $service->{$action}($campaign);
            $this->flashType = 'success';
            $this->flashMessage = 'Campaign updated.';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    /** Separate from lifecycleAction() — this is the one action that unlocks money moving, and requires the elevated .approve permission. */
    public function approve(int $campaignId, PerformanceCampaignService $service): void
    {
        $campaign = PerformanceCampaign::findOrFail($campaignId);
        if (! $this->canApproveScope($campaign->authorizationScopeHint())) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to approve this campaign.';
            return;
        }

        try {
            $service->approve($campaign);
            $this->flashType = 'success';
            $this->flashMessage = 'Campaign approved.';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function disburse(int $campaignId, PerformanceCampaignService $service): void
    {
        $campaign = PerformanceCampaign::findOrFail($campaignId);
        if (! $this->canApproveScope($campaign->authorizationScopeHint())) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to disburse rewards for this campaign.';
            return;
        }

        try {
            $service->disburse($campaign);
            $this->flashType = 'success';
            $this->flashMessage = 'Rewards disbursed.';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function toggleParticipants(int $campaignId): void
    {
        $this->expandedCampaignId = $this->expandedCampaignId === $campaignId ? null : $campaignId;
    }

    public function getExpandedParticipantsProperty()
    {
        if (! $this->expandedCampaignId) {
            return collect();
        }

        return \App\Models\PerformanceCampaignParticipant::where('performance_campaign_id', $this->expandedCampaignId)
            ->orderByDesc('metric_value')
            ->limit(100)
            ->get()
            ->map(function ($p) {
                $actorClass = $p->participant_type;
                $actor = $actorClass::find($p->participant_id);
                $label = $actor?->name ?? $actor?->user?->name ?? null;
                $p->setAttribute('actor_label', $label ?? "#{$p->participant_id}");

                return $p;
            });
    }

    public function render()
    {
        $authz = app(AuthorizationService::class);

        $campaigns = $authz->visibleAmong(
            PerformanceCampaign::with(['badge', 'creator'])->withCount('participants')->latest()->get(),
            auth()->user(),
            'performance_campaigns.view',
        );

        return view('livewire.performance-campaigns.manage', [
            'campaigns' => $campaigns,
            'metrics' => CampaignMetricResolver::SUPPORTED,
            'countries' => \App\Models\Country::where('is_active', true)->orderBy('name')->get(),
            'cities' => $this->scopeCountryId ? \App\Models\City::where('country_id', $this->scopeCountryId)->orderBy('name')->get() : collect(),
            'franchises' => \App\Models\Franchise::orderBy('name')->get(),
            'zones' => $this->scopeFranchiseId ? \App\Models\Zone::where('franchise_id', $this->scopeFranchiseId)->orderBy('name')->get() : collect(),
            'badges' => Badge::where('mode', 'manual')->orderBy('label')->get(),
        ])->layout('layouts.admin', ['title' => 'Performance Campaigns']);
    }
}
