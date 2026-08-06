<?php

namespace App\Observers;

use App\Models\Franchise;
use App\Services\CodeGeneratorService;

class FranchiseObserver
{
    public function __construct(private CodeGeneratorService $codeGenerator)
    {
    }

    /**
     * Auto-generate franchise.code from franchise.name if not explicitly set.
     * e.g. name="Nellore" -> code="NLR". Never overwrites a manually-set code.
     */
    public function creating(Franchise $franchise): void
    {
        if (empty($franchise->code)) {
            $franchise->code = $this->codeGenerator->generate(
                $franchise->name,
                Franchise::class
            );
        }
    }

    /**
     * After a franchise is created, give it a default modules row —
     * services on, everything else off — so every franchise from here on
     * has a consistent toggle set, no manual step needed.
     */
    public function created(Franchise $franchise): void
    {
        \App\Models\FranchiseModule::create([
            'franchise_id' => $franchise->id,
            'service' => true,
        ]);
    }
}
