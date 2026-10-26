<?php

namespace App\Livewire\Partners;

use App\Models\FieldWorker;
use App\Models\Provider;
use App\Services\AuthorizationService;
use App\Support\PartnerReview;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Admin → Partners (owner decision 2026-10-26): one page for every partner, two tabs. People are riders, drivers
 * and helpers (field workers, Workers\Index); Businesses are service pros, restaurants, shops, hotels and rentals
 * (providers, Providers\Index). Both lists are reused as they are, inside this page, each opening on Needs review.
 * A new module adds one entry to a type dropdown, never a page. City, franchise and zone are filters, and every
 * list, dropdown and count is limited to the user's own area, so each city reviews its own partners.
 */
class Index extends Component
{
    #[Url]
    public string $tab = '';

    public function mount(): void
    {
        $tabs = array_keys($this->tabs());
        abort_if($tabs === [], 403, 'You do not have permission to view partners.');

        if (! in_array($this->tab, $tabs, true)) {
            $this->tab = $tabs[0];
        }
    }

    /** tab => [label, permission] for the tabs this user may open. */
    private function tabs(): array
    {
        $all = [
            'people' => ['People', 'workers.view'],
            'businesses' => ['Businesses', 'providers.view'],
        ];

        return array_filter($all, fn ($t) => auth()->user()->hasPermissionAnywhere($t[1]));
    }

    /** Needs review count per tab, inside the user's own area. */
    private function waiting(): array
    {
        $auth = app(AuthorizationService::class);
        $columns = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
        $models = ['people' => FieldWorker::class, 'businesses' => Provider::class];

        $counts = [];
        foreach ($this->tabs() as $key => [, $permission]) {
            $counts[$key] = $auth->scopeQuery($models[$key]::query(), auth()->user(), $permission, $columns)
                ->whereIn('kyc_status', PartnerReview::STATUSES)->count();
        }

        return $counts;
    }

    public function render()
    {
        return view('livewire.partners.index', [
            'tabs' => $this->tabs(),
            'waiting' => $this->waiting(),
        ])->layout('layouts.admin', ['title' => 'Partners']);
    }
}
