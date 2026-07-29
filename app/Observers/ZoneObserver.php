<?php

namespace App\Observers;

use App\Models\Zone;
use App\Services\CodeGeneratorService;

class ZoneObserver
{
    public function __construct(private CodeGeneratorService $codeGenerator)
    {
    }

    /**
     * Auto-generate zone.code from zone.name if not explicitly set.
     * Same pattern as FranchiseObserver — never overwrites a manually-set code.
     */
    public function creating(Zone $zone): void
    {
        if (empty($zone->code)) {
            $zone->code = $this->codeGenerator->generate(
                $zone->name,
                Zone::class
            );
        }
    }
}
