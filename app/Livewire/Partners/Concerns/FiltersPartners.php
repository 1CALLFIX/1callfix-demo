<?php

namespace App\Livewire\Partners\Concerns;

use App\Models\City;
use App\Models\Franchise;
use App\Models\Zone;
use App\Services\AuthorizationService;
use App\Support\PartnerReview;

/**
 * Filters shared by the two partner lists (Workers\Index = People, Providers\Index = Businesses), which the
 * Partners page shows as its two tabs. City, franchise and zone are filters, never pages. Every dropdown is built
 * from the user's own area under the list's permission, and the filters only narrow the list's scopeQuery() —
 * they can never widen it, whatever id is sent.
 *
 * "Needs review" (statusFilter = review) is every partner whose documents are waiting, oldest wait first, with the
 * wait shown in hours. It is the default when the list sits inside the Partners page.
 */
trait FiltersPartners
{
    /** True inside the Partners page (no own heading; Needs review first). */
    public bool $embedded = false;

    public string $typeFilter = '';

    public string $franchiseFilter = '';

    public string $zoneFilter = '';

    public string $cityFilter = '';

    public function updatingTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingFranchiseFilter(): void
    {
        $this->resetPage();
    }

    public function updatingZoneFilter(): void
    {
        $this->resetPage();
    }

    public function updatingCityFilter(): void
    {
        $this->resetPage();
    }

    /** Inside the Partners page the list opens on Needs review, unless the link asked for a status. */
    protected function startOnNeedsReview(): void
    {
        if ($this->embedded && ! request()->query->has('statusFilter')) {
            $this->statusFilter = 'review';
        }
    }

    /** Status tabs: Needs review first inside the Partners page. */
    protected function statusTabs(): array
    {
        $rest = ['approved' => 'Approved', 'rejected' => 'Rejected', 'archived' => 'Archived'];

        return $this->embedded
            ? ['review' => 'Needs review', '' => 'All partners'] + $rest
            : ['' => 'All', 'review' => 'Needs review'] + $rest;
    }

    /** The list's status filter, including Needs review (oldest wait first). */
    protected function applyStatus($query)
    {
        return $query
            ->when($this->statusFilter === 'archived', fn ($q) => $q->onlyTrashed())
            ->when($this->statusFilter === 'review', fn ($q) => $q->whereIn('kyc_status', PartnerReview::STATUSES))
            ->when(! in_array($this->statusFilter, ['', 'archived', 'review'], true), fn ($q) => $q->where('kyc_status', $this->statusFilter))
            ->withMin(['documents as waiting_since' => fn ($q) => $q->where('is_current', true)->where('status', 'pending')], 'created_at');
    }

    protected function applyOrder($query)
    {
        return $this->statusFilter === 'review'
            ? $query->orderByRaw('waiting_since is null')->orderBy('waiting_since')->oldest()
            : $query->latest();
    }

    protected function applyGeography($query)
    {
        return $query
            ->when($this->franchiseFilter !== '', fn ($q) => $q->where('franchise_id', (int) $this->franchiseFilter))
            ->when($this->zoneFilter !== '', fn ($q) => $q->where('zone_id', (int) $this->zoneFilter))
            ->when($this->cityFilter !== '', fn ($q) => $q->whereHas('franchise', fn ($f) => $f->where('city_id', (int) $this->cityFilter)));
    }

    /** Status counts for the tab badges, with Needs review summed from its statuses and its Overdue partners as 'overdue'. */
    protected function statusCounts($scopedQuery): array
    {
        $overdue = PartnerReview::overdue($this->applyGeography(clone $scopedQuery))->count();

        $counts = $this->applyGeography($scopedQuery)
            ->selectRaw('kyc_status, count(*) as total')
            ->groupBy('kyc_status')
            ->pluck('total', 'kyc_status')
            ->all();

        $all = array_sum($counts);
        $counts['review'] = array_sum(array_intersect_key($counts, array_flip(PartnerReview::STATUSES)));
        $counts[''] = $all;
        $counts['overdue'] = $overdue;

        return $counts;
    }

    /** Franchise, zone and city choices inside the user's own area for $permission. */
    protected function geographyOptions(string $permission): array
    {
        $auth = app(AuthorizationService::class);
        $user = auth()->user();

        $zones = $auth->scopeQuery(Zone::query(), $user, $permission, [
            'zone_id' => 'id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id',
        ])->orderBy('name')->get(['id', 'name', 'franchise_id']);

        $franchises = $auth->scopeQuery(Franchise::query(), $user, $permission, [
            'zone_id' => 'zones.id', 'franchise_id' => 'id', 'city_id' => 'city_id', 'country_id' => 'country_id',
        ])->orderBy('name')->get(['id', 'name', 'city_id']);

        $cities = City::whereIn('id', $franchises->pluck('city_id')->filter()->unique())->orderBy('name')->get(['id', 'name']);

        return [
            'franchiseOptions' => $franchises->pluck('name', 'id')->all(),
            'zoneOptions' => $zones->when($this->franchiseFilter !== '', fn ($z) => $z->where('franchise_id', (int) $this->franchiseFilter))->pluck('name', 'id')->all(),
            'cityOptions' => $cities->pluck('name', 'id')->all(),
        ];
    }
}
