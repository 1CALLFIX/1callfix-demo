<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\UpdateProfileRequest;
use App\Http\Resources\Customer\UserProfileResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;

/**
 * P0 Customer Core API — Customer profile (mission item 5). Allow-list
 * enforced entirely by `UpdateProfileRequest`'s own rule set — this
 * controller never does `$user->fill($request->all())` or any other
 * mass-assignment of raw input, specifically so `role`/`status`/
 * `franchise_id`/`zone_id`/`phone`/`phone_verified_at` can never be
 * touched through this endpoint no matter what a client sends.
 */
class ProfileController extends Controller
{
    /** GET /api/profile */
    public function show(Request $request)
    {
        return ApiResponse::success(new UserProfileResource($request->user()));
    }

    /** PUT /api/profile */
    public function update(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $user->fill($request->validated());
        $user->save();

        return ApiResponse::success(new UserProfileResource($user->fresh()), 'Profile updated.');
    }
}
