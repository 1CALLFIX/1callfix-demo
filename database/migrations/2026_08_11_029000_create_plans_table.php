<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Universal 1CallFix Plan Engine — Phase A. The catalog definition: one row
// per sellable plan (Customer Prime, a Provider/Vendor Package tier, etc.).
// `plan_family` is a free string, not an enum — new families (parcel_volume,
// taxi_driver_pass, ...) are configuration added later, never a schema
// change. `scope_type`/`scope_id` reuse Setting::SCOPE_ORDER's exact
// vocabulary so plan visibility resolves the same way every other
// scope-aware feature in this app already does.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('plan_family'); // customer_membership | provider_package | (future families)
            $table->string('module')->nullable();
            $table->enum('scope_type', ['global', 'country', 'city', 'zone', 'franchise'])->default('global');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->enum('eligible_actor_type', ['customer', 'provider', 'business_account']);
            $table->json('eligibility_rules')->nullable();
            $table->enum('billing_cycle', ['daily', 'weekly', 'monthly', 'quarterly', 'half_yearly', 'annual', 'custom'])->default('monthly');
            $table->unsignedInteger('custom_cycle_days')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->enum('stacking_strategy', ['exclusive', 'stack', 'highest_benefit_wins', 'most_specific_wins', 'priority_order'])->default('exclusive');
            $table->integer('stacking_priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['plan_family', 'eligible_actor_type', 'is_active']);
            $table->index(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
