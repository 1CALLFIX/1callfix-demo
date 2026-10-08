<?php

namespace App\Livewire\Subscriptions;

use App\Actions\RedeemEntitlementAction;
use App\Models\BusinessAccount;
use App\Models\EntitlementBalance;
use App\Models\PlanEntitlement;
use App\Models\UsageLedger;
use App\Models\Setting;
use App\Livewire\Concerns\HasRowArchive;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\Plans\SubscriptionService;
use App\Services\Plans\UsageService;
use Livewire\Component;
use Livewire\WithPagination;

/** Browse/pause/resume/cancel/renew/adjust — the second half of "Plans & Memberships" (approved plan §15). Not an analytics dashboard: no real volume yet to analyze, deliberately deferred. */
class Index extends Component
{
    use WithPagination;
    use HasRowArchive;

    protected function archiveModel(): string
    {
        return Subscription::class;
    }

    /** subscriptions.manage within the admin's own scope, and only an unpaid or ended subscription. */
    protected function canArchiveRow(Model $row): bool
    {
        return auth()->user()->hasPermissionAnywhere('subscriptions.manage')
            && in_array($row->id, $this->visibleSubscriptionIds($row->trashed()), true)
            && ($row->trashed() || $row->isArchivable());
    }

    protected function archiveLabel(Model $row): string
    {
        return 'subscription #'.$row->getKey();
    }

    public string $statusFilter = '';
    public string $flashMessage = '';
    public string $flashType = 'success';

    // --- inline balance adjustment ---
    public ?int $adjustingBalanceId = null;
    public string $adjustQuantityDelta = '0';
    public string $adjustMonetaryDelta = '0';
    public string $adjustReason = '';

    // --- inline entitlement redemption (deliberate "use one voucher now") ---
    public ?int $redeemingBalanceId = null;
    public string $redeemCategory = '';

    // --- usage / redemption history for one expanded subscription ---
    public ?int $historySubscriptionId = null;

    /**
     * subscriptions.view was seeded (2026_08_11_038000) but never checked on
     * this list screen (only the mutating actions check subscriptions.manage)
     * -- see Commissions\Index's identical fix for the full reasoning.
     *
     * Row-level scoping (was deferred here, now closed): a subscription
     * carries no geography of its own, so visibility follows its plan's --
     * see Subscription::authorizationScopeHint(), the exact same basis
     * scopeHint() below (used by the mutation actions) already computed.
     */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('subscriptions.view'), 403, 'You do not have permission to view subscriptions.');
    }

    private function scopeHint(Subscription $subscription): array
    {
        return $subscription->authorizationScopeHint();
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

    public function toggleHistory(int $id): void
    {
        $this->historySubscriptionId = $this->historySubscriptionId === $id ? null : $id;
    }

    /**
     * Gives a redeemed benefit back — a 'reverse' ledger row pointing at the
     * consume row it undoes, stamped with this admin (UsageService::reverse is
     * idempotent, so a double click restores nothing twice). Same
     * subscriptions.manage scope gate as every other mutation on this screen.
     */
    public function reverseUsage(int $ledgerId, UsageService $service): void
    {
        $row = UsageLedger::findOrFail($ledgerId);
        $subscription = $row->subscription;

        if (! auth()->user()->hasPermission('subscriptions.manage', $this->scopeHint($subscription))) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to reverse usage on this subscription.';
            return;
        }

        try {
            $reversal = $service->reverse($row, 'Reversed by admin', auth()->id());
        } catch (\InvalidArgumentException $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
            return;
        }

        $this->flashType = $reversal ? 'success' : 'error';
        $this->flashMessage = $reversal ? 'Usage reversed — the benefit is back on the balance and recorded in the ledger.' : 'That usage was already reversed.';
    }

    /** @return array{rows: \Illuminate\Support\Collection, reversed: array<int,bool>} */
    private function historyFor(?int $subscriptionId): array
    {
        if (! $subscriptionId) {
            return ['rows' => collect(), 'reversed' => []];
        }

        $rows = UsageLedger::where('subscription_id', $subscriptionId)
            ->with(['planEntitlement', 'booking:id,code', 'createdBy:id,name'])
            ->latest('id')->limit(60)->get();

        $reversed = UsageLedger::where('subscription_id', $subscriptionId)
            ->where('event_type', 'reverse')->pluck('related_usage_ledger_id')->flip()->map(fn () => true)->all();

        return ['rows' => $rows, 'reversed' => $reversed];
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

    public function startRedeem(int $balanceId): void
    {
        $this->redeemingBalanceId = $balanceId;
        $this->redeemCategory = '';
    }

    /**
     * Deliberate redemption of ONE unit of a named entitlement on behalf of
     * a customer (call-centre / support). Distinct from adjust(): this is a
     * real 'consume' event through RedeemEntitlementAction, category-checked,
     * subject to the same subscriptions.manage scope gate as everything else
     * on this screen.
     */
    public function confirmRedeem(RedeemEntitlementAction $action): void
    {
        $balance = EntitlementBalance::with('planEntitlement')->findOrFail($this->redeemingBalanceId);
        $subscription = $balance->subscription;

        if (! auth()->user()->hasPermission('subscriptions.manage', $this->scopeHint($subscription))) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to redeem against this subscription.';
            return;
        }

        try {
            $row = $action->execute(
                $subscription,
                $balance->planEntitlement,
                $this->redeemCategory !== '' ? $this->redeemCategory : null,
                1,
                null,
                auth()->id(),
            );
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
            return;
        }

        $this->redeemingBalanceId = null;
        $this->redeemCategory = '';
        $this->flashType = 'success';
        $this->flashMessage = 'Entitlement redeemed'
            .($row->redeemed_category ? " ({$row->redeemed_category})" : '')
            .' — recorded in the usage ledger.';
    }

    /**
     * Admin Command Center completion session, Admin UX/Performance phase
     * (2026-08-20) -- this used to re-fetch the actor with a fresh
     * User::find()/BusinessAccount::find() query PER ROW, ignoring the
     * `subscribable` relation render() already eager-loads via
     * ->with(['subscribable.franchise.country', ...]) -- a real, wasteful
     * N+1 (up to 15 extra queries per page load) for data already sitting
     * in memory. Uses the already-loaded relation directly instead.
     */
    private function actorLabel(Subscription $subscription): string
    {
        $actor = $subscription->subscribable;

        if ($subscription->subscribable_type === User::class) {
            return $actor ? $actor->name.' ('.$actor->role.')' : 'User #'.$subscription->subscribable_id;
        }
        if ($subscription->subscribable_type === BusinessAccount::class) {
            return $actor ? $actor->name.' (business)' : 'Business #'.$subscription->subscribable_id;
        }

        return 'Unknown actor';
    }

    /**
     * Lightweight id+plan projection first (plan-linked commerce data,
     * inherently far smaller than transactional tables like bookings), same
     * "filter-then-whereIn, not filter-after-paginate" reasoning as
     * Plans\Manage::visiblePlanIds() so pagination/counts stay correct.
     */
    private function visibleSubscriptionIds(bool $trashed = false): array
    {
        $candidates = ($trashed ? Subscription::onlyTrashed() : Subscription::query())->select('id', 'plan_id')->with('plan:id,scope_type,scope_id')->get();

        return app(AuthorizationService::class)
            ->visibleAmong($candidates, auth()->user(), 'subscriptions.view')
            ->pluck('id')
            ->all();
    }

    public function render()
    {
        $archivedTab = $this->statusFilter === 'archived';
        $query = ($archivedTab ? Subscription::onlyTrashed() : Subscription::query())->whereIn('id', $this->visibleSubscriptionIds($archivedTab))
            ->with(['plan', 'registeredAddress', 'subscribable.franchise.country', 'entitlementBalances' => fn ($q) => $q->where('status', 'current')->with('planEntitlement')])->latest();
        if ($this->statusFilter && ! $archivedTab) {
            $query->where('status', $this->statusFilter);
        }
        $subscriptions = $query->paginate(15);
        $subscriptions->getCollection()->transform(function ($s) {
            $s->display_label = $this->actorLabel($s);
            return $s;
        });

        return view('livewire.subscriptions.index', [
            'subscriptions' => $subscriptions,
            'canManage' => auth()->user()->hasPermissionAnywhere('subscriptions.manage'),
            'canForce' => $this->isSuperAdminUser(),
            'archiveBars' => $this->archiveBars(),
            'history' => $this->historyFor($this->historySubscriptionId),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('layouts.admin', ['title' => 'Subscriptions']);
    }
}
