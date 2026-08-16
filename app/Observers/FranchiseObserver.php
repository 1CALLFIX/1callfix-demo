<?php

namespace App\Observers;

use App\Models\Franchise;
use App\Services\CodeGeneratorService;
use App\Services\ModuleActivationService;
use App\Support\Modules;

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
     *
     * franchise_modules is superseded but deliberately left as-is (frozen,
     * still written here for continuity/rollback safety — see Phase 22.1's
     * migration docblocks); `module_activations` is the new, authoritative
     * system going forward. Every new franchise gets an explicit `service`
     * activation row here so ModuleActivationService::isActive('service',
     * ...) never has to fall through to its legacy default for anything
     * created from this point on — that default exists only for franchises
     * that predate this phase (backfilled once, in migration
     * 2026_08_16_902000) or bypass Eloquent events entirely.
     */
    public function created(Franchise $franchise): void
    {
        \App\Models\FranchiseModule::create([
            'franchise_id' => $franchise->id,
            'service' => true,
        ]);

        app(ModuleActivationService::class)->setActive(Modules::SERVICE, 'franchise', $franchise->id, true);
    }
}
