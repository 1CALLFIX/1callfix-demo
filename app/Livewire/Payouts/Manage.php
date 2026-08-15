<?php

namespace App\Livewire\Payouts;

use App\Exports\PayoutsExport;
use App\Models\PaymentAccount;
use App\Models\Payout;
use App\Models\Provider;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\PayoutService;
use App\Services\WalletService;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

// Disbursement — the missing half of Commission: CommissionService already
// credits provider/franchise-owner wallets in real time at booking
// completion (see App\Services\CommissionService); this screen is where
// that wallet balance turns into an actual payout request, tracked through
// to paid/failed via PayoutService. See PayoutService's docblock for why
// there's no live gateway call here yet.
class Manage extends Component
{
    use WithPagination;

    /**
     * No view-level check existed at all — only write actions checked
     * payouts.manage (Phase 11 audit finding, same bug class addendum #1
     * already fixed on 15 other screens). This is a particularly serious
     * instance of the gap: real payout requests/amounts were browsable by
     * any admin-panel actor of any role. No separate payouts.view
     * permission was ever seeded, so this reuses payouts.manage.
     */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('payouts.manage'), 403, 'You do not have permission to view payouts.');
    }

    public string $payeeType = 'provider'; // provider|franchise_owner
    public string $payeeSearch = '';
    public ?int $selectedPayeeId = null;
    public string $selectedPayeeLabel = '';
    public string $amount = '';
    public ?int $paymentAccountId = null;

    public string $flashMessage = '';
    public string $flashType = 'success';

    // --- inline "mark paid" gateway-ref capture ---
    public ?int $markingPaidId = null;
    public string $gatewayRefInput = '';

    public function updatedPayeeType(): void
    {
        $this->reset(['payeeSearch', 'selectedPayeeId', 'selectedPayeeLabel', 'paymentAccountId']);
    }

    public function getMatchingPayeesProperty()
    {
        if (mb_strlen($this->payeeSearch) < 2) {
            return collect();
        }

        if ($this->payeeType === 'provider') {
            return Provider::with('user')
                ->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$this->payeeSearch}%")->orWhere('phone', 'like', "%{$this->payeeSearch}%"))
                ->limit(8)->get()
                ->map(fn ($p) => ['id' => $p->id, 'label' => ($p->user->name ?? 'Provider #'.$p->id).' — '.($p->user->phone ?? '—')]);
        }

        return User::where('name', 'like', "%{$this->payeeSearch}%")
            ->orWhere('phone', 'like', "%{$this->payeeSearch}%")
            ->limit(8)->get()
            ->map(fn ($u) => ['id' => $u->id, 'label' => $u->name.' — '.$u->phone]);
    }

    public function selectPayee(int $id, string $label): void
    {
        $this->selectedPayeeId = $id;
        $this->selectedPayeeLabel = $label;
        $this->paymentAccountId = null;
    }

    public function getPayeeWalletBalanceProperty(): ?float
    {
        if (! $this->selectedPayeeId) {
            return null;
        }

        $user = app(PayoutService::class)->resolvePayeeUser($this->payeeType, $this->selectedPayeeId);

        return app(WalletService::class)->balance($user);
    }

    public function getPayeePaymentAccountsProperty()
    {
        if (! $this->selectedPayeeId) {
            return collect();
        }

        $user = app(PayoutService::class)->resolvePayeeUser($this->payeeType, $this->selectedPayeeId);

        return PaymentAccount::where('user_id', $user->id)->get();
    }

    public function request(PayoutService $service): void
    {
        if (! auth()->user()->hasPermission('payouts.manage')) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to request payouts.';
            return;
        }

        $this->validate([
            'selectedPayeeId' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ], [], ['selectedPayeeId' => 'payee', 'amount' => 'amount']);

        try {
            $service->request($this->payeeType, $this->selectedPayeeId, (float) $this->amount, $this->paymentAccountId);
            $this->reset(['payeeSearch', 'selectedPayeeId', 'selectedPayeeLabel', 'amount', 'paymentAccountId']);
            $this->flashType = 'success';
            $this->flashMessage = 'Payout requested — amount held from wallet balance.';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function markProcessing(int $payoutId, PayoutService $service): void
    {
        $payout = Payout::findOrFail($payoutId);

        if (! auth()->user()->hasPermission('payouts.manage', $payout->authorizationScopeHint())) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to modify this payout.';
            return;
        }

        try {
            $service->markProcessing($payout);
            $this->flashType = 'success';
            $this->flashMessage = 'Payout marked processing.';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function startMarkPaid(int $payoutId): void
    {
        $this->markingPaidId = $payoutId;
        $this->gatewayRefInput = '';
    }

    public function confirmMarkPaid(PayoutService $service): void
    {
        $payout = Payout::findOrFail($this->markingPaidId);

        if (! auth()->user()->hasPermission('payouts.manage', $payout->authorizationScopeHint())) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to modify this payout.';
            return;
        }

        $this->validate(['gatewayRefInput' => ['required', 'string', 'max:255']], [], ['gatewayRefInput' => 'transfer reference']);

        try {
            $service->markPaid($payout, $this->gatewayRefInput);
            $this->markingPaidId = null;
            $this->flashType = 'success';
            $this->flashMessage = 'Payout marked paid.';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function markFailed(int $payoutId, PayoutService $service): void
    {
        $payout = Payout::findOrFail($payoutId);

        if (! auth()->user()->hasPermission('payouts.manage', $payout->authorizationScopeHint())) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to modify this payout.';
            return;
        }

        try {
            $service->markFailed($payout, 'Marked failed by admin');
            $this->flashType = 'success';
            $this->flashMessage = 'Payout marked failed — amount refunded to wallet.';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    /**
     * Verification is always an admin action (payment_accounts had zero
     * write path before this session -- see PaymentAccountService's own
     * docblock). Reuses payouts.manage rather than a new permission --
     * this is part of the same "get a payee ready to actually be paid"
     * capability the rest of this screen already gates that way.
     */
    public function verifyPaymentAccount(int $accountId, \App\Services\PaymentAccountService $service): void
    {
        if (! auth()->user()->hasPermission('payouts.manage')) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to verify payment accounts.';
            return;
        }

        $service->verify(\App\Models\PaymentAccount::findOrFail($accountId));
        $this->flashType = 'success';
        $this->flashMessage = 'Payment account verified.';
    }

    public function unverifyPaymentAccount(int $accountId, \App\Services\PaymentAccountService $service): void
    {
        if (! auth()->user()->hasPermission('payouts.manage')) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to unverify payment accounts.';
            return;
        }

        $service->unverify(\App\Models\PaymentAccount::findOrFail($accountId));
        $this->flashType = 'success';
        $this->flashMessage = 'Payment account verification revoked.';
    }

    private function payeeLabel(Payout $payout): string
    {
        if ($payout->payee_type === 'provider') {
            $p = Provider::with('user')->find($payout->payee_id);
            return $p ? ($p->user->name ?? 'Provider #'.$p->id).' (provider)' : 'Provider #'.$payout->payee_id.' (provider)';
        }

        $u = User::find($payout->payee_id);
        return $u ? $u->name.' (franchise owner)' : 'User #'.$payout->payee_id.' (franchise owner)';
    }

    /**
     * Mission Phase 14 (Operations Import/Export completeness) — the real
     * reference product's "Payouts" export. Never exports raw banking
     * details — see PayoutsExport's docblock. Phase 21 item TECH-1: now
     * reuses the same row-level franchise scope render() applies, passing
     * the acting user through, matching CommissionsExport's own
     * constructor-injection convention — a franchise-scoped payouts.manage
     * grant's export contains only their own franchise's payouts.
     */
    public function exportPayouts()
    {
        return Excel::download(new PayoutsExport(auth()->user()), 'payouts-'.now()->format('Y-m-d').'.xlsx');
    }

    /**
     * Phase 21 item TECH-1. Payout's payee_type/payee_id is a manual
     * discriminator, not a real Eloquent relation (see
     * PayoutService::resolvePayeeUser()), so AuthorizationService::
     * scopeQuery()'s generic relation-traversal can't reach it directly --
     * same shape Plan/NotificationCampaign already solve via
     * authorizationScopeHint() + visibleAmong(), reused here rather than
     * inventing a new mechanism. Mirrors Plans\Manage::visiblePlanIds()
     * exactly: a lightweight id+payee_type+payee_id projection fetched
     * first (payout REQUESTS, not bookings -- inherently small), filtered
     * here, then whereIn()'d before the real paginated query runs, so
     * pagination/counts stay correct rather than being computed before
     * this filter.
     */
    private function visiblePayoutIds(): array
    {
        $candidates = Payout::query()->select('id', 'payee_type', 'payee_id')->get();

        return app(AuthorizationService::class)
            ->visibleAmong($candidates, auth()->user(), 'payouts.manage')
            ->pluck('id')
            ->all();
    }

    public function render()
    {
        $payouts = Payout::whereIn('id', $this->visiblePayoutIds())->latest()->paginate(15);
        $payouts->getCollection()->transform(function ($p) {
            $p->display_label = $this->payeeLabel($p);
            return $p;
        });

        return view('livewire.payouts.manage', [
            'payouts' => $payouts,
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('layouts.admin', ['title' => 'Payouts']);
    }
}
