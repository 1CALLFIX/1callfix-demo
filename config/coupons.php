<?php

/**
 * Coupon engine registry (1CF-COUPON-HARDENING-003 §G).
 *
 * A module is redeemable only when it appears here AND ModuleActivationService says it is enabled for the
 * franchise/zone. Having a PromotionContext for a module is not enough. Add a module here only once its
 * order flow actually calls CouponService (builder, reserve, confirm/release) and has tests.
 */
return [
    'connected_modules' => ['service'],
];
