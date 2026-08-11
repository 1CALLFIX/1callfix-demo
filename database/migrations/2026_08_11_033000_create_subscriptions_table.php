<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A purchased plan instance, any actor — polymorphic subscribable_type/id
// (User for Customer Prime / Provider Package, BusinessAccount for a pooled
// business subscription) so ONE table serves every plan family, per
// amendment 1 ("keep one universal engine"). Scheduled upgrade/downgrade is
// a field set on an otherwise-active row (pending_plan_id/pending_change_*),
// not a separate status — a status meaning "active but something is queued"
// would be ambiguous.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->morphs('subscribable'); // subscribable_type, subscribable_id
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->enum('status', [
                'pending_payment', 'active', 'grace_period', 'past_due',
                'paused', 'cancelled', 'expired', 'failed',
            ])->default('pending_payment');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamp('grace_period_ends_at')->nullable();
            $table->foreignId('pending_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->enum('pending_change_type', ['upgrade', 'downgrade'])->nullable();
            $table->timestamp('pending_change_effective_at')->nullable();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['current_period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
