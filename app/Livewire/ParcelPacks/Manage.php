<?php

namespace App\Livewire\ParcelPacks;

use App\Models\Franchise;
use App\Models\ParcelPack;
use App\Models\ParcelPackPurchase;
use App\Services\ActivityLogger;
use App\Support\SuperAdminGate;
use Livewire\Component;

/**
 * Admin → Parcel Packs (Stage 2, owner decision 2026-10-31): Super Admin creates the prepaid parcel packs businesses
 * buy online ("50 parcels within 5 km for ₹X, valid 30 days"), for every city or one franchise. Prices are HQ's, so
 * Super Admin only (same gate as Parcel Settings), re-checked on every write and audit-logged. Editing a pack never
 * changes packs already bought (each purchase keeps its own snapshot); switching it off only stops new sales.
 */
class Manage extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $franchiseId = '';

    public string $parcelCount = '';

    public string $maxKm = '';

    public string $price = '';

    public string $validityDays = '30';

    public bool $isActive = true;

    public string $flash = '';

    public function mount(): void
    {
        SuperAdminGate::authorize(auth()->user());
    }

    public function edit(int $id): void
    {
        SuperAdminGate::authorize(auth()->user());
        $pack = ParcelPack::findOrFail($id);
        $this->editingId = $pack->id;
        $this->name = $pack->name;
        $this->franchiseId = (string) ($pack->franchise_id ?? '');
        $this->parcelCount = (string) $pack->parcel_count;
        $this->maxKm = rtrim(rtrim((string) $pack->max_km, '0'), '.');
        $this->price = rtrim(rtrim((string) $pack->price, '0'), '.');
        $this->validityDays = (string) $pack->validity_days;
        $this->isActive = $pack->is_active;
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'name', 'franchiseId', 'parcelCount', 'maxKm', 'price', 'isActive');
        $this->validityDays = '30';
    }

    public function save(): void
    {
        SuperAdminGate::authorize(auth()->user());
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'franchiseId' => ['nullable', 'integer', 'exists:franchises,id'],
            'parcelCount' => ['required', 'integer', 'min:1', 'max:5000'],
            'maxKm' => ['required', 'numeric', 'min:0.5', 'max:100'],
            'price' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'validityDays' => ['required', 'integer', 'min:1', 'max:730'],
            'isActive' => ['boolean'],
        ]);

        $values = [
            'name' => trim($this->name), 'franchise_id' => $this->franchiseId !== '' ? (int) $this->franchiseId : null,
            'parcel_count' => (int) $this->parcelCount, 'max_km' => (float) $this->maxKm, 'price' => (float) $this->price,
            'validity_days' => (int) $this->validityDays, 'is_active' => $this->isActive,
        ];

        if ($this->editingId) {
            $pack = ParcelPack::findOrFail($this->editingId);
            $before = $pack->only(array_keys($values));
            $pack->update($values);
            ActivityLogger::logModel(auth()->user(), $pack, "Parcel pack updated (#{$pack->id})", ['before' => $before, 'after' => $values]);
            $this->flash = 'Pack saved. Packs already bought keep what they were sold with.';
        } else {
            $pack = ParcelPack::create($values);
            ActivityLogger::logModel(auth()->user(), $pack, "Parcel pack created (#{$pack->id})", $values);
            $this->flash = 'Pack created.';
        }

        $this->cancelEdit();
    }

    public function toggle(int $id): void
    {
        SuperAdminGate::authorize(auth()->user());
        $pack = ParcelPack::findOrFail($id);
        $pack->update(['is_active' => ! $pack->is_active]);
        ActivityLogger::logModel(auth()->user(), $pack, 'Parcel pack '.($pack->is_active ? 'put on sale' : 'taken off sale')." (#{$pack->id})");
    }

    public function render()
    {
        return view('livewire.parcel-packs.manage', [
            'packs' => ParcelPack::with('franchise:id,name')->withCount(['purchases as sold' => fn ($q) => $q->where('status', 'active')])->orderByDesc('is_active')->orderBy('price')->get(),
            'franchises' => Franchise::orderBy('name')->get(['id', 'name']),
            'activePurchases' => ParcelPackPurchase::where('status', 'active')->where('expires_at', '>', now())->count(),
        ])->layout('layouts.admin', ['title' => 'Parcel Packs']);
    }
}
