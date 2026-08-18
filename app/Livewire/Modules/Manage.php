<?php

namespace App\Livewire\Modules;

use App\Models\City;
use App\Models\Country;
use App\Models\Franchise;
use App\Models\Module;
use App\Models\Zone;
use App\Services\ActivityLogger;
use App\Services\ModuleActivationService;
use Livewire\Component;

/**
 * Phase 22.1 (Module Activation Foundation). The admin screen that finally
 * makes the Country -> City -> Zone -> Franchise activation cascade
 * PHASE_22_PLATFORM_CAPABILITY_RECOVERY_AUDIT.md §3/§6/§11 found completely
 * missing something an operator can actually see and control.
 *
 * Deliberately no Branch level — mission brief principle #7. Deliberately
 * never lets an unimplemented module (Module.is_implemented=false — every
 * module except `service` today) be turned ON here: the toggle itself is
 * disabled with an explanatory label rather than silently accepting a
 * click that ModuleActivationService::isActive() would ignore anyway —
 * the mission brief's "unimplemented module must never appear as usable"
 * applies to this screen's own UI, not just the resolver.
 */
class Manage extends Component
{
    public string $scopeLevel = 'franchise';
    public ?int $scopeId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('modules.manage'), 403, 'You do not have permission to manage module activation.');
    }

    public function updatingScopeLevel(): void
    {
        $this->scopeId = null;
    }

    /** id => display label, for the level currently selected. */
    public function getEntitiesProperty(): array
    {
        return match ($this->scopeLevel) {
            'country' => Country::orderBy('name')->pluck('name', 'id')->all(),
            'city' => City::with('country')->orderBy('name')->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->name} ({$c->country?->name})"])->all(),
            'zone' => Zone::with('franchise')->orderBy('name')->get()->mapWithKeys(fn ($z) => [$z->id => "{$z->name} — {$z->franchise?->name}"])->all(),
            'franchise' => Franchise::orderBy('name')->get()->mapWithKeys(fn ($f) => [$f->id => $f->display_name])->all(),
            default => [],
        };
    }

    /**
     * The full {level}_id scope array ModuleActivationService expects,
     * derived from the one selected entity's own ancestor chain — a
     * Zone-level selection resolves its Franchise/City/Country too, so
     * "effective state" reflects the whole cascade, not just the exact
     * row (if any) at the selected level.
     */
    private function resolvedScope(): array
    {
        if (! $this->scopeId) {
            return [];
        }

        return match ($this->scopeLevel) {
            'country' => ['country_id' => $this->scopeId],
            'city' => $this->cityScope(),
            'zone' => $this->zoneScope(),
            'franchise' => $this->franchiseScope(),
            default => [],
        };
    }

    private function cityScope(): array
    {
        $city = City::find($this->scopeId);
        if (! $city) {
            return [];
        }

        return ['country_id' => $city->country_id, 'city_id' => $city->id];
    }

    private function zoneScope(): array
    {
        $zone = Zone::with('franchise')->find($this->scopeId);
        if (! $zone) {
            return [];
        }

        return [
            'country_id' => $zone->franchise?->country_id,
            'city_id' => $zone->franchise?->city_id,
            'franchise_id' => $zone->franchise_id,
            'zone_id' => $zone->id,
        ];
    }

    private function franchiseScope(): array
    {
        $franchise = Franchise::find($this->scopeId);
        if (! $franchise) {
            return [];
        }

        return [
            'country_id' => $franchise->country_id,
            'city_id' => $franchise->city_id,
            'franchise_id' => $franchise->id,
        ];
    }

    /** One row per registered module — code, label, implemented flag, effective state, and where that state comes from. */
    public function getModuleRowsProperty(): array
    {
        if (! $this->scopeId) {
            return [];
        }

        $svc = app(ModuleActivationService::class);
        $scope = $this->resolvedScope();

        return Module::orderBy('sort_order')->get()->map(function (Module $module) use ($svc, $scope) {
            $resolvedFrom = $svc->resolvedFrom($module->code, $scope);

            return [
                'module' => $module,
                'is_active' => $svc->isActive($module->code, $scope),
                'set_here' => $resolvedFrom && $resolvedFrom['level'] === $this->scopeLevel,
                'inherited_from' => $resolvedFrom && $resolvedFrom['level'] !== $this->scopeLevel ? $resolvedFrom['level'] : null,
            ];
        })->all();
    }

    public function toggle(string $moduleCode): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('modules.manage'), 403);

        if (! $this->scopeId) {
            return;
        }

        $module = Module::where('code', $moduleCode)->firstOrFail();

        if (! $module->is_implemented) {
            // Deliberately a silent no-op, not a validation error — this
            // path is only reachable if the disabled-toggle UI is bypassed
            // (e.g. a tampered Livewire request), and ModuleActivationService
            // would ignore the row anyway (see its own docblock). Nothing
            // to tell the user that isn't already visible on the screen.
            return;
        }

        $current = app(ModuleActivationService::class)->isActive($moduleCode, $this->resolvedScope());
        $newValue = ! $current;

        $activation = app(ModuleActivationService::class)->setActive(
            $moduleCode,
            $this->scopeLevel,
            $this->scopeId,
            $newValue,
            auth()->id()
        );

        // Module activation can turn an entire vertical on/off for a whole
        // geography -- one of the most consequential mutations in the
        // admin panel, yet `module_activations` only ever retains the
        // MOST RECENT actor (created_by_user_id is overwritten on every
        // toggle by ModuleActivationService::setActive()'s own
        // updateOrCreate, despite its name). ActivityLog already exists
        // and is this codebase's own established pattern for auditing
        // exactly this kind of admin mutation (see Operations\Health's
        // retryJob/discardJob/reprocessWebhook) -- wired in here so a full
        // append-only history of every activation change (who, when,
        // module, scope, before/after) survives the next toggle instead of
        // being silently overwritten.
        ActivityLogger::logModel(auth()->user(), $activation, "Module '{$moduleCode}' ".($newValue ? 'activated' : 'deactivated')." for {$this->scopeLevel} #{$this->scopeId}", [
            'module_code' => $moduleCode,
            'scope_type' => $this->scopeLevel,
            'scope_id' => $this->scopeId,
            'was_active' => $current,
            'is_active' => $newValue,
        ]);
    }

    public function render()
    {
        return view('livewire.modules.manage', [
            'entities' => $this->entities,
            'moduleRows' => $this->moduleRows,
        ])->layout('layouts.admin', ['title' => 'Modules']);
    }
}
