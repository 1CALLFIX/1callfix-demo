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

Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureNotInMaintenanceMode::class])->group(function () {
    Route::post('/bookings/{booking}/accept', [\App\Http\Controllers\API\DispatchController::class, 'accept']);
    Route::post('/bookings/{booking}/complete', [\App\Http\Controllers\API\DispatchController::class, 'complete']);
    Route::post('/bookings/{booking}/pay/create-order', [\App\Http\Controllers\API\PaymentController::class, 'createOrder']);
    Route::post('/bookings/{booking}/pay/confirm', [\App\Http\Controllers\API\PaymentController::class, 'confirm']);
    Route::get('/wallet', [\App\Http\Controllers\API\WalletController::class, 'show']);
    Route::post('/wallet/topup', [\App\Http\Controllers\API\WalletController::class, 'topUp']);
    Route::get('/bookings/{bookingId}/chat/{withUserId}', [\App\Http\Controllers\API\ChatController::class, 'index']);
    Route::post('/bookings/{bookingId}/chat', [\App\Http\Controllers\API\ChatController::class, 'store']);
    Route::get('/chat/attachments/{messageId}', [\App\Http\Controllers\API\ChatController::class, 'attachment']);
    Route::get('/payments/{paymentId}/document', [\App\Http\Controllers\API\DocumentController::class, 'paymentDocument']);
    Route::get('/providers/nearby', [\App\Http\Controllers\API\ProviderDiscoveryController::class, 'nearby']);
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
});

// No auth middleware — this is a server-to-server callback from Razorpay,
// authenticated via signature verification inside the controller instead.
Route::post('/webhooks/razorpay', [\App\Http\Controllers\API\PaymentController::class, 'webhook']);