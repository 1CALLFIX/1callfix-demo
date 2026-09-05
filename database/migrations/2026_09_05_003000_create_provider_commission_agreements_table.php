<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase: Provider Commercial Rate Resolver — Phase 1
//
// Tier 1 of the new three-tier hierarchy (Provider negotiated -> Franchise
// default -> Global SuperAdmin default), consulted by
// App\Services\ProviderCommercialRateResolver. One row per provider (unique
// provider_id) -- a row's mere existence IS the "this provider has a
// negotiated rate" signal, so platform_fee_percent itself is never nullable
// here; "no agreement" is represented by no row, not a null column, same
// idiom Setting::clear() uses for "no override at this scope."
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_commission_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->unique()->constrained('providers')->cascadeOnDelete();
            $table->decimal('platform_fee_percent', 5, 2);
            $table->text('notes')->nullable();
            $table->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_commission_agreements');
    }
};
