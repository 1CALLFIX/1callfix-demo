<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureNotInMaintenanceMode::class])->group(function () {
    Route::post('/bookings/{booking}/accept', [\App\Http\Controllers\API\DispatchController::class, 'accept']);
    Route::post('/bookings/{booking}/complete', [\App\Http\Controllers\API\DispatchController::class, 'complete']);
    Route::post('/bookings/{booking}/pay/create-order', [\App\Http\Controllers\API\PaymentController::class, 'createOrder']);
    Route::post('/bookings/{booking}/pay/confirm', [\App\Http\Controllers\API\PaymentController::class, 'confirm']);
    Route::get('/wallet', [\App\Http\Controllers\API\WalletController::class, 'show']);
    Route::post('/wallet/topup', [\App\Http\Controllers\API\WalletController::class, 'topUp']);
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
});

// No auth middleware — this is a server-to-server callback from Razorpay,
// authenticated via signature verification inside the controller instead.
Route::post('/webhooks/razorpay', [\App\Http\Controllers\API\PaymentController::class, 'webhook']);