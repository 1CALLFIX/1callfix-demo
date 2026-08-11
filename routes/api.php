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
});

// No auth middleware — this is a server-to-server callback from Razorpay,
// authenticated via signature verification inside the controller instead.
Route::post('/webhooks/razorpay', [\App\Http\Controllers\API\PaymentController::class, 'webhook']);