<?php

namespace App\Livewire\Concerns;

use App\Models\Provider;
use App\Services\AuthorizationService;
use Illuminate\Support\Collection;

/**
 * The "owner" dropdown on the Stores, Hotels, Properties, Vehicles and Equipment screens. It used to list every
 * provider on the platform, and create() accepted any provider id: a franchise user holding one of those
 * permissions would see other cities' partners and could attach a listing to one of them. Both now go through the
 * same scopeQuery() as the provider list.
 */
trait ScopesOwnerProviders
{
    protected function ownerProvidersQuery(string $permission)
    {
        return app(AuthorizationService::class)->scopeQuery(Provider::query(), auth()->user(), $permission, [
            'zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id',
        ]);
    }

    protected function ownerProviderOptions(string $permission): Collection
    {
        return $this->ownerProvidersQuery($permission)->with('user')->get();
    }

    /** False (with an error on providerId) when the chosen owner is outside the user's own area. */
    protected function ownerProviderAllowed(string $permission): bool
    {
        if ($this->ownerProvidersQuery($permission)->whereKey($this->providerId)->exists()) {
            return true;
        }

        $this->addError('providerId', 'Choose a provider from your own area.');

        return false;
    }
}
