<?php

namespace App\Support;

use App\Services\ModuleActivationService;

/**
 * One Rental (owner 2026-11-14: "why can't we add all rental into 1 module, 1 card, 1 settings"). Admin → Modules has
 * one Rental switch per location; Rental Settings says which kinds of rental are offered there: Function halls,
 * Properties, Vehicles, Equipment ("if we need we will keep vehicles and equipment active, else only function halls
 * and properties"). A kind is live where Rental is on AND that kind is on.
 */
final class RentalKinds
{
    public const HALLS = 'halls';
    public const PROPERTIES = 'properties';
    public const VEHICLES = 'vehicles';
    public const EQUIPMENT = 'equipment';

    /** kind => [label, default on] */
    public const KINDS = [
        self::HALLS => ['Function halls', true],
        self::PROPERTIES => ['Properties (houses, villas, rooms)', true],
        self::VEHICLES => ['Vehicles', false],
        self::EQUIPMENT => ['Equipment', false],
    ];

    public static function key(string $kind): string
    {
        return "rental.kind.{$kind}";
    }

    /** The kind is switched on in this scope (Rental Settings), whatever the Rental module switch says. */
    public static function on(string $kind, array $scope = []): bool
    {
        return array_key_exists($kind, self::KINDS) && RentalSettings::on(self::key($kind), $scope);
    }

    /** Customers can use this kind here: Rental on in Modules and the kind on in Rental Settings. */
    public static function active(string $kind, array $scope = []): bool
    {
        return self::on($kind, $scope) && app(ModuleActivationService::class)->isActive(Modules::RENTAL, $scope);
    }

    /** @return array<string, string> kind => label, only the kinds switched on in this scope */
    public static function enabled(array $scope = []): array
    {
        $out = [];
        foreach (self::KINDS as $kind => [$label]) {
            if (self::on($kind, $scope)) {
                $out[$kind] = $label;
            }
        }

        return $out;
    }
}
