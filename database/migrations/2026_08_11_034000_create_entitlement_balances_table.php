<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Standing balance per subscription per entitlement per period. Net
// available is always computed as granted + rolled_over - consumed +
// reversed — never destructively mutated. Closed periods (status='closed')
// are NEVER deleted (amendment 8: "never destroy previous entitlement
// periods") — they stay queryable forever for usage history / audits.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlement_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_entitlement_id')->constrained()->cascadeOnDelete();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->integer('granted_quantity')->default(0);
            $table->decimal('granted_monetary_value', 10, 2)->default(0);
            $table->integer('rolled_over_quantity')->default(0);
            $table->decimal('rolled_over_monetary_value', 10, 2)->default(0);
            $table->timestamp('rollover_expires_at')->nullable();
            $table->integer('consumed_quantity')->default(0);
            $table->decimal('consumed_monetary_value', 10, 2)->default(0);
            $table->integer('reversed_quantity')->default(0);
            $table->decimal('reversed_monetary_value', 10, 2)->default(0);
            $table->enum('status', ['current', 'closed'])->default('current');
            $table->timestamps();

            $table->index(['subscription_id', 'plan_entitlement_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_balances');
    }
};
