<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Authentication foundation (Customer/Partner/Worker) — see
// AUTHENTICATION_ARCHITECTURE.md/OTP_ARCHITECTURE.md/QR_SCAN_ARCHITECTURE.md.
// otp/request and otp/verify are unauthenticated by necessity (this is how
// a Sanctum token is obtained in the first place — nothing else in this
// API previously issued one). Throttled: OTP requests are the one endpoint
// where an attacker could otherwise force real SMS cost / brute-force a
// short code.
Route::prefix('auth')->group(function () {
    Route::post('/otp/request', [\App\Http\Controllers\API\AuthController::class, 'requestOtp'])->middleware('throttle:5,1');
    Route::post('/otp/verify', [\App\Http\Controllers\API\AuthController::class, 'verifyOtp'])->middleware('throttle:10,1');

    // QR device pairing — create/status/claim are unauthenticated by
    // necessity (the initiating side, e.g. a desktop, has no session yet;
    // that's the entire point of this flow) but authenticated by
    // possession of poll_token instead, which is never rendered into the
    // QR image itself. confirm() is the one QR endpoint requiring
    // auth:sanctum — only an already-logged-in mobile session can vouch
    // for a pairing challenge.
    Route::post('/qr/create', [\App\Http\Controllers\API\QrAuthController::class, 'create'])->middleware('throttle:10,1');
    Route::get('/qr/status', [\App\Http\Controllers\API\QrAuthController::class, 'status'])->middleware('throttle:60,1');
    Route::post('/qr/claim', [\App\Http\Controllers\API\QrAuthController::class, 'claim'])->middleware('throttle:20,1');
    Route::post('/qr/revoke', [\App\Http\Controllers\API\QrAuthController::class, 'revoke'])->middleware('throttle:20,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [\App\Http\Controllers\API\AuthController::class, 'logout']);
        Route::post('/device', [\App\Http\Controllers\API\AuthController::class, 'registerDevice']);
        Route::post('/qr/confirm', [\App\Http\Controllers\API\QrAuthController::class, 'confirm'])->middleware('throttle:20,1');
    });
});

// Public content (mission Phase 12 — CMS/content audit). Unauthenticated
// by design, same reasoning as /auth/otp/* above — Terms/Privacy/FAQs/
// banners are pre-login content on every reference app this one is
// benchmarked against.
Route::get('/pages/{slug}', [\App\Http\Controllers\API\ContentController::class, 'page']);
Route::get('/faqs', [\App\Http\Controllers\API\ContentController::class, 'faqs']);
Route::get('/banners', [\App\Http\Controllers\API\ContentController::class, 'banners']);

// Phase 22.7 -- Property Rental browse/search. Public by the same
// reasoning as the content routes above: a real customer browses
// properties before logging in (the same real-world shape Glover's own
// public property-search evidence confirms), same as every other
// pre-login discovery surface in this codebase.
Route::get('/properties', [\App\Http\Controllers\API\PropertyController::class, 'index']);
Route::get('/properties/{propertyId}', [\App\Http\Controllers\API\PropertyController::class, 'show']);
Route::get('/properties/{propertyId}/availability', [\App\Http\Controllers\API\PropertyController::class, 'availability']);

// RENTAL MODULE IMPLEMENTATION -- Vehicle/Equipment browse/search. Public,
// same reasoning as Property Rental above.
Route::get('/vehicles', [\App\Http\Controllers\API\VehicleController::class, 'index']);
Route::get('/vehicles/{vehicleId}', [\App\Http\Controllers\API\VehicleController::class, 'show']);
Route::get('/vehicles/{vehicleId}/availability', [\App\Http\Controllers\API\VehicleController::class, 'availability']);
Route::get('/equipment', [\App\Http\Controllers\API\EquipmentController::class, 'index']);
Route::get('/equipment/{itemId}', [\App\Http\Controllers\API\EquipmentController::class, 'show']);
Route::get('/equipment/{itemId}/availability', [\App\Http\Controllers\API\EquipmentController::class, 'availability']);

// HOTEL / STAY BOOKING MODULE -- Accommodation browse/search. Public, same
// reasoning as Property Rental above.
Route::get('/hotels', [\App\Http\Controllers\API\HotelController::class, 'index']);
Route::get('/hotels/{accommodationId}', [\App\Http\Controllers\API\HotelController::class, 'show']);
Route::get('/hotels/{accommodationId}/room-types', [\App\Http\Controllers\API\HotelController::class, 'roomTypes']);
Route::get('/hotels/{accommodationId}/room-types/{roomTypeId}/availability', [\App\Http\Controllers\API\HotelController::class, 'availability']);

// Phase 24 -- Marketplace Foundation browse/search. Public, same reasoning
// as Property Rental above -- real 6amMart evidence confirms unauthenticated
// store/product browsing is the real shape of this domain.
Route::get('/stores', [\App\Http\Controllers\API\StoreController::class, 'index']);
Route::get('/stores/{storeId}', [\App\Http\Controllers\API\StoreController::class, 'show']);
Route::get('/stores/{storeId}/products', [\App\Http\Controllers\API\ProductController::class, 'index']);
Route::get('/products/{productId}', [\App\Http\Controllers\API\ProductController::class, 'show']);

// P0 CUSTOMER CORE API -- Service catalog discovery. Public, same reasoning
// as every other browse endpoint above -- see ServiceCatalogController's
// own docblock for the real `service_categories.module` filtering rule.
Route::get('/categories', [\App\Http\Controllers\API\ServiceCatalogController::class, 'categories']);
Route::get('/subcategories', [\App\Http\Controllers\API\ServiceCatalogController::class, 'subcategories']);
Route::get('/services', [\App\Http\Controllers\API\ServiceCatalogController::class, 'services']);

Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureNotInMaintenanceMode::class])->group(function () {
    // P0 CUSTOMER CORE API -- Service booking creation/history/cancellation
    // (mission items 2/3/4). Static-segment routes ('mine') registered
    // before the dynamic '{bookingId}' routes so 'mine' is never captured
    // as an id. See BookingController's own docblock for the real
    // call-center-only-vs-self-service business-model note this resolves.
    Route::post('/bookings', [\App\Http\Controllers\API\BookingController::class, 'store']);
    Route::get('/bookings/mine', [\App\Http\Controllers\API\BookingController::class, 'mine']);
    Route::get('/bookings/{bookingId}', [\App\Http\Controllers\API\BookingController::class, 'show']);
    Route::post('/bookings/{bookingId}/cancel', [\App\Http\Controllers\API\BookingController::class, 'cancel']);

    // Phase E2 (Multi-Service Booking — Creation). Customer submits several
    // services in one request; one BookingBundle wraps one child Booking per
    // service, priced/paid/dispatched through the same engines as
    // POST /bookings above. Existing single-service POST /bookings is
    // unchanged and never routed through here.
    Route::post('/booking-bundles', [\App\Http\Controllers\API\BookingBundleController::class, 'store']);
    Route::get('/booking-bundles/{bundleId}', [\App\Http\Controllers\API\BookingBundleController::class, 'show']);

    // P0 CUSTOMER CORE API -- Customer profile (mission item 5).
    Route::get('/profile', [\App\Http\Controllers\API\ProfileController::class, 'show']);
    Route::put('/profile', [\App\Http\Controllers\API\ProfileController::class, 'update']);

    // P0 CUSTOMER CORE API -- Customer addresses CRUD (mission item 6).
    Route::get('/addresses', [\App\Http\Controllers\API\AddressController::class, 'index']);
    Route::post('/addresses', [\App\Http\Controllers\API\AddressController::class, 'store']);
    Route::get('/addresses/{addressId}', [\App\Http\Controllers\API\AddressController::class, 'show']);
    Route::put('/addresses/{addressId}', [\App\Http\Controllers\API\AddressController::class, 'update']);
    Route::delete('/addresses/{addressId}', [\App\Http\Controllers\API\AddressController::class, 'destroy']);

    // P1 CUSTOMER PARCEL API -- static segments ('quote', 'mine') registered
    // before the dynamic '{parcelOrderId}' route, same reasoning as
    // '/bookings/mine' above.
    Route::post('/parcel-orders/quote', [\App\Http\Controllers\API\ParcelOrderController::class, 'quote']);
    Route::post('/parcel-orders', [\App\Http\Controllers\API\ParcelOrderController::class, 'store']);
    Route::get('/parcel-orders/mine', [\App\Http\Controllers\API\ParcelOrderController::class, 'mine']);
    Route::get('/parcel-orders/{parcelOrderId}', [\App\Http\Controllers\API\ParcelOrderController::class, 'show']);
    Route::post('/parcel-orders/{parcelOrderId}/cancel', [\App\Http\Controllers\API\ParcelOrderController::class, 'cancel']);

    // P1 CUSTOMER TAXI API -- same static-before-dynamic ordering.
    Route::post('/taxi-rides/quote', [\App\Http\Controllers\API\TaxiRideController::class, 'quote']);
    Route::post('/taxi-rides', [\App\Http\Controllers\API\TaxiRideController::class, 'store']);
    Route::get('/taxi-rides/mine', [\App\Http\Controllers\API\TaxiRideController::class, 'mine']);
    Route::get('/taxi-rides/{taxiRideId}', [\App\Http\Controllers\API\TaxiRideController::class, 'show']);
    Route::post('/taxi-rides/{taxiRideId}/cancel', [\App\Http\Controllers\API\TaxiRideController::class, 'cancel']);

    Route::post('/bookings/{booking}/accept', [\App\Http\Controllers\API\DispatchController::class, 'accept']);
    Route::post('/bookings/{booking}/complete', [\App\Http\Controllers\API\DispatchController::class, 'complete']);
    Route::post('/bookings/{booking}/pay/create-order', [\App\Http\Controllers\API\PaymentController::class, 'createOrder']);
    Route::post('/bookings/{booking}/pay/confirm', [\App\Http\Controllers\API\PaymentController::class, 'confirm']);
    Route::post('/bookings/{bookingId}/tip', [\App\Http\Controllers\API\TipController::class, 'store']);
    Route::post('/bookings/{bookingId}/review', [\App\Http\Controllers\API\ReviewController::class, 'store']);
    Route::post('/reviews/{reviewId}/reply', [\App\Http\Controllers\API\ReviewController::class, 'reply']);
    Route::get('/wallet', [\App\Http\Controllers\API\WalletController::class, 'show']);
    Route::post('/wallet/topup', [\App\Http\Controllers\API\WalletController::class, 'topUp']);
    Route::get('/bookings/{bookingId}/chat/{withUserId}', [\App\Http\Controllers\API\ChatController::class, 'index']);
    Route::post('/bookings/{bookingId}/chat', [\App\Http\Controllers\API\ChatController::class, 'store']);
    Route::get('/chat/attachments/{messageId}', [\App\Http\Controllers\API\ChatController::class, 'attachment']);
    Route::get('/payments/{paymentId}/document', [\App\Http\Controllers\API\DocumentController::class, 'paymentDocument']);
    Route::get('/notifications', [\App\Http\Controllers\API\InAppNotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [\App\Http\Controllers\API\InAppNotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/read', [\App\Http\Controllers\API\InAppNotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [\App\Http\Controllers\API\InAppNotificationController::class, 'markAllRead']);
    Route::get('/payment-accounts', [\App\Http\Controllers\API\PaymentAccountController::class, 'index']);
    Route::post('/payment-accounts', [\App\Http\Controllers\API\PaymentAccountController::class, 'store']);
    Route::put('/payment-accounts/{id}', [\App\Http\Controllers\API\PaymentAccountController::class, 'update']);
    Route::post('/payment-accounts/{id}/set-default', [\App\Http\Controllers\API\PaymentAccountController::class, 'setDefault']);
    Route::delete('/payment-accounts/{id}', [\App\Http\Controllers\API\PaymentAccountController::class, 'destroy']);
    Route::get('/providers/nearby', [\App\Http\Controllers\API\ProviderDiscoveryController::class, 'nearby']);

    // Phase 22.7 -- Property Rental (authenticated actions only -- browse
    // is public, see below).
    Route::post('/properties/{propertyId}/reservations', [\App\Http\Controllers\API\PropertyReservationController::class, 'store']);
    Route::get('/reservations/mine', [\App\Http\Controllers\API\PropertyReservationController::class, 'mine']);
    Route::get('/reservations/{reservationId}', [\App\Http\Controllers\API\PropertyReservationController::class, 'show']);

    // RENTAL MODULE IMPLEMENTATION -- Vehicle/Equipment (authenticated
    // actions only -- browse is public, see above). Own /rental-reservations
    // prefix rather than the bare /reservations Property Rental uses --
    // deliberately not sharing that URL family since the underlying models
    // (and therefore ids) are different.
    Route::post('/rental-reservations', [\App\Http\Controllers\API\RentalReservationController::class, 'store']);
    Route::get('/rental-reservations/mine', [\App\Http\Controllers\API\RentalReservationController::class, 'mine']);
    Route::get('/rental-reservations/{reservationId}', [\App\Http\Controllers\API\RentalReservationController::class, 'show']);

    // HOTEL / STAY BOOKING MODULE (authenticated actions only -- browse is
    // public, see above). Own /hotel-reservations prefix, same reasoning
    // /rental-reservations gives for not sharing Property Rental's bare
    // /reservations URL family.
    Route::post('/hotel-reservations', [\App\Http\Controllers\API\HotelReservationController::class, 'store']);
    Route::get('/hotel-reservations/mine', [\App\Http\Controllers\API\HotelReservationController::class, 'mine']);
    Route::get('/hotel-reservations/{reservationId}', [\App\Http\Controllers\API\HotelReservationController::class, 'show']);

    // Phase 24 -- Marketplace Foundation (authenticated actions only -- browse is public, see above).
    Route::get('/cart', [\App\Http\Controllers\API\CartController::class, 'index']);
    Route::post('/cart', [\App\Http\Controllers\API\CartController::class, 'store']);
    Route::patch('/cart/{cartItemId}', [\App\Http\Controllers\API\CartController::class, 'update']);
    Route::delete('/cart/{cartItemId}', [\App\Http\Controllers\API\CartController::class, 'destroy']);
    Route::post('/marketplace-orders', [\App\Http\Controllers\API\MarketplaceOrderController::class, 'store']);
    Route::get('/marketplace-orders/mine', [\App\Http\Controllers\API\MarketplaceOrderController::class, 'mine']);
    Route::get('/marketplace-orders/{orderId}', [\App\Http\Controllers\API\MarketplaceOrderController::class, 'show']);
    Route::get('/loyalty', [\App\Http\Controllers\API\LoyaltyController::class, 'show']);
    Route::post('/loyalty/redeem', [\App\Http\Controllers\API\LoyaltyController::class, 'redeem']);

    Route::get('/plans', [\App\Http\Controllers\API\PlanController::class, 'index']);
    Route::post('/plans/{plan}/subscribe', [\App\Http\Controllers\API\PlanController::class, 'subscribe']);
    Route::get('/subscriptions/mine', [\App\Http\Controllers\API\SubscriptionController::class, 'mine']);
    Route::get('/subscriptions/{id}/entitlements', [\App\Http\Controllers\API\SubscriptionController::class, 'entitlements']);
    Route::get('/subscriptions/{id}/usage', [\App\Http\Controllers\API\SubscriptionController::class, 'usage']);
    Route::post('/subscriptions/{id}/cancel', [\App\Http\Controllers\API\SubscriptionController::class, 'cancel']);
    Route::post('/subscriptions/{id}/upgrade', [\App\Http\Controllers\API\SubscriptionController::class, 'upgrade']);
    Route::post('/subscriptions/{id}/downgrade', [\App\Http\Controllers\API\SubscriptionController::class, 'downgrade']);
    Route::post('/subscriptions/{id}/renew-now', [\App\Http\Controllers\API\SubscriptionController::class, 'renewNow']);

    // Phase B0.2 — Service Partner -> Worker delegation.
    Route::get('/worker/jobs', [\App\Http\Controllers\API\WorkerJobController::class, 'index']);
    Route::post('/worker/jobs/{booking}/start', [\App\Http\Controllers\API\WorkerJobController::class, 'start']);
    Route::post('/worker/jobs/{booking}/complete', [\App\Http\Controllers\API\WorkerJobController::class, 'complete']);
    Route::post('/partner/workers/assign-booking', [\App\Http\Controllers\API\PartnerWorkerController::class, 'assignBooking']);

    // Phase 22.4 — Parcel rider (FieldWorker, capability_type='parcel_rider')
    // job flow. Same shape as WorkerJobController above. Gated end-to-end
    // by ModuleActivationService inside CreateParcelOrderAction -- these
    // action-execution endpoints have no orders to act on at all while the
    // module stays disabled, since none can ever be created.
    Route::get('/worker/parcel-orders', [\App\Http\Controllers\API\ParcelWorkerJobController::class, 'index']);
    Route::post('/worker/parcel-orders/{parcelOrderId}/accept', [\App\Http\Controllers\API\ParcelWorkerJobController::class, 'accept']);
    Route::post('/worker/parcel-orders/{parcelOrderId}/pickup', [\App\Http\Controllers\API\ParcelWorkerJobController::class, 'pickup']);
    Route::post('/worker/parcel-orders/{parcelOrderId}/deliver', [\App\Http\Controllers\API\ParcelWorkerJobController::class, 'deliver']);

    // Phase 22.6 — Taxi driver (FieldWorker, capability_type='taxi_driver')
    // trip flow. Same shape as ParcelWorkerJobController above.
    Route::get('/worker/taxi-rides', [\App\Http\Controllers\API\TaxiWorkerJobController::class, 'index']);
    Route::post('/worker/taxi-rides/{taxiRideId}/accept', [\App\Http\Controllers\API\TaxiWorkerJobController::class, 'accept']);
    Route::post('/worker/taxi-rides/{taxiRideId}/start', [\App\Http\Controllers\API\TaxiWorkerJobController::class, 'start']);
    Route::post('/worker/taxi-rides/{taxiRideId}/complete', [\App\Http\Controllers\API\TaxiWorkerJobController::class, 'complete']);

    // Phase 24 -- Marketplace delivery rider (FieldWorker, capability_type
    // one of food_delivery_rider/grocery_delivery_rider/commerce_delivery_
    // rider/pharmacy_delivery_rider). Same shape as ParcelWorkerJobController
    // above -- one accept + one OTP-gated deliver, no separate pickup step
    // (see marketplace_orders migration's own "one OTP, not two" comment).
    Route::get('/worker/marketplace-orders', [\App\Http\Controllers\API\MarketplaceWorkerJobController::class, 'index']);
    Route::post('/worker/marketplace-orders/{marketplaceOrderId}/accept', [\App\Http\Controllers\API\MarketplaceWorkerJobController::class, 'accept']);
    Route::post('/worker/marketplace-orders/{marketplaceOrderId}/deliver', [\App\Http\Controllers\API\MarketplaceWorkerJobController::class, 'deliver']);
});

// No auth middleware — this is a server-to-server callback from Razorpay,
// authenticated via signature verification inside the controller instead.
Route::post('/webhooks/razorpay', [\App\Http\Controllers\API\PaymentController::class, 'webhook']);