<?php

namespace App\Http\Requests\Customer;

/** PUT /api/addresses/{id} — same fields as StoreAddressRequest, all optional (partial update). */
class UpdateAddressRequest extends CustomerApiRequest
{
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:50'],
            'zone_id' => ['sometimes', 'integer', 'exists:zones,id'],
            'lat' => ['sometimes', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'numeric', 'between:-180,180'],
            'address_line' => ['sometimes', 'string', 'max:255'],
            'landmark' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pincode' => ['sometimes', 'nullable', 'string', 'max:10'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
