<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'franchise_id' => $this->franchise_id,
            'zone_id' => $this->zone_id,
            'label' => $this->label,
            'lat' => (float) $this->lat,
            'lng' => (float) $this->lng,
            'address_line' => $this->address_line,
            'landmark' => $this->landmark,
            'city' => $this->city,
            'pincode' => $this->pincode,
            'is_default' => (bool) $this->is_default,
            'created_at' => $this->created_at,
        ];
    }
}
