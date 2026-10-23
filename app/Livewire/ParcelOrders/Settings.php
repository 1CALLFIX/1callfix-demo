<?php

namespace App\Livewire\ParcelOrders;

use App\Models\Franchise;
use App\Models\Setting;
use App\Models\Zone;
use App\Services\Parcel\ParcelSettings;
use App\Services\SettingsAuditor;
use App\Support\SuperAdminGate;
use Livewire\Component;

/**
 * Admin → Parcel Settings: every ParcelSettings value (pricing, limits, operations, rider dispatch) at global,
 * franchise or zone scope, validated and audit-logged through SettingsAuditor — the same shape as the
 * Cancellation Policy screen. The rules (online-only benefits etc.) are not here; only their values are.
 * Includes a price preview from the values in force at the chosen scope.
 */
class Settings extends Component
{
    public string $scopeType = 'global'; // global|franchise|zone
    public ?int $scopeFranchiseId = null;
    public ?int $scopeZoneId = null;

    /** Keyed by ParcelSettings::field($key) — Livewire treats dots in a property path as nesting. */
    public array $inputs = [];

    public string $previewKm = '5';
    public string $previewKg = '2';
    public string $previewSize = 'small';

    public string $flashMessage = '';
    public string $flashType = 'success';

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

    private function scopeContext(): array
    {
        return match ($this->scopeType) {
            'franchise' => array_filter(['franchise_id' => $this->scopeFranchiseId]),
            'zone' => array_filter(['zone_id' => $this->scopeZoneId, 'franchise_id' => $this->scopeFranchiseId]),
            default => [],
        };
    }

    /** What is stored at EXACTLY this scope ('' = unset here, inherits). */
    private function loadInputs(): void
    {
        [$type, $id] = $this->scopeTarget() ?? ['global', null];
        $this->inputs = [];

        foreach (ParcelSettings::REGISTRY as $key => $meta) {
            $row = Setting::where('scope_type', $type)->where('scope_id', $id)->where('key', $key)->value('value');
            $this->inputs[ParcelSettings::field($key)] = $row === null ? '' : (string) $row;
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
        foreach (ParcelSettings::REGISTRY as $key => $meta) {
            $error = ParcelSettings::validate($key, $this->inputs[ParcelSettings::field($key)] ?? null);
            if ($error) {
                $this->addError('inputs.'.ParcelSettings::field($key), $error);
                $errors++;
            }
        }

        if ($errors > 0) {
            $this->flash("Nothing was saved — fix the {$errors} highlighted value(s).", 'error');

            return;
        }

        $changed = 0;
        foreach (ParcelSettings::REGISTRY as $key => $meta) {
            $changed += SettingsAuditor::put(auth()->user(), $key, $this->inputs[ParcelSettings::field($key)] ?? null, $target[0], $target[1]) ? 1 : 0;
        }

        $this->loadInputs();
        $this->flash($changed ? "Saved — {$changed} change(s) audit-logged." : 'No changes to save.', 'success');
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }

    /** Price for the preview inputs from the values in force at this scope — the same arithmetic as ParcelPricing. */
    private function preview(): array
    {
        $scope = $this->scopeContext();
        $missing = ParcelSettings::missingRequired($scope);
        if ($missing !== []) {
            return ['ok' => false, 'message' => 'Parcel cannot be booked here until these are set: '.implode(', ', array_map(fn ($k) => ParcelSettings::REGISTRY[$k]['label'], $missing)).'.'];
        }

        $km = max(0.0, (float) $this->previewKm);
        $kg = max(0.0, (float) $this->previewKg);
        $size = array_key_exists($this->previewSize, ParcelSettings::SIZES) ? $this->previewSize : 'small';

        $total = (float) ParcelSettings::get('parcel.base_fare', $scope)
            + max(0.0, $km - (float) ParcelSettings::get('parcel.base_km', $scope)) * (float) ParcelSettings::get('parcel.per_km_rate', $scope)
            + max(0.0, $kg - (float) ParcelSettings::get('parcel.free_weight_kg', $scope)) * (float) ParcelSettings::get('parcel.per_kg_rate', $scope)
            + (float) ParcelSettings::get("parcel.size_surcharge_{$size}", $scope);

        return ['ok' => true, 'total' => round($total, 2)];
    }

    public function render()
    {
        $data = [
            'groups' => collect(ParcelSettings::REGISTRY)->map(fn ($m, $k) => $m + ['key' => $k, 'field' => ParcelSettings::field($k)])->groupBy('group'),
            'franchises' => Franchise::orderBy('name')->get(['id', 'name']),
            'zones' => $this->scopeFranchiseId ? Zone::where('franchise_id', $this->scopeFranchiseId)->orderBy('name')->get(['id', 'name']) : collect(),
            'preview' => $this->preview(),
            'sizes' => ParcelSettings::SIZES,
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ];

        return view('livewire.parcel-orders.settings', $data)->layout('layouts.admin', ['title' => 'Parcel Settings']);
    }
}
