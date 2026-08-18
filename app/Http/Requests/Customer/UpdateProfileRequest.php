<?php

namespace App\Http\Requests\Customer;

use Illuminate\Validation\Rule;

/**
 * PUT /api/profile — allow-list only. Deliberately excludes `phone` (the
 * OTP-verified identity column — changing it here would be a verification
 * bypass), `role`/`franchise_id`/`zone_id`/`status`/`uuid` (internal/
 * account-ownership fields), `password` (a separate, unbuilt concern — no
 * self-service password endpoint exists anywhere in this codebase to
 * mirror), and `fcm_token` (already has its own dedicated write path,
 * `POST /api/auth/device`, no reason to duplicate it here).
 */
class UpdateProfileRequest extends CustomerApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'profile_photo' => ['sometimes', 'nullable', 'string', 'max:255'],
            'preferred_language' => ['sometimes', 'string', 'max:10'],
        ];
    }
}
