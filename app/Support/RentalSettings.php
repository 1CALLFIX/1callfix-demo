<?php

namespace App\Support;

/**
 * Admin → Rental Settings: which kinds of rental are offered (RentalKinds), then every Function hall setting
 * (HallSettings). One page, one audit log, same scopes (global → franchise → zone) as Shop / Hotel / Event Settings.
 */
class RentalSettings extends HallSettings
{
    public const REGISTRY = [
        'rental.kind.halls' => [
            'group' => 'Rental kinds', 'label' => 'Function halls', 'type' => 'switch', 'default' => '1',
            'help' => 'Halls for weddings, receptions and parties. Customers see it where Rental is on (Admin → Modules) and this is on.',
        ],
        'rental.kind.properties' => [
            'group' => 'Rental kinds', 'label' => 'Properties (houses, villas, rooms)', 'type' => 'switch', 'default' => '1',
            'help' => 'Houses, villas, farmhouses and rooms by the night.',
        ],
        'rental.kind.vehicles' => [
            'group' => 'Rental kinds', 'label' => 'Vehicles', 'type' => 'switch', 'default' => '0',
            'help' => 'Cars, bikes and other vehicles. Off by default: turn on only where you offer them.',
        ],
        'rental.kind.equipment' => [
            'group' => 'Rental kinds', 'label' => 'Equipment', 'type' => 'switch', 'default' => '0',
            'help' => 'Generators, tents, sound systems and other equipment. Off by default: turn on only where you offer them.',
        ],
    ] + HallSettings::REGISTRY;
}
