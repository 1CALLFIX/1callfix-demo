<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reuses the existing Address model rather than duplicating lat/lng/address
// fields. Pooling (amendment 12) falls out for free: a subscription belongs
// to the business_account, not to any one location, so N locations share
// one entitlement balance automatically.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('address_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->timestamps();

            $table->index(['business_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_locations');
    }
};
