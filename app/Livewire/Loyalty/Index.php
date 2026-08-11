<?php

namespace App\Livewire\Loyalty;

use App\Models\LoyaltyPoint;
use App\Models\Referral;
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
    public string $referralStatusFilter = ''; // '' | pending | rewarded

    protected $queryString = ['activeTab', 'search', 'referralStatusFilter'];

    public function updatingSearch() { $this->resetPage(); }
    public function updatingReferralStatusFilter() { $this->resetPage(); }

    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['points', 'referrals'], true) ? $tab : 'points';
        $this->resetPage();
    }

    public function render()
    {
        if ($this->activeTab === 'referrals') {
            $referrals = Referral::with(['referrer', 'referred', 'qualifyingBooking'])
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
            ])->layout('layouts.admin', ['title' => 'Loyalty & Referrals']);
        }

        $pointsQuery = LoyaltyPoint::with(['user', 'booking'])
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
