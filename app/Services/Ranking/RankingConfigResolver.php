<?php

namespace App\Services\Ranking;

use App\Models\Setting;

/**
 * Turns the admin-configured ranking rule for a given context ("providers"
 * today; the shape is context-keyed, not hard-coded to providers, so a
 * future context — "products", "restaurants" — reuses the exact same
 * Setting keys/parsing with a different $context string, no new resolver)
 * into the plain-array config RankingEngine consumes. Reuses Setting::get()'s
 * existing Global→Country→City→Zone→Module→Franchise cascade — no second
 * scope resolver.
 *
 * Defaults preserve DispatchService's pre-existing behaviour exactly
 * (distance ascending only) when nothing has been configured — turning
 * this engine on is opt-in, not a silent ranking change.
 */
class RankingConfigResolver
{
    public const CRITERIA = ['priority', 'rating', 'distance', 'orders', 'subscription'];

    public function resolve(string $context, array $scope = []): array
    {
        $mode = Setting::get("ranking.{$context}.mode", 'sequential', $scope);
        $sequential = $this->parseSequential(Setting::get("ranking.{$context}.sequential", 'distance:asc', $scope));
        $weights = $this->parseWeights(Setting::get(
            "ranking.{$context}.weights",
            '{"priority":0,"rating":0,"distance":100,"orders":0,"subscription":0}',
            $scope
        ));

        return ['mode' => $mode === 'weighted' ? 'weighted' : 'sequential', 'sequential' => $sequential, 'weights' => $weights];
    }

    /** "priority:desc,rating:desc,distance:asc" -> [['key'=>'priority','direction'=>'desc'], ...] */
    private function parseSequential(string $raw): array
    {
        $parsed = collect(explode(',', $raw))
            ->map(fn ($part) => explode(':', trim($part)))
            ->filter(fn ($pair) => count($pair) === 2 && in_array($pair[0], self::CRITERIA, true))
            ->map(fn ($pair) => ['key' => $pair[0], 'direction' => $pair[1] === 'desc' ? 'desc' : 'asc'])
            ->values()
            ->all();

        return $parsed ?: [['key' => 'distance', 'direction' => 'asc']];
    }

    private function parseWeights(string $raw): array
    {
        $decoded = json_decode($raw, true) ?: [];
        $weights = [];

        foreach (self::CRITERIA as $key) {
            $weights[$key] = max(0, (float) ($decoded[$key] ?? 0));
        }

        return $weights;
    }
}
