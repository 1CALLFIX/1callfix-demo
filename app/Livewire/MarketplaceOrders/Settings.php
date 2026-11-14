<?php

namespace App\Livewire\MarketplaceOrders;

use App\Models\Franchise;
use App\Models\Setting;
use App\Models\Zone;
use App\Services\SettingsAuditor;
use App\Support\MarketplaceSettings;
use App\Support\SuperAdminGate;
use Livewire\Component;

/**
 * Admin → Shop Settings (E-commerce Phase 1): every MarketplaceSettings value at global, franchise or zone scope,
 * validated and audit-logged through SettingsAuditor — the same shape as Parcel Settings. Keys marked `global`
 * (seller sign-ups) are set once for the whole platform and only shown at the global scope.
 */
class Settings extends Component
{
    public string $scopeType = 'global'; // global|franchise|zone
    public ?int $scopeFranchiseId = null;
    public ?int $scopeZoneId = null;

    /** Keyed by MarketplaceSettings::field($key) — Livewire treats dots in a property path as nesting. */
    public array $inputs = [];

    public string $flashMessage = '';
    public string $flashType = 'success';

    /** The settings class this page edits. Food Settings (FoodOrders\Settings) swaps in FoodSettings. */
    protected function registry(): string
    {
        return MarketplaceSettings::class;
    }

    protected function pageTitle(): string
    {
        return 'Shop Settings';
    }

    public function mount(): void
    {
        SuperAdminGate::authorize(auth()->user());
        $this->loadInputs();
    }

    public function updatedScopeType(): void
    {
        $this->scopeFranchiseId = null;
        $this->scopeZoneId = null;
        $this->loadInputs();
    }

    public function updatedScopeFranchiseId(): void
    {
        $this->scopeZoneId = null;
        $this->loadInputs();
    }

    public function updatedScopeZoneId(): void
    {
        $this->loadInputs();
    }

    /** @return array{0: string, 1: ?int}|null null while the picked scope is incomplete */
    private function scopeTarget(): ?array
    {
        return match ($this->scopeType) {
            'global' => ['global', null],
            'franchise' => $this->scopeFranchiseId ? ['franchise', $this->scopeFranchiseId] : null,
            'zone' => $this->scopeZoneId ? ['zone', $this->scopeZoneId] : null,
            default => null,
        };
    }

    /** The keys editable at the picked scope. */
    private function keysHere(): array
    {
        return array_keys(array_filter(
            $this->registry()::REGISTRY,
            fn ($meta) => $this->scopeType === 'global' || ! ($meta['global'] ?? false)
        ));
    }

    /** What is stored at EXACTLY this scope ('' = unset here, inherits). */
    private function loadInputs(): void
    {
        [$type, $id] = $this->scopeTarget() ?? ['global', null];
        $this->inputs = [];

        foreach ($this->keysHere() as $key) {
            $row = Setting::where('scope_type', $type)->where('scope_id', $id)->where('key', $key)->value('value');
            $meta = $this->registry()::REGISTRY[$key];
            // Owner 2026-11-14: a list (facilities, kinds, rules) shows what customers see today, so Super Admin edits
            // the real list instead of an empty "Not configured" box. Saving it unchanged stores nothing.
            $prefill = $row === null && $type === 'global' && ($meta['type'] ?? '') === 'text' && ($meta['default'] ?? null) !== null;
            $this->inputs[$this->registry()::field($key)] = $prefill ? (string) $meta['default'] : ($row === null ? '' : (string) $row);
        }
    }

    public function save(): void
    {
        SuperAdminGate::authorize(auth()->user());
        $this->resetErrorBag();
        $this->flashMessage = '';

        $target = $this->scopeTarget();
        if ($target === null) {
            $this->flash('Pick the franchise / zone first.', 'error');

            return;
        }

        $errors = 0;
        foreach ($this->keysHere() as $key) {
            $error = $this->registry()::validate($key, $this->inputs[$this->registry()::field($key)] ?? null);
            if ($error) {
                $this->addError('inputs.'.$this->registry()::field($key), $error);
                $errors++;
            }
        }

        if ($errors > 0) {
            $this->flash("Nothing was saved — fix the {$errors} highlighted value(s).", 'error');

            return;
        }

        $changed = 0;
        foreach ($this->keysHere() as $key) {
            $meta = $this->registry()::REGISTRY[$key];
            $input = $this->inputs[$this->registry()::field($key)] ?? null;
            if ($target[0] === 'global' && ($meta['type'] ?? '') === 'text' && ($meta['default'] ?? null) !== null
                && self::sameList((string) $input, (string) $meta['default'])
                && Setting::where('scope_type', 'global')->whereNull('scope_id')->where('key', $key)->doesntExist()) {
                continue; // the built-in list, shown but not changed
            }
            $changed += SettingsAuditor::put(auth()->user(), $key, $this->inputs[$this->registry()::field($key)] ?? null, $target[0], $target[1]) ? 1 : 0;
        }

        $this->loadInputs();
        $this->flash($changed ? "Saved — {$changed} change(s) audit-logged." : 'No changes to save.', 'success');
    }

    private static function sameList(string $a, string $b): bool
    {
        $norm = fn (string $v) => implode("\n", array_map('trim', preg_split('/\R/', trim($v))));

        return $norm($a) === $norm($b);
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }

    public function render()
    {
        $keys = $this->keysHere();

        return view('livewire.marketplace-orders.settings', [
            'groups' => collect($this->registry()::REGISTRY)->only($keys)
                ->map(fn ($m, $k) => $m + ['key' => $k, 'field' => $this->registry()::field($k)])->groupBy('group'),
            'pageTitle' => $this->pageTitle(),
            'franchises' => Franchise::orderBy('name')->get(['id', 'name']),
            'zones' => $this->scopeFranchiseId ? Zone::where('franchise_id', $this->scopeFranchiseId)->orderBy('name')->get(['id', 'name']) : collect(),
        ])->layout('layouts.admin', ['title' => $this->pageTitle()]);
    }
}
