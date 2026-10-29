<?php

/**
 * Coupon engine registry (1CF-COUPON-HARDENING-003 §G).
 *
 * A module is redeemable only when it appears here AND ModuleActivationService says it is enabled for the
 * franchise/zone. Having a PromotionContext for a module is not enough. Add a module here only once its
 * order flow actually calls CouponService (builder, reserve, confirm/release) and has tests.
 */
return [
    // E-commerce (2026-10-29): shop orders reserve on checkout, confirm on completion, release on cancel.
    'connected_modules' => ['service', 'commerce'],
];
