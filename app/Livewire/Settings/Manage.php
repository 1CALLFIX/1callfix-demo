<?php

namespace App\Livewire\Settings;

use App\Models\City;
use App\Models\Country;
use App\Models\Franchise;
use App\Models\Setting;
use App\Models\Zone;
use App\Services\Ranking\RankingConfigResolver;
use Livewire\Component;

// One config hub, not a list — unlike the catalog {Module}\Manage screens,
// there's nothing to paginate/search here. Tabbed like the reference apps
// (Glover, 6amMart) this was benchmarked against, but as Livewire-driven
// panels within one component rather than a second sidebar — this app
// already has a left icon-rail for the main admin nav, so a nested sidebar
// would be a new layout pattern with no other precedent here. Each real tab
// saves independently (saveDispatch(), saveCommission(), etc.), same as
// both reference apps' own per-tab "Save Information" convention.
//
// SCOPE PICKER — the real Control Plane behaviour. Phase 1 built
// Setting::get()/set()'s Global→Country→City→Zone→Module→Franchise cascade
// but nothing used a non-global scope until this. Pick Global (default) or
// drill into a specific Country/City/Franchise/Zone, and the five real tabs
// below read/write overrides at exactly that scope — falling back to
// whatever's set at a broader scope when nothing's overridden here. Module
// scope isn't in the picker: with only one real module (Service) built,
// there's nothing yet for a module-scoped override to differ from a global
// one — the resolver already supports it the moment a second module needs it.
//
// Every REAL tab's fields are wired to something that actually reads them —
// see the comment above each group below. As of this pass that's ten tabs:
// Dispatch, Commission Defaults, Booking, Locale & Currency, Branding, Maps
// (read-only status), General/System (Maintenance Mode, gates the booking
// API routes via EnsureNotInMaintenanceMode), Payment (which methods the
// New Booking modal offers — not gateway credentials, those stay in .env),
// Website/CMS (a real screen at admin.cms.index — content_pages/faqs had
// schema and zero consumers), and Websocket (read-only status — the
// BookingStatusUpdated/NewJobOffered events genuinely implement
// ShouldBroadcast, but BROADCAST_CONNECTION is "log", not a connected
// realtime service).
//
// Everything else renders as a greyed-out "coming soon" panel via
// PLACEHOLDER_TABS, each with a one-line reason grounded in this
// codebase's actual state, not a guess — shipping controls that configure
// nothing violates the project's own Definition of Done.
//
// Refund/Cancellation graduated to a real tab once the four cancellation
// business rules (timer reference point, paid-booking refund behaviour,
// seeded default, admin-only scope) were confirmed — see
// App\Services\CancellationService, wired into AdminCancelBookingAction.
//
// Roles/Permissions graduated to its own screen (admin.roles.index, not a
// tab here) — see App\Services\AuthorizationService and
// App\Livewire\Roles\Manage. A user-search + scope-assignment workflow
// doesn't fit this component's per-tab-form shape; same reasoning that put
// CMS on its own screen instead of a Settings tab.
//
// Finance/Settlement and Disbursement graduated the same way, to
// admin.payouts.index — franchise revenue share is now credited to the
// franchise owner's wallet at completion (CommissionService, same
// mechanism as the provider's share), and PayoutService turns wallet
// balance into a tracked payout request through to paid/failed.
//
// Notifications / Communication and Mail / SMS merged into one real tab
// here ("Notifications") once App\Notifications existed: BookingStatus/
// PaymentStatus/PayoutStatus Notification classes route through the
// built-in mail channel plus custom SmsChannel/PushChannel (backed by
// LogSmsAdapter/LogPushAdapter — no real SMS/push provider is configured
// anywhere, same as before, but the full event -> channel -> adapter flow
// is real and logged to notification_logs). This tab only controls which
// channels are attempted, per scope.
//
// Priority/Ranking graduated once a real consumer existed to change: this
// codebase's ONE real ranking consumer, DispatchService::findCandidates()
// (provider matching), plus a new customer-facing GET /api/providers/nearby
// (App\Services\Ranking\RankingEngine + RankingConfigResolver). Default
// config (distance ascending only) preserves DispatchService's exact prior
// behaviour until an admin actually changes it. "Riders" ranking uses the
// same config -- Provider is the only actor entity in this schema, same
// note as Wallet's provider settings.
//
// Wallet/Ledger graduated once real consumers existed to enforce it
// against: customer top-up (App\Services\WalletTopUpService, backed by the
// same Razorpay order/webhook path booking payments use — see
// payments.purpose) and payout min/max (App\Services\PayoutService). A
// provider's minimum-balance-to-accept-jobs gate lives in
// AcceptBookingAction. "Maximum wallet balance" is enforced only against
// voluntary top-ups, not automatic commission earnings — a balance cap
// must never cause an earned commission credit to be rejected/lost.
//
// Loyalty/Referral graduated once App\Services\LoyaltyService/
// ReferralService existed as real consumers of loyalty_points/referrals
// (both previously zero-consumer, confirmed by audit). Earning is wired
// into CompleteBookingAction (customer per-rupee, provider per-job);
// redemption is real money via the existing WalletService, not a second
// balance system. Referral qualification (a referred customer's first
// completed booking) rewards the referrer via wallet OR points, admin's
// choice — reuses the same two systems either way.
class Manage extends Component
{
    public const PLACEHOLDER_TABS = [
        'mobile_apps' => ['label' => 'Mobile Apps', 'note' => 'Country picker, app links, upgrade prompts — no mobile apps exist yet (M6/M7 haven\'t started).'],
        'vendor' => ['label' => 'Vendor / Provider', 'note' => 'Provider self-registration rules, KYC requirements, verified badges — the Providers screen already covers approve/reject; broader policy config isn\'t built yet. No self-registration route exists (confirmed by audit) since there\'s no provider-facing app yet.'],
        'customer' => ['label' => 'Customer', 'note' => 'Registration rules, profile requirements, per-customer limits — no generalized customer-config surface exists yet (no customer-facing app to register through).'],
        'ui_home_screen' => ['label' => 'UI / Home Screen', 'note' => 'Banner position/vendor layout/widget visibility — there\'s no customer-facing home screen yet to configure (M6 not started).'],
        'dynamic_links' => ['label' => 'Dynamic Links', 'note' => 'iOS/Android deep-link scheme, package names, SHA256 — meaningless without a mobile app to link into.'],
        'in_app_support' => ['label' => 'In-App Support', 'note' => 'Support widget/link config for a mobile app that doesn\'t exist yet.'],
        'subscriptions_membership' => ['label' => 'Subscriptions / Membership', 'note' => 'subscription_plans and provider_subscriptions tables exist with zero consumers anywhere (confirmed by audit) — no provider app to sell a package through, no customer app to sell Prime through.'],
        'app_upgrade' => ['label' => 'App Upgrade', 'note' => 'Independent version gating per app (customer/partner/rider) — queued: the force-update contract (Setting-driven min version + API endpoint) is real backend work regardless of app status, just not built yet.'],
        'advanced_system' => ['label' => 'Advanced / System', 'note' => 'Feature flags, queue behaviour, cache rules — no admin-facing surface for any of this exists yet.'],
    ];

    public string $activeTab = 'dispatch';

    // --- Scope picker (Global by default) ---
    public string $scopeType = 'global'; // global|country|city|franchise|zone
    public ?int $scopeCountryId = null;
    public ?int $scopeCityId = null;
    public ?int $scopeFranchiseId = null;
    public ?int $scopeZoneId = null;

    // --- Dispatch (ServiceMatchingJob's tuning constants) ---
    public string $dispatchOfferBatchSize = '5';
    public string $dispatchOfferTimeoutSeconds = '25';
    public string $dispatchMaxRounds = '6';
    public string $dispatchDefaultRadiusKm = '8';

    // --- Commission Defaults (Franchises\Manage's Add New pre-fill) ---
    public string $commissionDefaultModel = 'revenue_share';
    public string $commissionDefaultValue = '0';
    public string $commissionDefaultPlatformFeePercent = '0';

    // --- Booking (OTP generators, scheduling window) ---
    public string $bookingOtpLength = '4';
    public string $bookingMaxScheduleDaysAhead = '14';

    // --- Refund / Cancellation (CancellationService, via AdminCancelBookingAction) ---
    public string $cancellationFreeMinutes = '15';
    public string $cancellationFeeType = 'flat';
    public string $cancellationFeeValue = '0';

    // --- Wallet (WalletTopUpService, AcceptBookingAction, PayoutService) ---
    public string $walletCustomerMinTopup = '100';
    public string $walletCustomerMaxTopup = '10000';
    public string $walletCustomerMaxBalance = '50000';
    public string $walletCustomerDailyTopupLimit = '20000';
    public string $walletCustomerMonthlyTopupLimit = '100000';
    public string $walletProviderMinBalanceToAcceptJobs = '0';
    public string $walletProviderMinPayoutAmount = '0';
    public string $walletProviderMaxPayoutAmount = '0';
    public string $walletFranchiseMinPayoutAmount = '0';
    public string $walletFranchiseMaxPayoutAmount = '0';

    // --- Priority / Ranking (RankingEngine, consumed by DispatchService + /api/providers/nearby) ---
    public string $rankingMode = 'sequential';
    public string $rankingPrimaryCriterion = 'distance';
    public string $rankingPrimaryDirection = 'asc';
    public string $rankingSecondaryCriterion = '';
    public string $rankingSecondaryDirection = 'asc';
    public string $rankingTertiaryCriterion = '';
    public string $rankingTertiaryDirection = 'asc';
    public string $rankingWeightPriority = '0';
    public string $rankingWeightRating = '0';
    public string $rankingWeightDistance = '100';
    public string $rankingWeightOrders = '0';
    public string $rankingWeightSubscription = '0';

    // --- Loyalty / Referral (LoyaltyService/ReferralService, consumed by CompleteBookingAction) ---
    public string $loyaltyCustomerPointsPerCurrencyUnit = '0.01';
    public string $loyaltyProviderPointsPerCompletedJob = '5';
    public string $loyaltyPointsPerRupeeRedemption = '10';
    public string $loyaltyMinRedemptionPoints = '100';
    public string $loyaltyPointsExpiryDays = '365';
    public string $referralRewardType = 'wallet';
    public string $referralRewardAmount = '50';
    public string $referralRewardPoints = '100';

    // --- Notifications (ChannelResolver, consumed by every Notification class) ---
    public bool $notifyMail = true;
    public bool $notifySms = false;
    public bool $notifyPush = false;
    public bool $notifyInApp = false;

    // --- Locale & Currency (display symbol used across admin money fields) ---
    public string $localeCurrencySymbol = '₹';

    // --- Platform / Branding (admin header, <title>, login page) ---
    public string $brandingPlatformName = '1CallFix Admin';
    public string $brandingOperatingCityLabel = 'Nellore';

    // --- General / System (EnsureNotInMaintenanceMode middleware, routes/api.php) ---
    public string $systemMaintenanceMode = '0';

    // --- Payment (New Booking modal's payment-method dropdown) ---
    public string $paymentOnlineEnabled = '1';
    public string $paymentCashEnabled = '1';
    public string $paymentWalletEnabled = '1';

    public string $flashMessage = '';

    public function mount(): void
    {
        $this->loadFields();
    }

    // ============================= Scope picker =============================

    public function updatedScopeType(): void
    {
        $this->reset(['scopeCountryId', 'scopeCityId', 'scopeFranchiseId', 'scopeZoneId']);
        $this->loadFields();
    }

    public function updatedScopeCountryId(): void
    {
        $this->scopeCityId = null;
        $this->loadFields();
    }

    public function updatedScopeCityId(): void { $this->loadFields(); }

    public function updatedScopeFranchiseId(): void
    {
        $this->scopeZoneId = null;
        $this->loadFields();
    }

    public function updatedScopeZoneId(): void { $this->loadFields(); }

    /** True once the picked scope type has everything it needs to resolve to a concrete row (global always does). */
    private function scopeReady(): bool
    {
        return match ($this->scopeType) {
            'global' => true,
            'country' => (bool) $this->scopeCountryId,
            'city' => (bool) $this->scopeCityId,
            'franchise' => (bool) $this->scopeFranchiseId,
            'zone' => (bool) $this->scopeZoneId,
            default => false,
        };
    }

    /** Scope hint array for Setting::get()'s cascade, e.g. ['zone_id' => 7]. */
    private function scopeContext(): array
    {
        return match ($this->scopeType) {
            'country' => ['country_id' => $this->scopeCountryId],
            'city' => ['city_id' => $this->scopeCityId],
            'franchise' => ['franchise_id' => $this->scopeFranchiseId],
            'zone' => ['zone_id' => $this->scopeZoneId],
            default => [],
        };
    }

    /** [scopeType, scopeId] for Setting::set()/clear() — writes always target the exact picked scope, never the cascade. */
    private function scopeTypeAndId(): array
    {
        return match ($this->scopeType) {
            'country' => ['country', $this->scopeCountryId],
            'city' => ['city', $this->scopeCityId],
            'franchise' => ['franchise', $this->scopeFranchiseId],
            'zone' => ['zone', $this->scopeZoneId],
            default => ['global', null],
        };
    }

    private function loadFields(): void
    {
        $scope = $this->scopeReady() ? $this->scopeContext() : [];

        $this->dispatchOfferBatchSize = (string) Setting::get('dispatch.offer_batch_size', '5', $scope);
        $this->dispatchOfferTimeoutSeconds = (string) Setting::get('dispatch.offer_timeout_seconds', '25', $scope);
        $this->dispatchMaxRounds = (string) Setting::get('dispatch.max_rounds', '6', $scope);
        $this->dispatchDefaultRadiusKm = (string) Setting::get('dispatch.default_radius_km', '8', $scope);

        $this->commissionDefaultModel = Setting::get('commission.default_model', 'revenue_share', $scope);
        $this->commissionDefaultValue = (string) Setting::get('commission.default_value', '0', $scope);
        $this->commissionDefaultPlatformFeePercent = (string) Setting::get('commission.default_platform_fee_percent', '0', $scope);

        $this->bookingOtpLength = (string) Setting::get('booking.otp_length', '4', $scope);
        $this->bookingMaxScheduleDaysAhead = (string) Setting::get('booking.max_schedule_days_ahead', '14', $scope);

        $this->cancellationFreeMinutes = (string) Setting::get('cancellation.free_minutes', '15', $scope);
        $this->cancellationFeeType = Setting::get('cancellation.fee_type', 'flat', $scope);
        $this->cancellationFeeValue = (string) Setting::get('cancellation.fee_value', '0', $scope);

        $this->walletCustomerMinTopup = (string) Setting::get('wallet.customer_min_topup', '100', $scope);
        $this->walletCustomerMaxTopup = (string) Setting::get('wallet.customer_max_topup', '10000', $scope);
        $this->walletCustomerMaxBalance = (string) Setting::get('wallet.customer_max_balance', '50000', $scope);
        $this->walletCustomerDailyTopupLimit = (string) Setting::get('wallet.customer_daily_topup_limit', '20000', $scope);
        $this->walletCustomerMonthlyTopupLimit = (string) Setting::get('wallet.customer_monthly_topup_limit', '100000', $scope);
        $this->walletProviderMinBalanceToAcceptJobs = (string) Setting::get('wallet.provider_min_balance_to_accept_jobs', '0', $scope);
        $this->walletProviderMinPayoutAmount = (string) Setting::get('wallet.provider_min_payout_amount', '0', $scope);
        $this->walletProviderMaxPayoutAmount = (string) Setting::get('wallet.provider_max_payout_amount', '0', $scope);
        $this->walletFranchiseMinPayoutAmount = (string) Setting::get('wallet.franchise_min_payout_amount', '0', $scope);
        $this->walletFranchiseMaxPayoutAmount = (string) Setting::get('wallet.franchise_max_payout_amount', '0', $scope);

        $rankingConfig = app(RankingConfigResolver::class)->resolve('providers', $scope);
        $this->rankingMode = $rankingConfig['mode'];
        $sequentialSlots = array_values($rankingConfig['sequential']);
        $this->rankingPrimaryCriterion = $sequentialSlots[0]['key'] ?? 'distance';
        $this->rankingPrimaryDirection = $sequentialSlots[0]['direction'] ?? 'asc';
        $this->rankingSecondaryCriterion = $sequentialSlots[1]['key'] ?? '';
        $this->rankingSecondaryDirection = $sequentialSlots[1]['direction'] ?? 'asc';
        $this->rankingTertiaryCriterion = $sequentialSlots[2]['key'] ?? '';
        $this->rankingTertiaryDirection = $sequentialSlots[2]['direction'] ?? 'asc';
        $this->rankingWeightPriority = (string) $rankingConfig['weights']['priority'];
        $this->rankingWeightRating = (string) $rankingConfig['weights']['rating'];
        $this->rankingWeightDistance = (string) $rankingConfig['weights']['distance'];
        $this->rankingWeightOrders = (string) $rankingConfig['weights']['orders'];
        $this->rankingWeightSubscription = (string) $rankingConfig['weights']['subscription'];

        $this->loyaltyCustomerPointsPerCurrencyUnit = (string) Setting::get('loyalty.customer_points_per_currency_unit', '0.01', $scope);
        $this->loyaltyProviderPointsPerCompletedJob = (string) Setting::get('loyalty.provider_points_per_completed_job', '5', $scope);
        $this->loyaltyPointsPerRupeeRedemption = (string) Setting::get('loyalty.points_per_rupee_redemption', '10', $scope);
        $this->loyaltyMinRedemptionPoints = (string) Setting::get('loyalty.min_redemption_points', '100', $scope);
        $this->loyaltyPointsExpiryDays = (string) Setting::get('loyalty.points_expiry_days', '365', $scope);
        $this->referralRewardType = Setting::get('referral.reward_type', 'wallet', $scope);
        $this->referralRewardAmount = (string) Setting::get('referral.reward_amount', '50', $scope);
        $this->referralRewardPoints = (string) Setting::get('referral.reward_points', '100', $scope);

        $configuredChannels = explode(',', Setting::get('notifications.channels', 'mail', $scope));
        $this->notifyMail = in_array('mail', $configuredChannels, true);
        $this->notifySms = in_array('sms', $configuredChannels, true);
        $this->notifyPush = in_array('push', $configuredChannels, true);
        $this->notifyInApp = in_array('in_app', $configuredChannels, true);

        $this->localeCurrencySymbol = Setting::get('locale.currency_symbol', '₹', $scope);

        $this->brandingPlatformName = Setting::get('branding.platform_name', '1CallFix Admin', $scope);
        $this->brandingOperatingCityLabel = Setting::get('branding.operating_city_label', 'Nellore', $scope);

        $this->systemMaintenanceMode = (string) Setting::get('system.maintenance_mode', '0', $scope);

        $this->paymentOnlineEnabled = (string) Setting::get('payment.online_enabled', '1', $scope);
        $this->paymentCashEnabled = (string) Setting::get('payment.cash_enabled', '1', $scope);
        $this->paymentWalletEnabled = (string) Setting::get('payment.wallet_enabled', '1', $scope);
    }

    /** Keys with a real override AT the exact picked scope (not inherited) — drives the "overridden here" badge. */
    public function getOverriddenKeysProperty(): array
    {
        if (! $this->scopeReady() || $this->scopeType === 'global') {
            return [];
        }

        [$scopeType, $scopeId] = $this->scopeTypeAndId();

        return Setting::where('scope_type', $scopeType)->where('scope_id', $scopeId)->pluck('key')->all();
    }

    public function clearOverride(string $key): void
    {
        if (! $this->scopeReady() || $this->scopeType === 'global') {
            return;
        }

        [$scopeType, $scopeId] = $this->scopeTypeAndId();
        Setting::clear($key, $scopeType, $scopeId);
        $this->loadFields();
        $this->flashMessage = 'Override cleared — now inheriting from a broader scope.';
    }

    // ============================== Save (per tab) ==============================

    public function saveDispatch(): void
    {
        $this->validate([
            'dispatchOfferBatchSize' => ['required', 'integer', 'min:1', 'max:20'],
            'dispatchOfferTimeoutSeconds' => ['required', 'integer', 'min:5', 'max:300'],
            'dispatchMaxRounds' => ['required', 'integer', 'min:1', 'max:20'],
            'dispatchDefaultRadiusKm' => ['required', 'integer', 'min:1', 'max:100'],
        ], [], [
            'dispatchOfferBatchSize' => 'offer batch size',
            'dispatchOfferTimeoutSeconds' => 'offer timeout',
            'dispatchMaxRounds' => 'max dispatch rounds',
            'dispatchDefaultRadiusKm' => 'default dispatch radius',
        ]);

        [$scopeType, $scopeId] = $this->scopeTypeAndId();

        Setting::set('dispatch.offer_batch_size', $this->dispatchOfferBatchSize, $scopeType, $scopeId);
        Setting::set('dispatch.offer_timeout_seconds', $this->dispatchOfferTimeoutSeconds, $scopeType, $scopeId);
        Setting::set('dispatch.max_rounds', $this->dispatchMaxRounds, $scopeType, $scopeId);
        Setting::set('dispatch.default_radius_km', $this->dispatchDefaultRadiusKm, $scopeType, $scopeId);

        $this->flashMessage = 'Dispatch settings saved'.($scopeType === 'global' ? '.' : " for this {$scopeType}.");
    }

    public function saveCommission(): void
    {
        $this->validate([
            'commissionDefaultModel' => ['required', 'in:revenue_share,flat_fee,subscription_only'],
            'commissionDefaultValue' => ['required', 'numeric', 'min:0'],
            'commissionDefaultPlatformFeePercent' => ['required', 'numeric', 'min:0', 'max:100'],
        ], [], [
            'commissionDefaultModel' => 'default commission model',
            'commissionDefaultValue' => 'default commission value',
            'commissionDefaultPlatformFeePercent' => 'default platform fee',
        ]);

        [$scopeType, $scopeId] = $this->scopeTypeAndId();

        Setting::set('commission.default_model', $this->commissionDefaultModel, $scopeType, $scopeId);
        Setting::set('commission.default_value', $this->commissionDefaultValue, $scopeType, $scopeId);
        Setting::set('commission.default_platform_fee_percent', $this->commissionDefaultPlatformFeePercent, $scopeType, $scopeId);

        $this->flashMessage = 'Commission defaults saved'.($scopeType === 'global' ? '.' : " for this {$scopeType}.");
    }

    public function saveBooking(): void
    {
        $this->validate([
            'bookingOtpLength' => ['required', 'integer', 'min:4', 'max:8'],
            'bookingMaxScheduleDaysAhead' => ['required', 'integer', 'min:1', 'max:90'],
        ], [], [
            'bookingOtpLength' => 'OTP length',
            'bookingMaxScheduleDaysAhead' => 'max schedule days ahead',
        ]);

        [$scopeType, $scopeId] = $this->scopeTypeAndId();

        Setting::set('booking.otp_length', $this->bookingOtpLength, $scopeType, $scopeId);
        Setting::set('booking.max_schedule_days_ahead', $this->bookingMaxScheduleDaysAhead, $scopeType, $scopeId);

        $this->flashMessage = 'Booking settings saved'.($scopeType === 'global' ? '.' : " for this {$scopeType}.");
    }

    /** Real consumer: App\Services\CancellationService::calculateFee(), called from AdminCancelBookingAction. */
    public function saveCancellation(): void
    {
        $this->validate([
            'cancellationFreeMinutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'cancellationFeeType' => ['required', 'in:flat,percent'],
            'cancellationFeeValue' => ['required', 'numeric', 'min:0'],
        ], [], [
            'cancellationFreeMinutes' => 'free cancellation window',
            'cancellationFeeType' => 'fee type',
            'cancellationFeeValue' => 'fee value',
        ]);

        [$scopeType, $scopeId] = $this->scopeTypeAndId();

        Setting::set('cancellation.free_minutes', $this->cancellationFreeMinutes, $scopeType, $scopeId);
        Setting::set('cancellation.fee_type', $this->cancellationFeeType, $scopeType, $scopeId);
        Setting::set('cancellation.fee_value', $this->cancellationFeeValue, $scopeType, $scopeId);

        $this->flashMessage = 'Refund / Cancellation settings saved'.($scopeType === 'global' ? '.' : " for this {$scopeType}.");
    }

    /** Real consumers: App\Services\DispatchService::findCandidates() (dispatch) and ::nearbyForService() (GET /api/providers/nearby), via RankingEngine/RankingConfigResolver. */
    public function saveRanking(): void
    {
        if (! auth()->user()->hasPermission('settings.manage')) {
            $this->addError('permission', 'You do not have permission to manage ranking settings.');
            return;
        }

        $this->validate([
            'rankingMode' => ['required', 'in:sequential,weighted'],
            'rankingPrimaryCriterion' => ['required', 'in:'.implode(',', RankingConfigResolver::CRITERIA)],
            'rankingSecondaryCriterion' => ['nullable', 'in:'.implode(',', RankingConfigResolver::CRITERIA)],
            'rankingTertiaryCriterion' => ['nullable', 'in:'.implode(',', RankingConfigResolver::CRITERIA)],
            'rankingWeightPriority' => ['required', 'numeric', 'min:0'],
            'rankingWeightRating' => ['required', 'numeric', 'min:0'],
            'rankingWeightDistance' => ['required', 'numeric', 'min:0'],
            'rankingWeightOrders' => ['required', 'numeric', 'min:0'],
            'rankingWeightSubscription' => ['required', 'numeric', 'min:0'],
        ], [], ['rankingPrimaryCriterion' => 'primary criterion']);

        $slots = collect([
            ['key' => $this->rankingPrimaryCriterion, 'direction' => $this->rankingPrimaryDirection],
            ['key' => $this->rankingSecondaryCriterion, 'direction' => $this->rankingSecondaryDirection],
            ['key' => $this->rankingTertiaryCriterion, 'direction' => $this->rankingTertiaryDirection],
        ])->filter(fn ($s) => $s['key'] !== '');

        if ($slots->pluck('key')->unique()->count() !== $slots->count()) {
            $this->addError('rankingSecondaryCriterion', 'Each ranking criterion can only be used once (primary/secondary/tertiary).');
            return;
        }

        [$scopeType, $scopeId] = $this->scopeTypeAndId();

        Setting::set('ranking.providers.mode', $this->rankingMode, $scopeType, $scopeId);
        Setting::set('ranking.providers.sequential', $slots->map(fn ($s) => "{$s['key']}:{$s['direction']}")->implode(','), $scopeType, $scopeId);
        Setting::set('ranking.providers.weights', json_encode([
            'priority' => (float) $this->rankingWeightPriority,
            'rating' => (float) $this->rankingWeightRating,
            'distance' => (float) $this->rankingWeightDistance,
            'orders' => (float) $this->rankingWeightOrders,
            'subscription' => (float) $this->rankingWeightSubscription,
        ]), $scopeType, $scopeId);

        $this->flashMessage = 'Ranking settings saved'.($scopeType === 'global' ? '.' : " for this {$scopeType}.");
    }

    /** Real consumers: App\Services\LoyaltyService (earn/redeem, wired into CompleteBookingAction), App\Services\ReferralService (qualification reward). */
    public function saveLoyalty(): void
    {
        if (! auth()->user()->hasPermission('settings.manage')) {
            $this->addError('permission', 'You do not have permission to manage loyalty/referral settings.');
            return;
        }

        $this->validate([
            'loyaltyCustomerPointsPerCurrencyUnit' => ['required', 'numeric', 'min:0'],
            'loyaltyProviderPointsPerCompletedJob' => ['required', 'integer', 'min:0'],
            'loyaltyPointsPerRupeeRedemption' => ['required', 'integer', 'min:1'],
            'loyaltyMinRedemptionPoints' => ['required', 'integer', 'min:0'],
            'loyaltyPointsExpiryDays' => ['required', 'integer', 'min:0'],
            'referralRewardType' => ['required', 'in:wallet,points'],
            'referralRewardAmount' => ['required', 'numeric', 'min:0'],
            'referralRewardPoints' => ['required', 'integer', 'min:0'],
        ], [], [
            'loyaltyCustomerPointsPerCurrencyUnit' => 'customer earn rate', 'loyaltyProviderPointsPerCompletedJob' => 'provider earn rate',
            'loyaltyPointsPerRupeeRedemption' => 'redemption rate', 'loyaltyMinRedemptionPoints' => 'minimum redemption',
            'loyaltyPointsExpiryDays' => 'points expiry', 'referralRewardType' => 'referral reward type',
            'referralRewardAmount' => 'referral wallet reward', 'referralRewardPoints' => 'referral points reward',
        ]);

        [$scopeType, $scopeId] = $this->scopeTypeAndId();

        foreach ([
            'loyalty.customer_points_per_currency_unit' => $this->loyaltyCustomerPointsPerCurrencyUnit,
            'loyalty.provider_points_per_completed_job' => $this->loyaltyProviderPointsPerCompletedJob,
            'loyalty.points_per_rupee_redemption' => $this->loyaltyPointsPerRupeeRedemption,
            'loyalty.min_redemption_points' => $this->loyaltyMinRedemptionPoints,
            'loyalty.points_expiry_days' => $this->loyaltyPointsExpiryDays,
            'referral.reward_type' => $this->referralRewardType,
            'referral.reward_amount' => $this->referralRewardAmount,
            'referral.reward_points' => $this->referralRewardPoints,
        ] as $key => $value) {
            Setting::set($key, $value, $scopeType, $scopeId);
        }

        $this->flashMessage = 'Loyalty / Referral settings saved'.($scopeType === 'global' ? '.' : " for this {$scopeType}.");
    }

    /** Real consumers: App\Services\WalletTopUpService (customer fields), App\Actions\AcceptBookingAction (min balance), App\Services\PayoutService (payout min/max). */
    public function saveWallet(): void
    {
        // settings.manage has existed in the RBAC catalog since the RBAC
        // phase but was never actually enforced anywhere in Settings\Manage
        // -- enforcing it here, on this new tab, rather than retrofitting
        // the other nine existing tabs in the same pass.
        if (! auth()->user()->hasPermission('settings.manage')) {
            $this->addError('permission', 'You do not have permission to manage wallet settings.');
            return;
        }

        $this->validate([
            'walletCustomerMinTopup' => ['required', 'numeric', 'min:0'],
            'walletCustomerMaxTopup' => ['required', 'numeric', 'gt:walletCustomerMinTopup'],
            'walletCustomerMaxBalance' => ['required', 'numeric', 'min:0'],
            'walletCustomerDailyTopupLimit' => ['required', 'numeric', 'min:0'],
            'walletCustomerMonthlyTopupLimit' => ['required', 'numeric', 'gte:walletCustomerDailyTopupLimit'],
            'walletProviderMinBalanceToAcceptJobs' => ['required', 'numeric', 'min:0'],
            'walletProviderMinPayoutAmount' => ['required', 'numeric', 'min:0'],
            'walletProviderMaxPayoutAmount' => ['required', 'numeric', 'min:0'],
            'walletFranchiseMinPayoutAmount' => ['required', 'numeric', 'min:0'],
            'walletFranchiseMaxPayoutAmount' => ['required', 'numeric', 'min:0'],
        ], [], [
            'walletCustomerMinTopup' => 'minimum top-up', 'walletCustomerMaxTopup' => 'maximum top-up',
            'walletCustomerMaxBalance' => 'maximum wallet balance', 'walletCustomerDailyTopupLimit' => 'daily top-up limit',
            'walletCustomerMonthlyTopupLimit' => 'monthly top-up limit',
            'walletProviderMinBalanceToAcceptJobs' => 'minimum balance to accept jobs',
            'walletProviderMinPayoutAmount' => 'provider minimum payout', 'walletProviderMaxPayoutAmount' => 'provider maximum payout',
            'walletFranchiseMinPayoutAmount' => 'franchise minimum payout', 'walletFranchiseMaxPayoutAmount' => 'franchise maximum payout',
        ]);

        if ((float) $this->walletProviderMaxPayoutAmount > 0 && (float) $this->walletProviderMaxPayoutAmount < (float) $this->walletProviderMinPayoutAmount) {
            $this->addError('walletProviderMaxPayoutAmount', 'Maximum payout must be greater than the minimum (or 0 for no cap).');
            return;
        }
        if ((float) $this->walletFranchiseMaxPayoutAmount > 0 && (float) $this->walletFranchiseMaxPayoutAmount < (float) $this->walletFranchiseMinPayoutAmount) {
            $this->addError('walletFranchiseMaxPayoutAmount', 'Maximum payout must be greater than the minimum (or 0 for no cap).');
            return;
        }

        [$scopeType, $scopeId] = $this->scopeTypeAndId();

        foreach ([
            'wallet.customer_min_topup' => $this->walletCustomerMinTopup,
            'wallet.customer_max_topup' => $this->walletCustomerMaxTopup,
            'wallet.customer_max_balance' => $this->walletCustomerMaxBalance,
            'wallet.customer_daily_topup_limit' => $this->walletCustomerDailyTopupLimit,
            'wallet.customer_monthly_topup_limit' => $this->walletCustomerMonthlyTopupLimit,
            'wallet.provider_min_balance_to_accept_jobs' => $this->walletProviderMinBalanceToAcceptJobs,
            'wallet.provider_min_payout_amount' => $this->walletProviderMinPayoutAmount,
            'wallet.provider_max_payout_amount' => $this->walletProviderMaxPayoutAmount,
            'wallet.franchise_min_payout_amount' => $this->walletFranchiseMinPayoutAmount,
            'wallet.franchise_max_payout_amount' => $this->walletFranchiseMaxPayoutAmount,
        ] as $key => $value) {
            Setting::set($key, $value, $scopeType, $scopeId);
        }

        $this->flashMessage = 'Wallet settings saved'.($scopeType === 'global' ? '.' : " for this {$scopeType}.");
    }

    /** Real consumer: App\Notifications\Support\ChannelResolver, called from every BookingStatus/PaymentStatus/PayoutStatus dispatch site. */
    public function saveNotifications(): void
    {
        $channels = array_filter([
            $this->notifyMail ? 'mail' : null,
            $this->notifySms ? 'sms' : null,
            $this->notifyPush ? 'push' : null,
            $this->notifyInApp ? 'in_app' : null,
        ]);

        [$scopeType, $scopeId] = $this->scopeTypeAndId();

        Setting::set('notifications.channels', implode(',', $channels) ?: 'mail', $scopeType, $scopeId);

        $this->flashMessage = 'Notification settings saved'.($scopeType === 'global' ? '.' : " for this {$scopeType}.");
    }

    public function saveLocale(): void
    {
        $this->validate([
            'localeCurrencySymbol' => ['required', 'string', 'max:5'],
        ], [], [
            'localeCurrencySymbol' => 'currency symbol',
        ]);

        [$scopeType, $scopeId] = $this->scopeTypeAndId();

        Setting::set('locale.currency_symbol', $this->localeCurrencySymbol, $scopeType, $scopeId);

        $this->flashMessage = 'Locale & currency settings saved'.($scopeType === 'global' ? '.' : " for this {$scopeType}.");
    }

    public function saveBranding(): void
    {
        $this->validate([
            'brandingPlatformName' => ['required', 'string', 'max:100'],
            'brandingOperatingCityLabel' => ['required', 'string', 'max:100'],
        ], [], [
            'brandingPlatformName' => 'platform name',
            'brandingOperatingCityLabel' => 'operating city label',
        ]);

        [$scopeType, $scopeId] = $this->scopeTypeAndId();

        Setting::set('branding.platform_name', $this->brandingPlatformName, $scopeType, $scopeId);
        Setting::set('branding.operating_city_label', $this->brandingOperatingCityLabel, $scopeType, $scopeId);

        $this->flashMessage = 'Branding settings saved'.($scopeType === 'global' ? '.' : " for this {$scopeType}.");
    }

    /**
     * Real consumer: app/Http/Middleware/EnsureNotInMaintenanceMode.php,
     * applied to the booking-operation API routes in routes/api.php.
     */
    public function saveGeneralSystem(): void
    {
        $this->validate([
            'systemMaintenanceMode' => ['required', 'in:0,1'],
        ], [], [
            'systemMaintenanceMode' => 'maintenance mode',
        ]);

        [$scopeType, $scopeId] = $this->scopeTypeAndId();

        Setting::set('system.maintenance_mode', $this->systemMaintenanceMode, $scopeType, $scopeId);

        $this->flashMessage = 'General / System settings saved'.($scopeType === 'global' ? '.' : " for this {$scopeType}.");
    }

    /**
     * Real consumer: the New Booking modal's payment-method dropdown
     * (app/Livewire/Bookings/Index.php) only offers methods enabled here,
     * and createBooking() re-validates the submission against the same
     * enabled set. Gateway credentials/mode stay in .env, untouched — this
     * only controls which of the three existing methods a customer is
     * offered, not how Razorpay itself is configured.
     */
    public function savePayment(): void
    {
        $this->validate([
            'paymentOnlineEnabled' => ['required', 'in:0,1'],
            'paymentCashEnabled' => ['required', 'in:0,1'],
            'paymentWalletEnabled' => ['required', 'in:0,1'],
        ]);

        if ($this->paymentOnlineEnabled === '0' && $this->paymentCashEnabled === '0' && $this->paymentWalletEnabled === '0') {
            $this->addError('paymentOnlineEnabled', 'At least one payment method must stay enabled.');
            return;
        }

        [$scopeType, $scopeId] = $this->scopeTypeAndId();

        Setting::set('payment.online_enabled', $this->paymentOnlineEnabled, $scopeType, $scopeId);
        Setting::set('payment.cash_enabled', $this->paymentCashEnabled, $scopeType, $scopeId);
        Setting::set('payment.wallet_enabled', $this->paymentWalletEnabled, $scopeType, $scopeId);

        $this->flashMessage = 'Payment settings saved'.($scopeType === 'global' ? '.' : " for this {$scopeType}.");
    }

    public function render()
    {
        // Read-only gateway identity/status for the Payment tab -- which
        // provider is bound (App\Providers\AppServiceProvider), whether it
        // has real credentials configured, and a masked (never secret)
        // fragment of its public key, so an admin can confirm the gateway
        // is live-configured without ever seeing key_secret/webhook_secret,
        // which never leave RazorpayService. Gateway credentials themselves
        // still live only in .env, exactly as the existing Payment tab's
        // own docblock already states -- this only surfaces their STATUS.
        $gateway = app(\App\Contracts\PaymentGateway::class);

        return view('livewire.settings.manage', [
            'scopeCountries' => Country::where('is_active', true)->orderBy('name')->get(),
            'scopeCities' => $this->scopeCountryId
                ? City::where('country_id', $this->scopeCountryId)->where('is_active', true)->orderBy('name')->get()
                : collect(),
            'scopeFranchises' => Franchise::orderBy('name')->get(['id', 'name', 'code']),
            'scopeZones' => $this->scopeFranchiseId
                ? Zone::where('franchise_id', $this->scopeFranchiseId)->where('is_active', true)->orderBy('name')->get()
                : collect(),
            'mapsConfigured' => (bool) config('services.google_maps.key'),
            'broadcastDriver' => config('broadcasting.default', env('BROADCAST_CONNECTION', 'log')),
            'cmsPageCount' => \App\Models\ContentPage::count(),
            'cmsFaqCount' => \App\Models\Faq::count(),
            'paymentGatewayName' => $gateway->displayName(),
            'paymentGatewayConfigured' => $gateway->isConfigured(),
            'paymentGatewayMaskedKey' => $gateway->maskedPublicIdentifier(),
        ])->layout('layouts.admin', ['title' => 'Settings']);
    }
}
