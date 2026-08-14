<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Operations/Troubleshoot expansion (mission Phase 10, item 4 -- Payment
// Webhook Failures). PaymentController::webhook() previously only
// Log::warning()'d an unmatched/unhandled event to the server log file --
// not queryable, not browsable, no admin visibility at all, and an
// unmatched order_id silently returned 200 (so Razorpay never retried it
// and the payment update was permanently lost with zero trace beyond a
// log line). Every webhook receipt is now recorded here regardless of
// outcome -- the payload is stored (admin-only screen, never public) so a
// failed-to-process event can be safely REPROCESSED later through the
// exact same idempotent handler the live webhook uses, not a second one.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('gateway')->default('razorpay');
            $table->string('event')->nullable();
            $table->string('gateway_order_id')->nullable();
            $table->string('gateway_payment_id')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->boolean('signature_valid')->default(false);
            $table->boolean('processed')->default(false);
            // invalid_signature | unmatched_order | unhandled_event |
            // captured | failed | already_processed | error
            $table->string('outcome');
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['gateway_order_id']);
            $table->index(['processed', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_logs');
    }
};
