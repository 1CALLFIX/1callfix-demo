<?php

namespace App\Livewire\Customer\Account;

use App\Models\FieldAssociate;
use App\Services\FieldAssociates\FieldAssociateTracker;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Account → My field work (field associates, owner 2026-11-23). A field associate's own page, made for a phone at
 * a shop counter: the month's target bar and rewards on top, one big QR + "Share link" to sign up a new shop, then
 * every shop they added with a coloured 5-step progress bar and what to do next. Read-only: rewards are paid outside
 * the app from the office's monthly report.
 */
class FieldWork extends Component
{
    /** 0 = this month, 1 = last month. */
    public int $monthsAgo = 0;

    public function mount(): void
    {
        abort_unless($this->associate() !== null, 403, 'This page is for 1CallFix field associates.');
    }

    public function showMonth(int $monthsAgo): void
    {
        $this->monthsAgo = in_array($monthsAgo, [0, 1], true) ? $monthsAgo : 0;
    }

    private function associate(): ?FieldAssociate
    {
        return FieldAssociate::query()->where('user_id', auth()->id())->first();
    }

    public function render(FieldAssociateTracker $tracker)
    {
        $associate = $this->associate();
        abort_unless($associate !== null, 403);

        $shops = $tracker->shops($associate);
        $month = Carbon::now()->startOfMonth()->subMonthsNoOverflow($this->monthsAgo);

        return view('livewire.customer.account.field-work', [
            'associate' => $associate,
            'shops' => $shops,
            'month' => $month,
            'stats' => $tracker->month($associate, $month),
            'target' => FieldAssociateTracker::monthlyTarget(),
            'rewardLive' => FieldAssociateTracker::rewardShopLive(),
            'rewardFirst' => FieldAssociateTracker::rewardFirstOrder(),
        ])->layout('components.layouts.customer', ['title' => 'My field work']);
    }
}
