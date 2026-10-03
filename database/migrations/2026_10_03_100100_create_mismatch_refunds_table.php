<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The single queue for Razorpay captures whose amount did not match the
// Payment row (RazorpayWebhookHandler::OUTCOME_AMOUNT_MISMATCH). One row per
// gateway payment (unique), created when the mismatch is logged, so queue
// age, scope, approval and escalation all work from one table. Money is
// stored in integer paise exactly as Razorpay reported it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mismatch_refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_webhook_log_id')->unique();
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->string('gateway_payment_id')->unique(); // one refund ever per Razorpay payment
            $table->unsignedBigInteger('franchise_id')->nullable()->index(); // null = HQ-only
            $table->unsignedBigInteger('amount_paise');
            $table->string('status', 24)->default('awaiting_request')->index();
            $table->unsignedBigInteger('requested_by_id')->nullable();
            $table->text('request_reason')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->unsignedBigInteger('approved_by_id')->nullable();
            $table->text('approval_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->string('gateway_refund_id')->nullable();
            $table->text('failure_message')->nullable();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamp('last_escalated_at')->nullable();
            $table->timestamp('refund_notice_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mismatch_refunds');
    }
};
