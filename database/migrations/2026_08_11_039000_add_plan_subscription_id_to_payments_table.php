<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Unlike a wallet top-up (which credits payment.user_id directly, no other
// record needed), activating a subscription after payment needs to know
// WHICH pending subscription this payment is for — this nullable FK is that
// link, read by SubscriptionService::activateAfterPayment() /
// PaymentController::handlePaymentCaptured().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('plan_subscription_id')->nullable()->after('user_id')
                ->constrained('subscriptions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['plan_subscription_id']);
            $table->dropColumn('plan_subscription_id');
        });
    }
};
