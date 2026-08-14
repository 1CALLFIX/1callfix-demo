<?php

namespace App\Livewire\Subscriptions;

use App\Models\BusinessAccount;
use App\Models\EntitlementBalance;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Plans\SubscriptionService;
use App\Services\Plans\UsageService;
use Livewire\Component;
use Livewire\WithPagination;

/** Browse/pause/resume/cancel/renew/adjust — the second half of "Plans & Memberships" (approved plan §15). Not an analytics dashboard: no real volume yet to analyze, deliberately deferred. */
class Index extends Component
{
    use WithPagination;

    public string $statusFilter = '';
    public string $flashMessage = '';
    public string $flashType = 'success';

    // --- inline balance adjustment ---
    public ?int $adjustingBalanceId = null;
    public string $adjustQuantityDelta = '0';
    public string $adjustMonetaryDelta = '0';
    public string $adjustReason = '';

    /** subscriptions.view was seeded (2026_08_11_038000) but never checked on this list screen (only the mutating actions check subscriptions.manage) -- see Commissions\Index's identical fix for the full reasoning. */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('subscriptions.view'), 403, 'You do not have permission to view subscriptions.');
    }

    private function scopeHint(Subscription $subscription): array
    {
        return $subscription->plan?->authorizationScopeHint() ?? [];
    }

    public function pause(int $id, SubscriptionService $service): void
    {
        $this->act($id, $service, fn ($s) => $service->pause($s), 'Subscription paused.');
    }

    public function resume(int $id, SubscriptionService $service): void
    {
        $this->act($id, $service, fn ($s) => $service->resume($s), 'Subscription resumed.');
    }

    public function cancel(int $id, SubscriptionService $service): void
    {
        $this->act($id, $service, fn ($s) => $service->cancel($s, 'Cancelled by admin'), 'Subscription cancelled — stays usable until the current period ends.');
    }

    public function renewNow(int $id, SubscriptionService $service): void
    {
        $this->act($id, $service, fn ($s) => $service->renewNow($s), 'Renewal initiated.');
    }

    private function act(int $id, SubscriptionService $service, \Closure $action, string $successMessage): void
    {
        $subscription = Subscription::findOrFail($id);

        if (! auth()->user()->hasPermission('subscriptions.manage', $this->scopeHint($subscription))) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage this subscription.';
            return;
        }

        try {
            $action($subscription);
            $this->flashType = 'success';
            $this->flashMessage = $successMessage;
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function startAdjust(int $balanceId): void
    {
        $this->adjustingBalanceId = $balanceId;
        $this->adjustQuantityDelta = '0';
        $this->adjustMonetaryDelta = '0';
        $this->adjustReason = '';
    }

    public function confirmAdjust(UsageService $service): void
    {
        $balance = EntitlementBalance::findOrFail($this->adjustingBalanceId);
        $subscription = $balance->subscription;

        if (! auth()->user()->hasPermission('subscriptions.manage', $this->scopeHint($subscription))) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to adjust this balance.';
            return;
        }

        $this->validate(['adjustReason' => ['required', 'string', 'max:255']]);

        $service->adjust(
            $balance,
            (int) $this->adjustQuantityDelta,
            (float) $this->adjustMonetaryDelta,
            $this->adjustReason,
            auth()->id()
        );

        $this->adjustingBalanceId = null;
        $this->flashType = 'success';
        $this->flashMessage = 'Balance adjusted — recorded in the usage ledger.';
    }

    private function actorLabel(Subscription $subscription): string
    {
        if ($subscription->subscribable_type === User::class) {
            $u = User::find($subscription->subscribable_id);
            return $u ? $u->name.' ('.$u->role.')' : 'User #'.$subscription->subscribable_id;
        }
        if ($subscription->subscribable_type === BusinessAccount::class) {
            $b = BusinessAccount::find($subscription->subscribable_id);
            return $b ? $b->name.' (business)' : 'Business #'.$subscription->subscribable_id;
        }

        return 'Unknown actor';
    }

    public function render()
    {
        $query = Subscription::with(['plan', 'entitlementBalances' => fn ($q) => $q->where('status', 'current')])->latest();
        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }
        $subscriptions = $query->paginate(15);
        $subscriptions->getCollection()->transform(function ($s) {
            $s->display_label = $this->actorLabel($s);
            return $s;
        });

        return view('livewire.subscriptions.index', ['subscriptions' => $subscriptions])
            ->layout('layouts.admin', ['title' => 'Subscriptions']);
    }
}
