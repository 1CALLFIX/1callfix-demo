<?php

namespace App\Livewire\Franchises;

use App\Models\Franchise;
use App\Models\FranchiseModule;
use Livewire\Component;

class Form extends Component
{
    public ?int $franchiseId = null;

    public string $name = '';
    public string $code = '';
    public string $city = '';
    public string $state = '';
    public string $country = 'India';
    public string $commissionModel = 'revenue_share';
    public float $commissionValue = 0;
    public float $platformFeePercent = 0;
    public string $status = 'pending_setup';

    // Module toggles — 'service' is always on, not editable, since it's the
    // only vertical actually built. Everything else is future-proofing.
    public bool $modFood = false;
    public bool $modParcel = false;
    public bool $modTaxi = false;
    public bool $modGrocery = false;
    public bool $modPharmacy = false;
    public bool $modCommerce = false;
    public bool $modBookings = false;

    public string $flashMessage = '';

    public function mount(?int $franchiseId = null)
    {
        if ($franchiseId) {
            $franchise = Franchise::with('modules')->findOrFail($franchiseId);
            $this->franchiseId = $franchise->id;
            $this->name = $franchise->name;
            $this->code = $franchise->code;
            $this->city = $franchise->city;
            $this->state = $franchise->state ?? '';
            $this->country = $franchise->country;
            $this->commissionModel = $franchise->commission_model;
            $this->commissionValue = $franchise->commission_value;
            $this->platformFeePercent = $franchise->platform_fee_percent;
            $this->status = $franchise->status;

            if ($franchise->modules) {
                $this->modFood = $franchise->modules->food;
                $this->modParcel = $franchise->modules->parcel;
                $this->modTaxi = $franchise->modules->taxi;
                $this->modGrocery = $franchise->modules->grocery;
                $this->modPharmacy = $franchise->modules->pharmacy;
                $this->modCommerce = $franchise->modules->commerce;
                $this->modBookings = $franchise->modules->bookings;
            }
        }
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'commissionModel' => 'required|in:revenue_share,flat_fee,subscription_only',
            'commissionValue' => 'required|numeric|min:0',
            'platformFeePercent' => 'required|numeric|min:0|max:100',
            'status' => 'required|in:active,inactive,pending_setup',
        ]);

        $data = [
            'name' => $this->name,
            'city' => $this->city,
            'state' => $this->state ?: null,
            'country' => $this->country,
            'commission_model' => $this->commissionModel,
            'commission_value' => $this->commissionValue,
            'platform_fee_percent' => $this->platformFeePercent,
            'status' => $this->status,
        ];

        if ($this->franchiseId) {
            $franchise = Franchise::findOrFail($this->franchiseId);
            $franchise->update($data);
        } else {
            // slug required by schema; derive from name, FranchiseObserver
            // handles the `code` auto-generation.
            $data['slug'] = \Illuminate\Support\Str::slug($this->name) . '-' . \Illuminate\Support\Str::random(4);
            $franchise = Franchise::create($data);
            $this->franchiseId = $franchise->id;
        }

        FranchiseModule::updateOrCreate(
            ['franchise_id' => $franchise->id],
            [
                'service' => true,
                'food' => $this->modFood,
                'parcel' => $this->modParcel,
                'taxi' => $this->modTaxi,
                'grocery' => $this->modGrocery,
                'pharmacy' => $this->modPharmacy,
                'commerce' => $this->modCommerce,
                'bookings' => $this->modBookings,
            ]
        );

        $this->code = $franchise->code;
        $this->flashMessage = 'Saved successfully.';
    }

    public function render()
    {
        return view('livewire.franchises.form')
            ->layout('layouts.admin', ['title' => $this->franchiseId ? 'Edit Franchise' : 'New Franchise']);
    }
}
