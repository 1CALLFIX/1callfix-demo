<?php

namespace App\Livewire\Settings;

use App\Models\Setting;
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
// Every REAL tab's fields are wired to something that actually reads them —
// see the comment above each group below. Everything else (Vendor, Wallet,
// Payment Gateway, SMS, CMS, Roles, Cancellation Policy, Map, Mail, System)
// renders as a greyed-out "coming soon" panel via PLACEHOLDER_TABS: nothing
// in the app reads settings for those yet (Cancellation Policy in particular
// has real schema — cancellation_policies — but zero consumers anywhere;
// building a settings editor for it before AdminCancelBookingAction actually
// enforces a policy would be exactly the anti-pattern this screen exists to
// avoid), and shipping controls that do nothing violates the project's own
// Definition of Done.
class Manage extends Component
{
    public const PLACEHOLDER_TABS = [
        'vendor' => ['label' => 'Vendor / Provider', 'note' => 'Provider self-registration rules, KYC requirements, verified badges — the Providers screen already covers approve/reject; broader policy config isn\'t built yet.'],
        'wallet' => ['label' => 'Wallet', 'note' => 'Wallet limits, payout thresholds — WalletService exists but has no admin-configurable rules yet.'],
        'payment_gateway' => ['label' => 'Payment Gateway', 'note' => 'Razorpay mode/keys currently live in .env (config/services.php) — intentionally not duplicated into an editable DB setting for a payment credential.'],
        'sms_notifications' => ['label' => 'SMS & Notifications', 'note' => 'No SMS gateway or push notification service is integrated yet.'],
        'cms' => ['label' => 'CMS / Pages', 'note' => 'content_pages and faqs tables exist with no admin screen yet.'],
        'roles' => ['label' => 'Roles & Permissions', 'note' => 'Access is currently a single flat super_admin role (EnsureSuperAdmin) — no scoped roles exist yet.'],
        'cancellation_policy' => ['label' => 'Cancellation Policy', 'note' => 'cancellation_policies table exists (free_cancellation_minutes, fee_type, fee_value) but AdminCancelBookingAction doesn\'t enforce it yet — needs that logic built first, not just a settings editor.'],
        'map' => ['label' => 'Map', 'note' => 'Google Maps API key lives in .env (GOOGLE_MAPS_API_KEY) — a credential, not a UI-editable setting.'],
        'mail' => ['label' => 'Mail', 'note' => 'Mail transport is configured via .env (config/mail.php) — no admin-editable mail settings exist yet.'],
        'system' => ['label' => 'System / Environment', 'note' => 'No environment/system config surface exists yet.'],
    ];

    public string $activeTab = 'dispatch';

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
        $this->dispatchOfferBatchSize = (string) Setting::get('dispatch.offer_batch_size', $this->dispatchOfferBatchSize);
        $this->dispatchOfferTimeoutSeconds = (string) Setting::get('dispatch.offer_timeout_seconds', $this->dispatchOfferTimeoutSeconds);
        $this->dispatchMaxRounds = (string) Setting::get('dispatch.max_rounds', $this->dispatchMaxRounds);
        $this->dispatchDefaultRadiusKm = (string) Setting::get('dispatch.default_radius_km', $this->dispatchDefaultRadiusKm);

        $this->commissionDefaultModel = Setting::get('commission.default_model', $this->commissionDefaultModel);
        $this->commissionDefaultValue = (string) Setting::get('commission.default_value', $this->commissionDefaultValue);
        $this->commissionDefaultPlatformFeePercent = (string) Setting::get('commission.default_platform_fee_percent', $this->commissionDefaultPlatformFeePercent);

        $this->bookingOtpLength = (string) Setting::get('booking.otp_length', $this->bookingOtpLength);
        $this->bookingMaxScheduleDaysAhead = (string) Setting::get('booking.max_schedule_days_ahead', $this->bookingMaxScheduleDaysAhead);

        $this->localeCurrencySymbol = Setting::get('locale.currency_symbol', $this->localeCurrencySymbol);

        $this->brandingPlatformName = Setting::get('branding.platform_name', $this->brandingPlatformName);
        $this->brandingOperatingCityLabel = Setting::get('branding.operating_city_label', $this->brandingOperatingCityLabel);
    }

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

        Setting::set('dispatch.offer_batch_size', $this->dispatchOfferBatchSize);
        Setting::set('dispatch.offer_timeout_seconds', $this->dispatchOfferTimeoutSeconds);
        Setting::set('dispatch.max_rounds', $this->dispatchMaxRounds);
        Setting::set('dispatch.default_radius_km', $this->dispatchDefaultRadiusKm);

        $this->flashMessage = 'Dispatch settings saved.';
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

        Setting::set('commission.default_model', $this->commissionDefaultModel);
        Setting::set('commission.default_value', $this->commissionDefaultValue);
        Setting::set('commission.default_platform_fee_percent', $this->commissionDefaultPlatformFeePercent);

        $this->flashMessage = 'Commission defaults saved.';
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

        Setting::set('booking.otp_length', $this->bookingOtpLength);
        Setting::set('booking.max_schedule_days_ahead', $this->bookingMaxScheduleDaysAhead);

        $this->flashMessage = 'Booking settings saved.';
    }

    public function saveLocale(): void
    {
        $this->validate([
            'localeCurrencySymbol' => ['required', 'string', 'max:5'],
        ], [], [
            'localeCurrencySymbol' => 'currency symbol',
        ]);

        Setting::set('locale.currency_symbol', $this->localeCurrencySymbol);

        $this->flashMessage = 'Locale & currency settings saved.';
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

        Setting::set('branding.platform_name', $this->brandingPlatformName);
        Setting::set('branding.operating_city_label', $this->brandingOperatingCityLabel);

        $this->flashMessage = 'Branding settings saved.';
    }

    public function render()
    {
        return view('livewire.settings.manage')
            ->layout('layouts.admin', ['title' => 'Settings']);
    }
}
