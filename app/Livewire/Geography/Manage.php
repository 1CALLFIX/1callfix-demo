<?php

namespace App\Livewire\Geography;

use App\Models\City;
use App\Models\Country;
use Livewire\Component;

/**
 * Countries -> Cities never had their own admin screen — both tables
 * existed only as dropdown data inside Franchises/Bookings/Settings/Roles.
 * Same pinned-add-form-plus-list, nested-inline-child pattern as
 * Categories -> Subcategories, kept intentionally lean: no images, no
 * reordering (alphabetical, not merchandised), no Excel import — none of
 * that applies to geography reference data.
 */
class Manage extends Component
{
    /**
     * No view-level check existed at all — only write actions checked
     * geography.manage (Phase 11 audit finding, same bug class addendum
     * #1 already fixed on 15 other screens). No separate geography.view
     * permission was ever seeded, so this reuses geography.manage.
     */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('geography.manage'), 403, 'You do not have permission to view geography.');
    }

    // --- New country form ---
    public string $name = '';
    public string $code = '';
    public string $currencyCode = '';
    public string $defaultTimezone = 'Asia/Kolkata';

    // --- New city form, scoped to the expanded country ---
    public ?int $expandedCountryId = null;
    public string $cityName = '';

    public string $flashMessage = '';
    public string $flashType = 'success';

    private function canManage(): bool
    {
        return auth()->user()->hasPermission('geography.manage');
    }

    public function saveCountry(): void
    {
        if (! $this->canManage()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage geography.';
            return;
        }

        $this->code = strtoupper(trim($this->code));
        $this->currencyCode = strtoupper(trim($this->currencyCode));

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'size:2', 'unique:countries,code'],
            'currencyCode' => ['required', 'string', 'size:3'],
            'defaultTimezone' => ['required', 'string', 'max:60'],
        ]);

        Country::create([
            'name' => $this->name,
            'code' => $this->code,
            'currency_code' => $this->currencyCode,
            'default_timezone' => $this->defaultTimezone,
            'is_active' => true,
        ]);

        $this->reset(['name', 'code', 'currencyCode']);
        $this->defaultTimezone = 'Asia/Kolkata';
        $this->flashType = 'success';
        $this->flashMessage = 'Country added.';
    }

    public function toggleCountryActive(int $countryId): void
    {
        if (! $this->canManage()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage geography.';
            return;
        }

        $country = Country::findOrFail($countryId);
        $country->is_active = ! $country->is_active;
        $country->save();
        $this->flashType = 'success';
        $this->flashMessage = 'Country ' . ($country->is_active ? 'activated' : 'deactivated') . '.';
    }

    public function deleteCountry(int $countryId): void
    {
        if (! $this->canManage()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage geography.';
            return;
        }

        $country = Country::withCount(['cities', 'franchises'])->findOrFail($countryId);

        if ($country->cities_count > 0 || $country->franchises_count > 0) {
            $this->flashType = 'error';
            $this->flashMessage = 'Cannot delete a country that still has cities or franchises. Remove those first.';
            return;
        }

        $country->delete();
        $this->flashType = 'success';
        $this->flashMessage = 'Country deleted.';
    }

    public function expand(int $countryId): void
    {
        $this->expandedCountryId = $this->expandedCountryId === $countryId ? null : $countryId;
        $this->reset('cityName');
    }

    public function saveCity(): void
    {
        if (! $this->canManage()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage geography.';
            return;
        }

        $this->validate([
            'cityName' => ['required', 'string', 'max:120'],
        ]);

        $exists = City::where('country_id', $this->expandedCountryId)->where('name', $this->cityName)->exists();
        if ($exists) {
            $this->flashType = 'error';
            $this->flashMessage = 'This city already exists in that country.';
            return;
        }

        City::create([
            'country_id' => $this->expandedCountryId,
            'name' => $this->cityName,
            'is_active' => true,
        ]);

        $this->reset('cityName');
        $this->flashType = 'success';
        $this->flashMessage = 'City added.';
    }

    public function toggleCityActive(int $cityId): void
    {
        if (! $this->canManage()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage geography.';
            return;
        }

        $city = City::findOrFail($cityId);
        $city->is_active = ! $city->is_active;
        $city->save();
        $this->flashType = 'success';
        $this->flashMessage = 'City ' . ($city->is_active ? 'activated' : 'deactivated') . '.';
    }

    public function deleteCity(int $cityId): void
    {
        if (! $this->canManage()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage geography.';
            return;
        }

        $city = City::withCount('franchises')->findOrFail($cityId);

        if ($city->franchises_count > 0) {
            $this->flashType = 'error';
            $this->flashMessage = 'Cannot delete a city that still has franchises. Remove those first.';
            return;
        }

        $city->delete();
        $this->flashType = 'success';
        $this->flashMessage = 'City deleted.';
    }

    public function render()
    {
        $countries = Country::withCount(['cities', 'franchises'])->orderBy('name')->get();
        $citiesByCountry = City::withCount('franchises')->orderBy('name')->get()->groupBy('country_id');

        return view('livewire.geography.manage', compact('countries', 'citiesByCountry'))
            ->layout('layouts.admin', ['title' => 'Geography']);
    }
}
