<?php

namespace App\Services\Seo;

use App\Models\City;
use App\Models\Franchise;
use App\Models\Provider;

/**
 * F3: how many real providers back a city (or one category in it) - the input to "index only real pages".
 * Counts approved, active providers of the city's active franchises; online status is deliberately ignored,
 * because a page does not stop being real when a provider logs off for the night.
 */
class ProviderCoverage
{
    public const SETTING_KEY = 'seo.min_providers_to_index';
    public const DEFAULT_MIN = 1;

    public static function minToIndex(): int
    {
        return max(0, (int) \App\Models\Setting::get(self::SETTING_KEY, self::DEFAULT_MIN));
    }

    /**
     * Total providers in the city, and per category id.
     *
     * @return array{total: int, byCategory: array<int,int>}
     */
    public function forCity(City $city): array
    {
        $franchiseIds = Franchise::query()->where('city_id', $city->id)->where('status', 'active')->pluck('id');

        $byCategory = [];
        $total = 0;

        Provider::query()
            ->whereIn('franchise_id', $franchiseIds)
            ->where('is_active', true)
            ->where('kyc_status', 'approved')
            ->get(['id', 'skills'])
            ->each(function (Provider $provider) use (&$byCategory, &$total) {
                $total++;
                foreach (array_unique(array_map('intval', $provider->skills ?? [])) as $categoryId) {
                    $byCategory[$categoryId] = ($byCategory[$categoryId] ?? 0) + 1;
                }
            });

        return ['total' => $total, 'byCategory' => $byCategory];
    }

    public function cityIsReal(City $city): bool
    {
        return $this->forCity($city)['total'] >= self::minToIndex();
    }

    public function categoryIsReal(City $city, int $categoryId): bool
    {
        return ($this->forCity($city)['byCategory'][$categoryId] ?? 0) >= self::minToIndex();
    }
}
