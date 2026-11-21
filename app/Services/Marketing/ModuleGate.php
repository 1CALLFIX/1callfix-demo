<?php

namespace App\Services\Marketing;

use App\Models\City;
use App\Models\Franchise;
use App\Models\Zone;
use App\Services\ModuleActivationService;
use App\Services\Visitors\PageClassifier;
use App\Support\ModuleSales;

/**
 * Owner 2026-11-21: "if any module is off then we don't need to create a digital ad for that section". A post tied to a
 * module is skipped when that module is switched off where the post is aimed: anywhere (no city) or in its city
 * (the city itself, or any of its franchises or zones, since modules are often switched on per area).
 */
class ModuleGate
{
    /** Post module key => Admin → Modules code. Rent anything and Second-hand live under Rental. */
    public const CODES = ModuleSales::MODULE_CODES + ['listings' => 'rental'];

    /** @var array<string, ?string> */
    private array $memo = [];

    public function __construct(private ModuleActivationService $modules) {}

    /** Null when the post may go out; otherwise the reason, e.g. "Food is switched off in Nellore". */
    public function offReason(?string $module, ?int $cityId = null): ?string
    {
        if (! $module || ! isset(self::CODES[$module])) {
            return null;
        }

        return $this->memo[$module.'|'.$cityId] ??= $this->check($module, $cityId);
    }

    private function check(string $module, ?int $cityId): ?string
    {
        $code = self::CODES[$module];
        $label = PageClassifier::MODULE_LABELS[$module] ?? ucfirst($module);

        if (! $cityId) {
            return $this->modules->isActiveAnywhere($code) ? null : "{$label} is switched off everywhere";
        }

        $city = City::find($cityId, ['id', 'name', 'country_id']);
        if (! $city) {
            return null;
        }
        $base = ['city_id' => $city->id, 'country_id' => $city->country_id];
        if ($this->modules->isActive($code, $base)) {
            return null;
        }
        foreach (Franchise::where('city_id', $city->id)->pluck('id') as $franchiseId) {
            if ($this->modules->isActive($code, $base + ['franchise_id' => $franchiseId])) {
                return null;
            }
            foreach (Zone::where('franchise_id', $franchiseId)->where('is_active', true)->pluck('id') as $zoneId) {
                if ($this->modules->isActive($code, $base + ['franchise_id' => $franchiseId, 'zone_id' => $zoneId])) {
                    return null;
                }
            }
        }

        return "{$label} is switched off in {$city->name}";
    }
}
