<?php

namespace App\Livewire\Settings;

use App\Models\City;
use App\Models\Country;
use App\Models\Franchise;
use App\Models\Setting;
use App\Models\Zone;
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
// see the comment above each group below. Everything else renders as a
// greyed-out "coming soon" panel via PLACEHOLDER_TABS, each with a one-line
// reason: no infrastructure exists yet for any of them (no Vendor system,
// no Wallet UI, no notification service, no SMS/Websocket, no CMS, no
// scoped RBAC, no mobile apps), and shipping controls that configure
// nothing violates the project's own Definition of Done. Two are
// deliberately still placeholders despite having real schema:
// Refund/Cancellation (cancellation_policies exists, but
// AdminCancelBookingAction doesn't enforce it — needs that logic built
// first, and how it should interact with captured payments/refunds is an
// open decision, not something to guess at here) and Roles/Permissions
// (blocked on the settings doc's Rule 9 rewrite — this app has one flat
// super_admin check today, not the granular RBAC the doc describes).
class Manage extends Component
{
    public const PLACEHOLDER_TABS = [
        'general_system' => ['label' => 'General / System', 'note' => 'Maintenance mode, timezone — no maintenance-mode feature or per-request timezone handling exists yet (the whole app runs in UTC today). Business name/city label are covered by the Branding tab above, not here.'],
        'mobile_apps' => ['label' => 'Mobile Apps', 'note' => 'Country picker, app links, upgrade prompts — no mobile apps exist yet (M6/M7 haven\'t started).'],
        'vendor' => ['label' => 'Vendor / Provider', 'note' => 'Provider self-registration rules, KYC requirements, verified badges — the Providers screen already covers approve/reject; broader policy config isn\'t built yet.'],
        'customer' => ['label' => 'Customer', 'note' => 'Registration rules, profile requirements, per-customer limits — no generalized customer-config surface exists yet.'],
        'payment' => ['label' => 'Payment', 'note' => 'Razorpay mode/keys currently live in .env (config/services.php) — intentionally not duplicated into an editable DB setting for a payment credential. A real multi-gateway abstraction is a project on the scale of RazorpayService itself, not a settings screen.'],
        'wallet' => ['label' => 'Wallet / Ledger', 'note' => 'Wallet limits, payout thresholds — WalletService exists but has no admin-configurable rules yet.'],
        'finance_settlement' => ['label' => 'Finance / Settlement', 'note' => 'Commission rates by scope are now real (see Commission Defaults\' scope picker above) — but disbursement/payout timing has nothing to configure: there is no SettlementService, informally or otherwise (confirmed by audit).'],
        'refund_cancellation' => ['label' => 'Refund / Cancellation', 'note' => 'cancellation_policies table exists (free_cancellation_minutes, fee_type, fee_value) but AdminCancelBookingAction doesn\'t enforce it yet — needs that logic built first (and a decision on how a fee interacts with an already-captured Razorpay payment), not just a settings editor.'],
        'notifications' => ['label' => 'Notifications / Communication', 'note' => 'No SMS gateway, push notification service, or WhatsApp integration is wired up yet — ServiceMatchingJob has a standing TODO for FCM.'],
        'websocket' => ['label' => 'Websocket / Realtime', 'note' => 'No realtime/websocket server exists in this codebase.'],
        'ui_home_screen' => ['label' => 'UI / Home Screen', 'note' => 'Banner position/vendor layout/widget visibility — there\'s no customer-facing home screen yet to configure (M6 not started).'],
        'priority_ranking' => ['label' => 'Priority / Ranking', 'note' => 'Search/listing sort rules — nothing customer-facing exists yet to rank.'],
        'website_cms' => ['label' => 'Website / CMS', 'note' => 'content_pages and faqs tables exist with no admin screen yet.'],
        'mail_sms' => ['label' => 'Mail / SMS', 'note' => 'Mail transport is configured via .env (config/mail.php); no SMS gateway is integrated. No admin-editable mail/SMS settings exist yet.'],
        'dynamic_links' => ['label' => 'Dynamic Links', 'note' => 'iOS/Android deep-link scheme, package names, SHA256 — meaningless without a mobile app to link into.'],
        'in_app_support' => ['label' => 'In-App Support', 'note' => 'Support widget/link config for a mobile app that doesn\'t exist yet.'],
        'subscriptions_membership' => ['label' => 'Subscriptions / Membership', 'note' => 'subscription_plans and provider_subscriptions tables exist with zero consumers anywhere (confirmed by audit).'],
        'loyalty_referral' => ['label' => 'Loyalty / Referral', 'note' => 'loyalty_points and referrals tables exist with zero consumers anywhere (confirmed by audit).'],
        'disbursement' => ['label' => 'Disbursement', 'note' => 'Payout timing/splits — no disbursement logic exists; commission is split and credited to the provider\'s wallet at completion, but franchise/platform shares are never paid out anywhere.'],
        'roles_permissions' => ['label' => 'Roles / Permissions', 'note' => 'Access is currently one flat super_admin check (EnsureSuperAdmin) — no scoped roles exist. Blocked on Rule 9\'s rewrite (what RBAC actually means in this codebase, not Glover\'s).'],
        'app_upgrade' => ['label' => 'App Upgrade', 'note' => 'Independent version gating per app (customer/partner/rider) — none of those apps exist yet.'],
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

    // --- Locale & Currency (display symbol used across admin money fields) ---
    public string $localeCurrencySymbol = '₹';

    // --- Platform / Branding (admin header, <title>, login page) ---
    public string $brandingPlatformName = '1CallFix Admin';
    public string $brandingOperatingCityLabel = 'Nellore';

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

        $this->localeCurrencySymbol = Setting::get('locale.currency_symbol', '₹', $scope);

        $this->brandingPlatformName = Setting::get('branding.platform_name', '1CallFix Admin', $scope);
        $this->brandingOperatingCityLabel = Setting::get('branding.operating_city_label', 'Nellore', $scope);
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

    public function render()
    {
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
        ])->layout('layouts.admin', ['title' => 'Settings']);
    }
}
