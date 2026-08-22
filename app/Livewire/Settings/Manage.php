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
    // Circuit breaker: after this many timeouts on the SAME booking, a
    // provider stops being re-offered that specific booking (still eligible
    // for other bookings). Added with the timeout-reeligibility fix — half
    // of dispatchMaxRounds by default, so a chronically-unresponsive
    // provider burns out before the booking itself exhausts all rounds.
    public string $dispatchMaxTimeoutsPerProvider = '3';

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
    /**
     * Read via `Setting::get('referral.pending_expiry_days', '', [])` —
     * ReferralService passes a hardcoded empty scope (not the picked scope
     * like every sibling field above), so this ALWAYS writes/reads at
     * Global regardless of the scope picker, and the field is disabled
     * whenever a non-global scope is picked (Phase 11 audit finding: this
     * key was read by real code with no admin UI at all).
     */
    public string $referralPendingExpiryDays = '';

    // --- Notifications (ChannelResolver, consumed by every Notification class) ---
    public bool $notifyMail = true;
    public bool $notifySms = false;
    public bool $notifyPush = false;
    public bool $notifyInApp = false;

    // --- Daily Digest (Sidebar Reorganization + Daily Digest session) ---
    // Consumed by DailyDigestDispatchService::sendIfDue() -- see its own
    // docblock for why the send time is read there (inside the scheduled
    // command's own execution) rather than baked into a dailyAt() cron
    // argument in routes/console.php. Global scope only (this session
    // deliberately doesn't offer a per-franchise send time -- one platform-
    // wide schedule, same as every other Schedule::command() entry).
    public string $digestSendTimeLocal = '08:00';
    public bool $digestWhatsappEnabled = false;

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

    /**
     * KYC (Phase 11 audit finding: all four keys below were read by real,
     * already-wired code — KycWithdrawalPolicyService, KycVerificationVideoService,
     * KycDocumentService, ReviewProviderKycAction — with no admin UI at
     * all, silently stuck on their hardcoded fallback default forever).
     * Scope-aware: each consumer is called with the real
     * franchise/zone-derived scope of the provider/payout in question (see
     * PayoutService::payoutScope(), Providers\Show), same cascade as every
     * other tab.
     */
    public string $kycWithdrawalRestrictionEnabled = '1';
    public string $kycRequireVerificationVideo = '1';
    public string $kycMaxDocumentSizeMb = '10';
    public string $kycMaxVideoSizeMb = '50';

    /**
     * Compensation (Phase 11 audit finding: all ten keys below are read by
     * CompensationService — wired into CompleteBookingAction since Phase 5
     * — with no admin UI, so every rate was permanently stuck at its 0/-1
     * "disabled" default no matter what an admin wanted). Every rate still
     * defaults to 0 / every window to -1 (disabled) here too — this only
     * adds the ability to change them, it does not invent values.
     */
    public string $compensationOvertimeRatePerMinute = '0';
    public string $compensationOvertimeThresholdMinutes = '0';
    public string $compensationNightWindowStartHour = '-1';
    public string $compensationNightWindowEndHour = '-1';
    public string $compensationNightFlatAmount = '0';
    public string $compensationPeakWindowStartHour = '-1';
    public string $compensationPeakWindowEndHour = '-1';
    public string $compensationPeakFlatAmount = '0';
    public string $compensationRainFlatAmount = '0';
    public string $compensationWaitingRatePerMinute = '0';

    /**
     * Security / OTP (Phase 11 audit finding: OtpService/QrChallengeService
     * call `Setting::get('auth.otp_length', 6)` etc. with NO scope
     * argument at all — genuinely global-only, unlike every field above.
     * The scope picker above this tab is ignored entirely; saveSecurity()
     * always writes to Global.
     */
    public string $authOtpLength = '6';
    public string $authOtpExpirySeconds = '300';
    public string $authOtpResendCooldownSeconds = '30';
    public string $authOtpMaxAttempts = '5';
    public string $authQrChallengeExpirySeconds = '120';

    /**
     * Operations (Phase 11 audit finding: StuckBookingService/
     * DispatchHealthService both call Setting::get() with no scope
     * argument — genuinely global-only, same as Security/OTP above. The
     * scope picker is ignored; saveOperations() always writes to Global.
     */
    public string $opsStuckThresholdSearchingProvider = '30';
    public string $opsStuckThresholdAssigned = '60';
    public string $opsStuckThresholdProviderEnRoute = '60';
    public string $opsStuckThresholdInProgress = '240';
    public string $opsStuckThresholdOnHold = '1440';
    public string $opsDispatchOfferResponseTimeoutMinutes = '2';

    /**
     * Subscriptions (Phase 11 audit finding: RenewalService calls
     * `Setting::get('plan.grace_period_days', '0', [])` — hardcoded empty
     * scope, global-only. Replaces the stale "subscriptions_membership"
     * placeholder, whose own note ("zero consumers anywhere") was no
     * longer true — RenewalService is a real, already-wired consumer.
     */
    public string $subscriptionsGracePeriodDays = '0';

    public string $flashMessage = '';

    public function mount(): void
    {
        // No view-level check existed at all — settings.manage has existed
        // in the RBAC catalog since the RBAC phase but was only enforced on
        // 3 of the 10 real tabs' save*() methods (saveRanking/saveLoyalty/
        // saveWallet), and never at all on view (Phase 11 audit finding,
        // same bug class addendum #1 already fixed on 15 other screens).
        // Gating the whole screen here means every save*() method is now
        // implicitly covered too — the 3 existing per-method checks are
        // left in place as harmless defense-in-depth.
        abort_unless(auth()->user()->hasPermissionAnywhere('settings.manage'), 403, 'You do not have permission to view settings.');

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
        $this->dispatchMaxTimeoutsPerProvider = (string) Setting::get('dispatch.max_timeouts_per_provider', '3', $scope);

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
        $this->referralPendingExpiryDays = (string) Setting::get('referral.pending_expiry_days', '', []);

        $configuredChannels = explode(',', Setting::get('notifications.channels', 'mail', $scope));
        $this->notifyMail = in_array('mail', $configuredChannels, true);
        $this->notifySms = in_array('sms', $configuredChannels, true);
        $this->notifyPush = in_array('push', $configuredChannels, true);
        $this->notifyInApp = in_array('in_app', $configuredChannels, true);

        // Global-only by design (see the property docblocks above) -- read
        // with no $scope hint, exactly like referralPendingExpiryDays just
        // above, which documents the same deliberate choice.
        $this->digestSendTimeLocal = Setting::get('digest.send_time_local', '08:00', []);
        $this->digestWhatsappEnabled = (bool) (int) Setting::get('digest.whatsapp_enabled', '0', []);

        $this->localeCurrencySymbol = Setting::get('locale.currency_symbol', '₹', $scope);

        $this->brandingPlatformName = Setting::get('branding.platform_name', '1CallFix Admin', $scope);
        $this->brandingOperatingCityLabel = Setting::get('branding.operating_city_label', 'Nellore', $scope);

        $this->systemMaintenanceMode = (string) Setting::get('system.maintenance_mode', '0', $scope);

        $this->paymentOnlineEnabled = (string) Setting::get('payment.online_enabled', '1', $scope);
        $this->paymentCashEnabled = (string) Setting::get('payment.cash_enabled', '1', $scope);
        $this->paymentWalletEnabled = (string) Setting::get('payment.wallet_enabled', '1', $scope);

        $this->kycWithdrawalRestrictionEnabled = (string) (int) Setting::get('kyc.withdrawal_restriction_enabled', '1', $scope);
        $this->kycRequireVerificationVideo = (string) (int) Setting::get('kyc.require_verification_video', '1', $scope);
        $this->kycMaxDocumentSizeMb = (string) Setting::get('kyc.max_document_size_mb', '10', $scope);
        $this->kycMaxVideoSizeMb = (string) Setting::get('kyc.max_video_size_mb', '50', $scope);

        $this->compensationOvertimeRatePerMinute = (string) Setting::get('compensation.overtime_rate_per_minute', '0', $scope);
        $this->compensationOvertimeThresholdMinutes = (string) Setting::get('compensation.overtime_threshold_minutes', '0', $scope);
        $this->compensationNightWindowStartHour = (string) Setting::get('compensation.night_window_start_hour', '-1', $scope);
        $this->compensationNightWindowEndHour = (string) Setting::get('compensation.night_window_end_hour', '-1', $scope);
        $this->compensationNightFlatAmount = (string) Setting::get('compensation.night_flat_amount', '0', $scope);
        $this->compensationPeakWindowStartHour = (string) Setting::get('compensation.peak_window_start_hour', '-1', $scope);
        $this->compensationPeakWindowEndHour = (string) Setting::get('compensation.peak_window_end_hour', '-1', $scope);
        $this->compensationPeakFlatAmount = (string) Setting::get('compensation.peak_flat_amount', '0', $scope);
        $this->compensationRainFlatAmount = (string) Setting::get('compensation.rain_flat_amount', '0', $scope);
        $this->compensationWaitingRatePerMinute = (string) Setting::get('compensation.waiting_rate_per_minute', '0', $scope);

        // Global-only (no scope argument at their call sites) — always read
        // at Global regardless of the picker above.
        $this->authOtpLength = (string) Setting::get('auth.otp_length', '6');
        $this->authOtpExpirySeconds = (string) Setting::get('auth.otp_expiry_seconds', '300');
        $this->authOtpResendCooldownSeconds = (string) Setting::get('auth.otp_resend_cooldown_seconds', '30');
        $this->authOtpMaxAttempts = (string) Setting::get('auth.otp_max_attempts', '5');
        $this->authQrChallengeExpirySeconds = (string) Setting::get('auth.qr_challenge_expiry_seconds', '120');

        $this->opsStuckThresholdSearchingProvider = (string) Setting::get('operations.stuck_threshold_minutes.searching_provider', '30');
        $this->opsStuckThresholdAssigned = (string) Setting::get('operations.stuck_threshold_minutes.assigned', '60');
        $this->opsStuckThresholdProviderEnRoute = (string) Setting::get('operations.stuck_threshold_minutes.provider_en_route', '60');
        $this->opsStuckThresholdInProgress = (string) Setting::get('operations.stuck_threshold_minutes.in_progress', '240');
        $this->opsStuckThresholdOnHold = (string) Setting::get('operations.stuck_threshold_minutes.on_hold', '1440');
        $this->opsDispatchOfferResponseTimeoutMinutes = (string) Setting::get('dispatch.offer_response_timeout_minutes', '2');

        $this->subscriptionsGracePeriodDays = (string) Setting::get('plan.grace_period_days', '0');
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
            'dispatchMaxTimeoutsPerProvider' => ['required', 'integer', 'min:1', 'max:20'],
        ], [], [
            'dispatchOfferBatchSize' => 'offer batch size',
            'dispatchOfferTimeoutSeconds' => 'offer timeout',
            'dispatchMaxRounds' => 'max dispatch rounds',
            'dispatchDefaultRadiusKm' => 'default dispatch radius',
            'dispatchMaxTimeoutsPerProvider' => 'max timeouts per provider',
        ]);

        [$scopeType, $scopeId] = $this->scopeTypeAndId();

        Setting::set('dispatch.offer_batch_size', $this->dispatchOfferBatchSize, $scopeType, $scopeId);
        Setting::set('dispatch.offer_timeout_seconds', $this->dispatchOfferTimeoutSeconds, $scopeType, $scopeId);
        Setting::set('dispatch.max_rounds', $this->dispatchMaxRounds, $scopeType, $scopeId);
        Setting::set('dispatch.default_radius_km', $this->dispatchDefaultRadiusKm, $scopeType, $scopeId);
        Setting::set('dispatch.max_timeouts_per_provider', $this->dispatchMaxTimeoutsPerProvider, $scopeType, $scopeId);

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
            'referralPendingExpiryDays' => ['nullable', 'integer', 'min:1'],
        ], [], [
            'loyaltyCustomerPointsPerCurrencyUnit' => 'customer earn rate', 'loyaltyProviderPointsPerCompletedJob' => 'provider earn rate',
            'loyaltyPointsPerRupeeRedemption' => 'redemption rate', 'loyaltyMinRedemptionPoints' => 'minimum redemption',
            'loyaltyPointsExpiryDays' => 'points expiry', 'referralRewardType' => 'referral reward type',
            'referralRewardAmount' => 'referral wallet reward', 'referralRewardPoints' => 'referral points reward',
            'referralPendingExpiryDays' => 'pending referral expiry',
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

        // ReferralService reads this with a hardcoded empty scope ([]) —
        // always writes Global, regardless of the picker, so it's never a
        // dead "override" that the consumer would silently never see.
        Setting::set('referral.pending_expiry_days', $this->referralPendingExpiryDays, 'global', null);

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

    /** Real consumer: App\Services\Reporting\DailyDigestDispatchService::sendIfDue(), via routes/console.php's digest:send-daily entry. Always global scope -- see the digestSendTimeLocal property docblock. */
    public function saveDigest(): void
    {
        $this->validate([
            'digestSendTimeLocal' => ['required', 'date_format:H:i'],
        ], [], [
            'digestSendTimeLocal' => 'send time',
        ]);

        Setting::set('digest.send_time_local', $this->digestSendTimeLocal, 'global', null);
        Setting::set('digest.whatsapp_enabled', $this->digestWhatsappEnabled ? '1' : '0', 'global', null);

        $this->flashMessage = 'Daily Digest settings saved.';
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

    /**
     * Real consumers: KycWithdrawalPolicyService::explain() (withdrawal
     * gate), KycVerificationVideoService::submit() (video size cap),
     * KycDocumentService (document size cap), ReviewProviderKycAction
     * (video-required-for-approval gate). All four had real, wired
     * consumers with zero admin UI before this (Phase 11 audit finding).
     */
    public function saveKyc(): void
    {
        if (! auth()->user()->hasPermission('settings.manage')) {
            $this->addError('permission', 'You do not have permission to manage KYC settings.');
            return;
        }

        $this->validate([
            'kycWithdrawalRestrictionEnabled' => ['required', 'in:0,1'],
            'kycRequireVerificationVideo' => ['required', 'in:0,1'],
            'kycMaxDocumentSizeMb' => ['required', 'numeric', 'min:1', 'max:100'],
            'kycMaxVideoSizeMb' => ['required', 'numeric', 'min:1', 'max:500'],
        ], [], [
            'kycMaxDocumentSizeMb' => 'max document size', 'kycMaxVideoSizeMb' => 'max video size',
        ]);

        [$scopeType, $scopeId] = $this->scopeTypeAndId();

        foreach ([
            'kyc.withdrawal_restriction_enabled' => $this->kycWithdrawalRestrictionEnabled,
            'kyc.require_verification_video' => $this->kycRequireVerificationVideo,
            'kyc.max_document_size_mb' => $this->kycMaxDocumentSizeMb,
            'kyc.max_video_size_mb' => $this->kycMaxVideoSizeMb,
        ] as $key => $value) {
            Setting::set($key, $value, $scopeType, $scopeId);
        }

        $this->flashMessage = 'KYC settings saved'.($scopeType === 'global' ? '.' : " for this {$scopeType}.");
    }

    /**
     * Real consumer: App\Services\CompensationService, wired into
     * CompleteBookingAction since mission Phase 5 (tips/waiting/rain/
     * overtime/peak/night). Every rate defaulted to 0 / every window to -1
     * (disabled) with zero admin UI to ever change them (Phase 11 audit
     * finding) — this only adds the ability to configure them, values
     * below stay at the same safe defaults until an admin changes them.
     */
    public function saveCompensation(): void
    {
        if (! auth()->user()->hasPermission('settings.manage')) {
            $this->addError('permission', 'You do not have permission to manage compensation settings.');
            return;
        }

        $this->validate([
            'compensationOvertimeRatePerMinute' => ['required', 'numeric', 'min:0'],
            'compensationOvertimeThresholdMinutes' => ['required', 'integer', 'min:0'],
            'compensationNightWindowStartHour' => ['required', 'integer', 'min:-1', 'max:23'],
            'compensationNightWindowEndHour' => ['required', 'integer', 'min:-1', 'max:23'],
            'compensationNightFlatAmount' => ['required', 'numeric', 'min:0'],
            'compensationPeakWindowStartHour' => ['required', 'integer', 'min:-1', 'max:23'],
            'compensationPeakWindowEndHour' => ['required', 'integer', 'min:-1', 'max:23'],
            'compensationPeakFlatAmount' => ['required', 'numeric', 'min:0'],
            'compensationRainFlatAmount' => ['required', 'numeric', 'min:0'],
            'compensationWaitingRatePerMinute' => ['required', 'numeric', 'min:0'],
        ], [], [
            'compensationOvertimeRatePerMinute' => 'overtime rate', 'compensationOvertimeThresholdMinutes' => 'overtime threshold',
            'compensationNightWindowStartHour' => 'night window start', 'compensationNightWindowEndHour' => 'night window end',
            'compensationNightFlatAmount' => 'night flat amount',
            'compensationPeakWindowStartHour' => 'peak window start', 'compensationPeakWindowEndHour' => 'peak window end',
            'compensationPeakFlatAmount' => 'peak flat amount',
            'compensationRainFlatAmount' => 'rain flat amount', 'compensationWaitingRatePerMinute' => 'waiting rate',
        ]);

        [$scopeType, $scopeId] = $this->scopeTypeAndId();

        foreach ([
            'compensation.overtime_rate_per_minute' => $this->compensationOvertimeRatePerMinute,
            'compensation.overtime_threshold_minutes' => $this->compensationOvertimeThresholdMinutes,
            'compensation.night_window_start_hour' => $this->compensationNightWindowStartHour,
            'compensation.night_window_end_hour' => $this->compensationNightWindowEndHour,
            'compensation.night_flat_amount' => $this->compensationNightFlatAmount,
            'compensation.peak_window_start_hour' => $this->compensationPeakWindowStartHour,
            'compensation.peak_window_end_hour' => $this->compensationPeakWindowEndHour,
            'compensation.peak_flat_amount' => $this->compensationPeakFlatAmount,
            'compensation.rain_flat_amount' => $this->compensationRainFlatAmount,
            'compensation.waiting_rate_per_minute' => $this->compensationWaitingRatePerMinute,
        ] as $key => $value) {
            Setting::set($key, $value, $scopeType, $scopeId);
        }

        $this->flashMessage = 'Compensation settings saved'.($scopeType === 'global' ? '.' : " for this {$scopeType}.");
    }

    /**
     * Real consumers: OtpService (login/verification OTP), QrChallengeService
     * (device-pairing QR). Both call Setting::get() with NO scope argument
     * at all — genuinely global-only, unlike every scoped tab above — so
     * this always writes Global regardless of the scope picker (Phase 11
     * audit finding: real, wired security parameters with zero admin UI).
     */
    public function saveSecurity(): void
    {
        if (! auth()->user()->hasPermission('settings.manage')) {
            $this->addError('permission', 'You do not have permission to manage security settings.');
            return;
        }

        $this->validate([
            'authOtpLength' => ['required', 'integer', 'min:4', 'max:8'],
            'authOtpExpirySeconds' => ['required', 'integer', 'min:30', 'max:3600'],
            'authOtpResendCooldownSeconds' => ['required', 'integer', 'min:10', 'max:600'],
            'authOtpMaxAttempts' => ['required', 'integer', 'min:1', 'max:20'],
            'authQrChallengeExpirySeconds' => ['required', 'integer', 'min:30', 'max:600'],
        ], [], [
            'authOtpLength' => 'OTP length', 'authOtpExpirySeconds' => 'OTP expiry',
            'authOtpResendCooldownSeconds' => 'OTP resend cooldown', 'authOtpMaxAttempts' => 'OTP max attempts',
            'authQrChallengeExpirySeconds' => 'QR challenge expiry',
        ]);

        foreach ([
            'auth.otp_length' => $this->authOtpLength,
            'auth.otp_expiry_seconds' => $this->authOtpExpirySeconds,
            'auth.otp_resend_cooldown_seconds' => $this->authOtpResendCooldownSeconds,
            'auth.otp_max_attempts' => $this->authOtpMaxAttempts,
            'auth.qr_challenge_expiry_seconds' => $this->authQrChallengeExpirySeconds,
        ] as $key => $value) {
            Setting::set($key, $value, 'global', null);
        }

        $this->flashMessage = 'Security / OTP settings saved (global — these are read without a scope by their consumers).';
    }

    /**
     * Real consumers: StuckBookingService, DispatchHealthService (both
     * mission Phase 10 — Operations expansion). Both call Setting::get()
     * with NO scope argument — global-only, same as Security/OTP above.
     * Phase 11 audit finding: these thresholds had zero admin UI, only
     * reachable via a direct DB/tinker edit.
     */
    public function saveOperations(): void
    {
        if (! auth()->user()->hasPermission('settings.manage')) {
            $this->addError('permission', 'You do not have permission to manage operations settings.');
            return;
        }

        $this->validate([
            'opsStuckThresholdSearchingProvider' => ['required', 'integer', 'min:1'],
            'opsStuckThresholdAssigned' => ['required', 'integer', 'min:1'],
            'opsStuckThresholdProviderEnRoute' => ['required', 'integer', 'min:1'],
            'opsStuckThresholdInProgress' => ['required', 'integer', 'min:1'],
            'opsStuckThresholdOnHold' => ['required', 'integer', 'min:1'],
            'opsDispatchOfferResponseTimeoutMinutes' => ['required', 'integer', 'min:1'],
        ], [], [
            'opsStuckThresholdSearchingProvider' => 'searching-provider threshold', 'opsStuckThresholdAssigned' => 'assigned threshold',
            'opsStuckThresholdProviderEnRoute' => 'en-route threshold', 'opsStuckThresholdInProgress' => 'in-progress threshold',
            'opsStuckThresholdOnHold' => 'on-hold threshold', 'opsDispatchOfferResponseTimeoutMinutes' => 'dispatch offer response timeout',
        ]);

        foreach ([
            'operations.stuck_threshold_minutes.searching_provider' => $this->opsStuckThresholdSearchingProvider,
            'operations.stuck_threshold_minutes.assigned' => $this->opsStuckThresholdAssigned,
            'operations.stuck_threshold_minutes.provider_en_route' => $this->opsStuckThresholdProviderEnRoute,
            'operations.stuck_threshold_minutes.in_progress' => $this->opsStuckThresholdInProgress,
            'operations.stuck_threshold_minutes.on_hold' => $this->opsStuckThresholdOnHold,
            'dispatch.offer_response_timeout_minutes' => $this->opsDispatchOfferResponseTimeoutMinutes,
        ] as $key => $value) {
            Setting::set($key, $value, 'global', null);
        }

        $this->flashMessage = 'Operations settings saved (global — these are read without a scope by their consumers).';
    }

    /**
     * Real consumer: App\Services\Plans\RenewalService, called with a
     * hardcoded empty scope ([]) — global-only. Replaces the stale
     * "subscriptions_membership" placeholder (Phase 11 audit finding).
     */
    public function saveSubscriptions(): void
    {
        if (! auth()->user()->hasPermission('settings.manage')) {
            $this->addError('permission', 'You do not have permission to manage subscription settings.');
            return;
        }

        $this->validate([
            'subscriptionsGracePeriodDays' => ['required', 'integer', 'min:0', 'max:90'],
        ], [], ['subscriptionsGracePeriodDays' => 'grace period']);

        Setting::set('plan.grace_period_days', $this->subscriptionsGracePeriodDays, 'global', null);

        $this->flashMessage = 'Subscription settings saved (global — read without a scope by RenewalService).';
    }

    public function render()
    {
        // Read-only gateway identity/status for the Payment tab -- which
        // provider is bound (App\Providers\AppServiceProvider), whether it
        // has real credentials configured, and a masked (never secret)
        // fragment of its public key, so an admin can confirm the gateway
        // is live-configured without ever seeing key_secret/webhook_secret,
        // which never leave RazorpayPaymentDriver. Gateway credentials themselves
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
