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
}
