<?php

namespace App\Http\Requests\Customer;

use App\Services\Coupons\CouponSettings;

/**
 * POST /api/coupons/validate. A code, the services, an address and a payment method — never an amount: the
 * full price, discount and payable are all computed server-side (CouponQuoteService).
 */
class ValidateCouponRequest extends CustomerApiRequest
{
    public function rules(): array
    {
        return [
            'coupon_code' => ['required', 'string', 'max:50'],
            'address_id' => ['required', 'integer', 'exists:addresses,id'],
            'payment_method' => ['nullable', 'string', 'in:online,cash,wallet'],
            'surface' => ['nullable', 'string', 'in:'.implode(',', CouponSettings::SURFACES)],
            'services' => ['required', 'array', 'min:1', 'max:'.StoreBookingBundleRequest::MAX_SERVICES],
            'services.*.service_id' => ['required', 'integer', 'exists:services,id'],
            'services.*.quantity' => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }
}
