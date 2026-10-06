<?php

namespace App\Http\Controllers\API;

use App\Exceptions\CouponEntryBlockedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ValidateCouponRequest;
use App\Models\Address;
use App\Models\Service;
use App\Services\Coupons\CouponEntryGate;
use App\Services\Coupons\CouponQuoteService;
use App\Support\Api\ApiResponse;

/**
 * Coupon preview for web and Flutter. Read-only: it reserves nothing. Every number in the answer is computed on
 * the server; a rejection carries only the customer-safe sentence, never an internal reason.
 */
class CouponController extends Controller
{
    /** POST /api/coupons/validate */
    public function validateCoupon(ValidateCouponRequest $request, CouponQuoteService $quotes)
    {
        $data = $request->validated();
        $customer = $request->user();

        try {
            CouponEntryGate::attempt($customer, $data['surface'] ?? 'wizard', $request->ip());
        } catch (CouponEntryBlockedException $e) {
            return ApiResponse::error($e->getMessage(), $e->status);
        }

        $address = Address::where('id', $data['address_id'])->where('user_id', $customer->id)->first();
        if (! $address || ! $address->franchise_id || ! $address->zone_id) {
            return ApiResponse::error('Address not found.', 404);
        }

        $items = [];
        foreach ($data['services'] as $row) {
            $service = Service::where('id', $row['service_id'])->where('is_active', true)->first();
            if (! $service) {
                return ApiResponse::error('Service not found or is no longer available.', 404);
            }
            $items[] = ['service' => $service, 'quantity' => (int) ($row['quantity'] ?? 1)];
        }

        return ApiResponse::success($quotes->quote(
            $customer,
            $address->franchise_id,
            $address->zone_id,
            $items,
            $data['payment_method'] ?? 'online',
            $data['coupon_code'],
        ));
    }
}
