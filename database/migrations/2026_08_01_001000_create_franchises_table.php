<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase: P1
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franchises', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('city');
            $table->string('state')->nullable();
            $table->string('country')->default('India');
            $table->foreignId('owner_user_id')->nullable(); // set after users table exists (nullable FK added later)
            $table->enum('commission_model', ['revenue_share','flat_fee','subscription_only'])->default('revenue_share');
            $table->decimal('commission_value', 8, 2)->default(0);
            $table->decimal('platform_fee_percent', 5, 2)->default(0);
            $table->enum('status', ['active','inactive','pending_setup'])->default('pending_setup');
            $table->timestamps();
            $table->softDeletes();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franchises');
    }
};
