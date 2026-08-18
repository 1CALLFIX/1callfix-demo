<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET/PUT /api/profile response shape. Deliberately excludes every
 * internal/account-ownership column `UpdateProfileRequest` also refuses to
 * accept — `role`/`status`/`franchise_id`/`zone_id`/`uuid` stay server-only
 * knowledge as far as this endpoint is concerned (role in particular is
 * never echoed back — a customer session has no legitimate use for it, and
 * not returning it removes any temptation for a client to branch on it as
 * if it were meaningfully settable here).
 */
class UserProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'profile_photo' => $this->profile_photo,
            'preferred_language' => $this->preferred_language,
            'phone_verified' => $this->phone_verified_at !== null,
            'created_at' => $this->created_at,
        ];
    }
}
