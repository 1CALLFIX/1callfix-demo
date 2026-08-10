<?php

namespace App\Livewire\Settings;

use App\Models\Setting;
use Livewire\Component;

// One config form, not a list — unlike the catalog {Module}\Manage screens,
// there's nothing to paginate/search here. Grouped into sections (Dispatch,
// Commission Defaults, Platform/Branding), one Save button, same
// validation/@error convention as everywhere else.
//
// Every field here is wired to something real that already reads it
// (ServiceMatchingJob, Franchises\Manage's Add New pre-fill, Zones\Manage's
// Add New pre-fill, the admin header/login page) — see the comments on each
// group below. Payment/Notification/Tax/Security are deliberately NOT here:
// nothing in the app reads settings for those yet, and shipping toggles that
// do nothing would violate the project's own Definition of Done. They're
// shown in the view as greyed-out "coming soon" sections instead, same
// convention as the sidebar's unbuilt items.
class Manage extends Component
{
    // --- Dispatch (ServiceMatchingJob's tuning constants) ---
    public string $dispatchOfferBatchSize = '5';
    public string $dispatchOfferTimeoutSeconds = '25';
    public string $dispatchMaxRounds = '6';
    public string $dispatchDefaultRadiusKm = '8';

    // --- Commission Defaults (Franchises\Manage's Add New pre-fill) ---
    public string $commissionDefaultModel = 'revenue_share';
    public string $commissionDefaultValue = '0';
    public string $commissionDefaultPlatformFeePercent = '0';

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

        $this->brandingPlatformName = Setting::get('branding.platform_name', $this->brandingPlatformName);
        $this->brandingOperatingCityLabel = Setting::get('branding.operating_city_label', $this->brandingOperatingCityLabel);
    }

    public function save(): void
    {
        $this->validate([
            'dispatchOfferBatchSize' => ['required', 'integer', 'min:1', 'max:20'],
            'dispatchOfferTimeoutSeconds' => ['required', 'integer', 'min:5', 'max:300'],
            'dispatchMaxRounds' => ['required', 'integer', 'min:1', 'max:20'],
            'dispatchDefaultRadiusKm' => ['required', 'integer', 'min:1', 'max:100'],
            'commissionDefaultModel' => ['required', 'in:revenue_share,flat_fee,subscription_only'],
            'commissionDefaultValue' => ['required', 'numeric', 'min:0'],
            'commissionDefaultPlatformFeePercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'brandingPlatformName' => ['required', 'string', 'max:100'],
            'brandingOperatingCityLabel' => ['required', 'string', 'max:100'],
        ], [], [
            'dispatchOfferBatchSize' => 'offer batch size',
            'dispatchOfferTimeoutSeconds' => 'offer timeout',
            'dispatchMaxRounds' => 'max dispatch rounds',
            'dispatchDefaultRadiusKm' => 'default dispatch radius',
            'commissionDefaultModel' => 'default commission model',
            'commissionDefaultValue' => 'default commission value',
            'commissionDefaultPlatformFeePercent' => 'default platform fee',
            'brandingPlatformName' => 'platform name',
            'brandingOperatingCityLabel' => 'operating city label',
        ]);

        Setting::set('dispatch.offer_batch_size', $this->dispatchOfferBatchSize);
        Setting::set('dispatch.offer_timeout_seconds', $this->dispatchOfferTimeoutSeconds);
        Setting::set('dispatch.max_rounds', $this->dispatchMaxRounds);
        Setting::set('dispatch.default_radius_km', $this->dispatchDefaultRadiusKm);

        Setting::set('commission.default_model', $this->commissionDefaultModel);
        Setting::set('commission.default_value', $this->commissionDefaultValue);
        Setting::set('commission.default_platform_fee_percent', $this->commissionDefaultPlatformFeePercent);

        Setting::set('branding.platform_name', $this->brandingPlatformName);
        Setting::set('branding.operating_city_label', $this->brandingOperatingCityLabel);

        $this->flashMessage = 'Settings saved.';
    }

    public function render()
    {
        return view('livewire.settings.manage')
            ->layout('layouts.admin', ['title' => 'Settings']);
    }
}
