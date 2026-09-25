<?php

namespace App\Livewire\EarningsControl;

use App\Models\Franchise;
use App\Models\LoyaltyPoint;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Zone;
use App\Services\Earnings\EarningsMonitor;
use App\Services\Earnings\WalletAdjustmentService;
use App\Services\Earnings\WalletFreezeService;
use App\Services\LoyaltyService;
use App\Services\SettingsAuditor;
use App\Support\EarningsSettings;
use App\Support\SuperAdminGate;
use App\Support\WalletSourceLabel;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Referral;
use App\Services\ReferralService;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — Stage 3. Payments & Finance → Earnings
 * Control: the Super Admin's steering wheel for wallet / loyalty / referral.
 *
 *   Switches   — every Earnings switch and limit, ON / OFF / UNSET per scope,
 *                written through SettingsAuditor (audited, one cascade).
 *   Freeze     — freeze / unfreeze a customer or provider wallet (D5).
 *   Adjust     — compensating wallet / points entries, capped, reasoned,
 *                audited; never an edit of an existing row.
 *   Ledger     — read-only per-user wallet + points history with labels.
 *   Monitoring — totals by label, and every flag list.
 *
 * Every action re-checks SuperAdminGate server-side: a direct Livewire call
 * from any other role is a 403, not a hidden button. Existing Wallet /
 * Loyalty-Referral values stay on their Settings tabs (also Super Admin
 * only since Stage 2); this screen holds the switches and the new controls.
 */
class Manage extends Component
{
    use WithPagination;

    public string $activeTab = 'switches'; // switches|freeze|adjust|ledger|referrals|monitoring

    // ── Referrals (EARN4) ──
    public string $refStatus = '';
    public string $refFrom = '';
    public string $refTo = '';
    public ?int $refFranchiseId = null;
    public ?int $reviewingReferralId = null;
    public string $reviewReason = '';
    public ?int $blockingUserId = null;
    public string $blockReason = '';
    /** Keyed by self::field($key), like limitInputs. */
    public array $refereeInputs = [];

    // ── Switches ──
    public string $scopeType = 'global'; // global|franchise|zone
    public ?int $scopeFranchiseId = null;
    public ?int $scopeZoneId = null;
    /** Keyed by self::field($settingKey) — Livewire treats dots in a property path as nesting. */
    public array $limitInputs = [];

    // ── Shared user picker (freeze / adjust / ledger) ──
    public string $userSearch = '';
    public ?int $selectedUserId = null;

    // ── Freeze ──
    public string $freezeReason = '';

    // ── Adjust ──
    public string $adjustKind = 'wallet'; // wallet|points
    public string $adjustDirection = 'credit'; // credit|debit
    public string $adjustAmount = '';
    public string $adjustReason = '';

    // ── Ledger ──
    public string $ledgerKind = 'wallet'; // wallet|points
    public string $ledgerLabel = '';
    public string $ledgerFrom = '';
    public string $ledgerTo = '';

    // ── Monitoring ──
    public string $monFrom = '';
    public string $monTo = '';
    public ?int $monFranchiseId = null;

    public string $flashMessage = '';
    public string $flashType = 'success';

    protected $queryString = ['activeTab'];

    public function mount(): void
    {
        SuperAdminGate::authorize(auth()->user());

        $this->monFrom = now()->startOfMonth()->toDateString();
        $this->monTo = now()->toDateString();
        $this->loadLimits();
        $this->loadRefereeInputs();
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['switches', 'freeze', 'adjust', 'ledger', 'referrals', 'monitoring'], true) ? $tab : 'switches';
        $this->resetPage();
        $this->flashMessage = '';
    }

    // ═════════════════════════════ Switches ═════════════════════════════

    public function updatedScopeType(): void
    {
        $this->scopeFranchiseId = null;
        $this->scopeZoneId = null;
        $this->loadLimits();
    }

    public function updatedScopeFranchiseId(): void
    {
        $this->scopeZoneId = null;
        $this->loadLimits();
    }

    public function updatedScopeZoneId(): void
    {
        $this->loadLimits();
    }

    /** @return array{0: string, 1: ?int}|null null while the picked scope is incomplete */
    private function scopeTarget(): ?array
    {
        return match ($this->scopeType) {
            'global' => ['global', null],
            'franchise' => $this->scopeFranchiseId ? ['franchise', $this->scopeFranchiseId] : null,
            'zone' => $this->scopeZoneId ? ['zone', $this->scopeZoneId] : null,
            default => null,
        };
    }

    private function loadLimits(): void
    {
        $target = $this->scopeTarget();
        $this->limitInputs = [];

        foreach (EarningsSettings::LIMITS as $key => $meta) {
            [$type, $id] = $meta['global'] ? ['global', null] : ($target ?? ['global', null]);
            $status = EarningsSettings::statusAt($key, $type, $id);
            $this->limitInputs[self::field($key)] = $status === 'UNSET' ? '' : $status;
        }
    }

    /** $state: '1' (ON), '0' (OFF) or 'unset' (clear at this scope → inherits, and UNSET globally = OFF). */
    public function setSwitch(string $key, string $state): void
    {
        SuperAdminGate::authorize(auth()->user());

        if (! array_key_exists($key, EarningsSettings::SWITCHES) || ! in_array($state, ['1', '0', 'unset'], true)) {
            abort(422, 'Unknown switch or state.');
        }

        $target = EarningsSettings::SWITCHES[$key]['global'] ? ['global', null] : $this->scopeTarget();
        if (! $target) {
            $this->flash('Pick a franchise / zone first.', 'error');

            return;
        }

        SettingsAuditor::put(auth()->user(), $key, $state === 'unset' ? null : $state, $target[0], $target[1]);
        $this->flash("{$key} set to ".($state === 'unset' ? 'UNSET' : ($state === '1' ? 'ON' : 'OFF'))." at {$target[0]} scope.");
    }

    public function saveLimits(): void
    {
        SuperAdminGate::authorize(auth()->user());

        $rules = [];
        foreach (EarningsSettings::LIMITS as $key => $meta) {
            $rules['limitInputs.'.self::field($key)] = ['nullable', $meta['integer'] ? 'integer' : 'numeric', 'min:0'];
        }
        $this->validate($rules);

        $target = $this->scopeTarget();
        if (! $target) {
            $this->flash('Pick a franchise / zone first.', 'error');

            return;
        }

        foreach (EarningsSettings::LIMITS as $key => $meta) {
            [$type, $id] = $meta['global'] ? ['global', null] : $target;
            SettingsAuditor::put(auth()->user(), $key, $this->limitInputs[self::field($key)] ?? null, $type, $id);
        }

        $this->loadLimits();
        $this->flash('Limits saved.');
    }

    /** Setting key → Livewire-safe array key ('wallet.admin_adjustment_max' → 'wallet__admin_adjustment_max'). */
    public static function field(string $settingKey): string
    {
        return str_replace('.', '__', $settingKey);
    }

    // ═════════════════════════════ Referrals (EARN4) ═════════════════════════════

    private function loadRefereeInputs(): void
    {
        foreach (EarningsSettings::REFEREE_REWARD as $key => $meta) {
            $status = EarningsSettings::statusAt($key, 'global', null);
            $this->refereeInputs[self::field($key)] = $status === 'UNSET' ? '' : $status;
        }
    }

    /** R5 protective holds: '1' ON, '0' OFF (explicit, audited), 'unset' (= ON). Global only. */
    public function setProtection(string $key, string $state): void
    {
        SuperAdminGate::authorize(auth()->user());

        if (! array_key_exists($key, EarningsSettings::PROTECTIONS) || ! in_array($state, ['1', '0', 'unset'], true)) {
            abort(422, 'Unknown protection or state.');
        }

        SettingsAuditor::put(auth()->user(), $key, $state === 'unset' ? null : $state, 'global', null);
        $this->flash("{$key} set to ".match ($state) { '1' => 'ON', '0' => 'OFF', default => 'UNSET (protection ON)' }.'.');
    }

    public function saveRefereeReward(): void
    {
        SuperAdminGate::authorize(auth()->user());

        $this->validate([
            'refereeInputs.'.self::field('referral.referee_reward_type') => ['nullable', 'in:wallet,points'],
            'refereeInputs.'.self::field('referral.referee_reward_amount') => ['nullable', 'numeric', 'min:0'],
            'refereeInputs.'.self::field('referral.referee_reward_points') => ['nullable', 'integer', 'min:0'],
        ]);

        foreach (array_keys(EarningsSettings::REFEREE_REWARD) as $key) {
            SettingsAuditor::put(auth()->user(), $key, $this->refereeInputs[self::field($key)] ?? null, 'global', null);
        }

        $this->loadRefereeInputs();
        $this->flash('Referee reward saved.');
    }

    public function startReview(int $referralId): void
    {
        SuperAdminGate::authorize(auth()->user());
        $this->reviewingReferralId = Referral::whereKey($referralId)->value('id');
        $this->reviewReason = '';
    }

    public function releaseReferral(ReferralService $referrals): void
    {
        $this->reviewAction(function (Referral $r) use ($referrals) {
            $result = $referrals->release($r, auth()->user(), $this->reviewReason);
            $this->flash("Referral #{$r->id} released — ".str_replace('_', ' ', $result['outcome']).'.');
        });
    }

    public function rejectReferral(ReferralService $referrals): void
    {
        $this->reviewAction(function (Referral $r) use ($referrals) {
            $referrals->reject($r, auth()->user(), $this->reviewReason);
            $this->flash("Referral #{$r->id} rejected.");
        });
    }

    private function reviewAction(callable $fn): void
    {
        SuperAdminGate::authorize(auth()->user());

        $referral = $this->reviewingReferralId ? Referral::find($this->reviewingReferralId) : null;
        if (! $referral) {
            $this->flash('Pick a referral first.', 'error');

            return;
        }

        try {
            $fn($referral);
            $this->reset('reviewingReferralId', 'reviewReason');
        } catch (\RuntimeException $e) {
            $this->flash($e->getMessage(), 'error');
        }
    }

    public function startBlock(int $userId): void
    {
        SuperAdminGate::authorize(auth()->user());
        $this->blockingUserId = User::whereKey($userId)->where('role', 'customer')->value('id');
        $this->blockReason = '';
    }

    public function blockReferrals(ReferralService $referrals): void
    {
        $this->blockAction(fn (User $u) => $referrals->blockUser(auth()->user(), $u, $this->blockReason), 'blocked from');
    }

    public function unblockReferrals(ReferralService $referrals): void
    {
        $this->blockAction(fn (User $u) => $referrals->unblockUser(auth()->user(), $u, $this->blockReason), 'unblocked for');
    }

    private function blockAction(callable $fn, string $verb): void
    {
        SuperAdminGate::authorize(auth()->user());

        $user = $this->blockingUserId ? User::where('role', 'customer')->find($this->blockingUserId) : null;
        if (! $user) {
            $this->flash('Pick a customer first.', 'error');

            return;
        }

        try {
            $fn($user);
            $this->flash("{$user->name} {$verb} the referral program.");
            $this->reset('blockingUserId', 'blockReason');
        } catch (\RuntimeException $e) {
            $this->flash($e->getMessage(), 'error');
        }
    }

    public function updatingRefStatus() { $this->resetPage(); }
    public function updatingRefFrom() { $this->resetPage(); }
    public function updatingRefTo() { $this->resetPage(); }
    public function updatingRefFranchiseId() { $this->resetPage(); }

    private function referralsQuery()
    {
        return Referral::query()
            ->with(['referrer:id,name,phone,franchise_id,referral_blocked_at,signup_ip,last_login_ip,signup_device_id,last_login_device_id', 'referred:id,name,phone', 'reviewedBy:id,name'])
            ->when($this->refStatus !== '', fn ($q) => $q->where('status', $this->refStatus))
            ->when($this->refFrom !== '', fn ($q) => $q->where('created_at', '>=', Carbon::parse($this->refFrom)->startOfDay()))
            ->when($this->refTo !== '', fn ($q) => $q->where('created_at', '<=', Carbon::parse($this->refTo)->endOfDay()))
            ->when($this->refFranchiseId, fn ($q) => $q->whereHas('referrer', fn ($u) => $u->where('franchise_id', $this->refFranchiseId)))
            ->latest('id');
    }

    // ═════════════════════════════ User picker ═════════════════════════════

    public function selectUser(int $userId): void
    {
        SuperAdminGate::authorize(auth()->user());

        $this->selectedUserId = User::whereKey($userId)->whereIn('role', ['customer', 'provider'])->value('id');
        $this->userSearch = '';
        $this->resetPage();
    }

    private function selectedUser(): ?User
    {
        return $this->selectedUserId ? User::whereIn('role', ['customer', 'provider'])->find($this->selectedUserId) : null;
    }

    // ═════════════════════════════ Freeze ═════════════════════════════

    public function freeze(WalletFreezeService $service): void
    {
        $this->runForSelectedUser(function (User $user) use ($service) {
            $service->freeze(auth()->user(), $user, $this->freezeReason);
            $this->freezeReason = '';
            $this->flash("Wallet of {$user->name} frozen.");
        });
    }

    public function unfreeze(WalletFreezeService $service): void
    {
        $this->runForSelectedUser(function (User $user) use ($service) {
            $service->unfreeze(auth()->user(), $user, $this->freezeReason);
            $this->freezeReason = '';
            $this->flash("Wallet of {$user->name} unfrozen.");
        });
    }

    // ═════════════════════════════ Adjust ═════════════════════════════

    public function adjust(WalletAdjustmentService $wallet, LoyaltyService $loyalty): void
    {
        SuperAdminGate::authorize(auth()->user());

        $this->validate([
            'adjustKind' => ['required', 'in:wallet,points'],
            'adjustDirection' => ['required', 'in:credit,debit'],
            'adjustAmount' => ['required', 'numeric', 'gt:0'],
            'adjustReason' => ['required', 'string', 'max:500'],
        ]);

        $this->runForSelectedUser(function (User $user) use ($wallet, $loyalty) {
            if ($this->adjustKind === 'wallet') {
                $wallet->adjust(auth()->user(), $user, $this->adjustDirection, (float) $this->adjustAmount, $this->adjustReason);
            } else {
                if ((float) $this->adjustAmount != (int) $this->adjustAmount) {
                    throw new \RuntimeException('Points must be a whole number.');
                }
                $loyalty->adjust(auth()->user(), $user, $this->adjustDirection, (int) $this->adjustAmount, $this->adjustReason);
            }

            $this->reset('adjustAmount', 'adjustReason');
            $this->flash('Adjustment recorded as a new ledger entry.');
        });
    }

    private function runForSelectedUser(callable $fn): void
    {
        SuperAdminGate::authorize(auth()->user());

        $user = $this->selectedUser();
        if (! $user) {
            $this->flash('Select a customer or provider first.', 'error');

            return;
        }

        try {
            $fn($user);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $this->flash($e->getMessage(), 'error');
        }
    }

    private function flash(string $message, string $type = 'success'): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }

    public function updatingLedgerLabel() { $this->resetPage(); }
    public function updatingLedgerKind() { $this->resetPage(); }
    public function updatingLedgerFrom() { $this->resetPage(); }
    public function updatingLedgerTo() { $this->resetPage(); }

    // ═════════════════════════════ Render ═════════════════════════════

    public function render(EarningsMonitor $monitor, LoyaltyService $loyalty)
    {
        SuperAdminGate::authorize(auth()->user());

        $data = ['tab' => $this->activeTab, 'currencySymbol' => \App\Models\Setting::get('locale.currency_symbol', '₹')];

        if ($this->activeTab === 'switches') {
            $target = $this->scopeTarget();
            $data['switches'] = collect(EarningsSettings::SWITCHES)->map(fn ($meta, $key) => [
                'label' => $meta['label'],
                'global_only' => $meta['global'],
                'global' => EarningsSettings::statusAt($key, 'global', null),
                'here' => $target && ! $meta['global'] && $target[0] !== 'global' ? EarningsSettings::statusAt($key, $target[0], $target[1]) : null,
            ])->all();
            $data['limits'] = EarningsSettings::LIMITS;
            $data['franchises'] = Franchise::orderBy('name')->get(['id', 'name']);
            $data['zones'] = $this->scopeFranchiseId ? Zone::where('franchise_id', $this->scopeFranchiseId)->orderBy('name')->get(['id', 'name']) : collect();
        }

        if (in_array($this->activeTab, ['freeze', 'adjust', 'ledger'], true)) {
            $data['userResults'] = strlen(trim($this->userSearch)) >= 2
                ? User::whereIn('role', ['customer', 'provider'])
                    ->where(fn ($q) => $q->where('name', 'like', "%{$this->userSearch}%")
                        ->orWhere('phone', 'like', "%{$this->userSearch}%")
                        ->orWhere('id', (int) $this->userSearch))
                    ->limit(10)->get(['id', 'name', 'phone', 'role'])
                : collect();

            $user = $this->selectedUser();
            $data['selectedUser'] = $user;
            $data['selectedWallet'] = $user ? Wallet::with('frozenBy:id,name')->where('user_id', $user->id)->first() : null;
            $data['selectedPoints'] = $user ? $loyalty->balance($user) : null;
            $data['walletAdjustMax'] = EarningsSettings::number('wallet.admin_adjustment_max');
            $data['pointsAdjustMax'] = EarningsSettings::integer('loyalty.admin_adjustment_max');

            if ($this->activeTab === 'ledger' && $user) {
                $data['labelOptions'] = WalletSourceLabel::options();
                $data['ledger'] = $this->ledgerQuery($user)->paginate(25);
            }
        }

        if ($this->activeTab === 'referrals') {
            $data += [
                'referrals' => $this->referralsQuery()->paginate(25),
                'franchises' => Franchise::orderBy('name')->get(['id', 'name']),
                'protections' => collect(EarningsSettings::PROTECTIONS)->map(fn ($meta, $key) => [
                    'label' => $meta['label'], 'status' => EarningsSettings::statusAt($key, 'global', null),
                ])->all(),
                'refereeMeta' => EarningsSettings::REFEREE_REWARD,
                'reviewing' => $this->reviewingReferralId ? Referral::with(['referrer:id,name', 'referred:id,name'])->find($this->reviewingReferralId) : null,
                'blocking' => $this->blockingUserId ? User::find($this->blockingUserId) : null,
            ];
        }

        if ($this->activeTab === 'monitoring') {
            $from = Carbon::parse($this->monFrom ?: now()->startOfMonth())->startOfDay();
            $to = Carbon::parse($this->monTo ?: now())->endOfDay();
            $data += [
                'franchises' => Franchise::orderBy('name')->get(['id', 'name']),
                'totals' => $monitor->totalsByLabel($from, $to, $this->monFranchiseId),
                'frozenWallets' => $monitor->frozenWallets(),
                'walletAdjustments' => $monitor->walletAdjustments($from, $to, $this->monFranchiseId),
                'pointsAdjustments' => $monitor->pointsAdjustments($from, $to, $this->monFranchiseId),
                'bigRefunds' => $monitor->refundsAboveThreshold($from, $to, $this->monFranchiseId),
                'refundThreshold' => EarningsSettings::number('earnings.flag_refund_above'),
                'busyReferrers' => $monitor->referrersAboveThreshold(),
                'referralThreshold' => EarningsSettings::integer('earnings.flag_referrals_above'),
                'frozenCredits' => $monitor->creditsToFrozenWallets(),
                'loyaltyAudit' => $monitor->loyaltyAudit(),
                'referralCounts' => $monitor->referralCountsByStatus(),
                'heldReferrals' => $monitor->heldReferrals(),
                'bundleAudit' => $monitor->bundleRefundAudit(),
            ];
        }

        return view('livewire.earnings-control.manage', $data)
            ->layout('layouts.admin', ['title' => 'Earnings Control']);
    }

    private function ledgerQuery(User $user)
    {
        $from = $this->ledgerFrom !== '' ? Carbon::parse($this->ledgerFrom)->startOfDay() : null;
        $to = $this->ledgerTo !== '' ? Carbon::parse($this->ledgerTo)->endOfDay() : null;

        if ($this->ledgerKind === 'points') {
            return LoyaltyPoint::query()->with(['booking:id,code', 'actor:id,name'])
                ->where('user_id', $user->id)
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                ->latest('id');
        }

        $walletId = Wallet::where('user_id', $user->id)->value('id');

        return WalletTransaction::query()->with('actor:id,name')
            ->where('wallet_id', $walletId ?? 0)
            ->when($this->ledgerLabel !== '', fn ($q) => WalletSourceLabel::scopeQuery($q, $this->ledgerLabel))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->latest('id');
    }
}
