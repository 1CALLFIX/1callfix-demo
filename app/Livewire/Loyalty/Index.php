<?php

namespace App\Livewire\Loyalty;

use App\Models\LoyaltyPoint;
use App\Models\Referral;
use App\Models\Setting;
use App\Services\AuthorizationService;
use App\Services\ReferralService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * loyalty_points and referrals are both real, actively-written ledgers
 * (LoyaltyService::earn/redeem, ReferralService) with no admin browsing
 * screen. One combined screen, two tabs -- same "one Manage screen"
 * reasoning as the other consolidated admin screens this session, not two
 * separate near-empty pages. Read-only, same as Wallet Ledger.
 */
class Index extends Component
{
    use WithPagination;

    public string $activeTab = 'points'; // points|referrals
    public string $search = '';
    public string $referralStatusFilter = ''; // '' | pending | rewarded | expired | fraud_flagged

    // --- Fraud flag form ---
    public ?int $flaggingReferralId = null;
    public string $fraudNotes = '';

    public string $flashMessage = '';
    public string $flashType = 'success';

    protected $queryString = ['activeTab', 'search', 'referralStatusFilter'];

    /**
     * loyalty.view was seeded (2026_08_11_052000) but never checked -- see
     * Commissions\Index's identical fix for the full reasoning.
     *
     * Row-level scoping (was deferred here, now closed): loyalty_points
     * scopes through its own earner (user.*); referrals scopes through the
     * REFERRER (referrer.*) -- the reward accrues to them, matching
     * ReferralService's own "reward the referrer" reasoning, not the
     * referred-in customer who may belong to a different scope entirely.
     */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('loyalty.view'), 403, 'You do not have permission to view loyalty points & referrals.');
    }

    public function updatingSearch() { $this->resetPage(); }
    public function updatingReferralStatusFilter() { $this->resetPage(); }

    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['points', 'referrals'], true) ? $tab : 'points';
        $this->resetPage();
    }

    public function startFlagging(int $referralId): void
    {
        $this->flaggingReferralId = $referralId;
        $this->fraudNotes = '';
    }

    /**
     * loyalty.manage was seeded (2026_08_14_012000) specifically for this
     * new mutation -- checked against the REFERRAL's own referrer scope
     * (same reasoning the .view row-scoping above already uses: the
     * reward accrued to the referrer, so that's whose scope governs who
     * can act on it), not just "holds loyalty.manage anywhere".
     */
    public function flagFraud(ReferralService $service): void
    {
        $referral = Referral::with('referrer')->findOrFail($this->flaggingReferralId);
        $referral->loadMissing('referrer.franchise');

        $scope = array_filter([
            'zone_id' => $referral->referrer?->zone_id,
            'franchise_id' => $referral->referrer?->franchise_id,
            'city_id' => $referral->referrer?->franchise?->city_id,
            'country_id' => $referral->referrer?->franchise?->country_id,
        ]);

        if (! auth()->user()->hasPermission('loyalty.manage', $scope)) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage this referral.';
            return;
        }

        $this->validate(['fraudNotes' => ['required', 'string', 'max:500']]);

        $service->flagAsFraud($referral, auth()->user(), $this->fraudNotes);

        $this->flashType = 'success';
        $this->flashMessage = 'Referral flagged as fraud.';
        $this->flaggingReferralId = null;
        $this->fraudNotes = '';
    }

    public function render()
    {
        if ($this->activeTab === 'referrals') {
            $referralColumns = ['zone_id' => 'referrer.zone_id', 'franchise_id' => 'referrer.franchise_id', 'city_id' => 'referrer.franchise.city_id', 'country_id' => 'referrer.franchise.country_id'];

            $referrals = app(AuthorizationService::class)
                ->scopeQuery(Referral::query(), auth()->user(), 'loyalty.view', $referralColumns)
                ->with(['referrer', 'referred', 'qualifyingBooking'])
                ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                    $w->whereHas('referrer', fn ($r) => $r->where('name', 'like', "%{$this->search}%")->orWhere('phone', 'like', "%{$this->search}%"))
                      ->orWhereHas('referred', fn ($r) => $r->where('name', 'like', "%{$this->search}%")->orWhere('phone', 'like', "%{$this->search}%"));
                }))
                ->when($this->referralStatusFilter !== '', fn ($q) => $q->where('status', $this->referralStatusFilter))
                ->latest()
                ->paginate(25);

            return view('livewire.loyalty.index', [
                'referrals' => $referrals,
                'points' => null,
                'totalPointsEarned' => null,
                'totalPointsRedeemed' => null,
                'canManageAnywhere' => auth()->user()->hasPermissionAnywhere('loyalty.manage'),
                'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
            ])->layout('layouts.admin', ['title' => 'Loyalty & Referrals']);
        }

        $pointsColumns = ['zone_id' => 'user.zone_id', 'franchise_id' => 'user.franchise_id', 'city_id' => 'user.franchise.city_id', 'country_id' => 'user.franchise.country_id'];

        $pointsQuery = app(AuthorizationService::class)
            ->scopeQuery(LoyaltyPoint::query(), auth()->user(), 'loyalty.view', $pointsColumns)
            ->with(['user', 'booking'])
            ->when($this->search !== '', fn ($q) => $q->whereHas('user', fn ($u) => $u
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")));

        // Clone BEFORE ->latest()->paginate() mutates the builder with
        // ORDER BY/LIMIT -- otherwise the totals query would inherit the
        // pagination LIMIT and undercount.
        $totals = (clone $pointsQuery)
            ->selectRaw('SUM(CASE WHEN points > 0 THEN points ELSE 0 END) as earned')
            ->selectRaw('SUM(CASE WHEN points < 0 THEN -points ELSE 0 END) as redeemed')
            ->first();

        $points = $pointsQuery->latest()->paginate(25);

        return view('livewire.loyalty.index', [
            'points' => $points,
            'referrals' => null,
            'totalPointsEarned' => (int) ($totals->earned ?? 0),
            'totalPointsRedeemed' => (int) ($totals->redeemed ?? 0),
        ])->layout('layouts.admin', ['title' => 'Loyalty & Referrals']);
    }
}
