<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase: P1
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('phone')->unique();
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->enum('role', ['customer','provider','franchise_owner','zone_manager','super_admin'])->default('customer');
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('profile_photo')->nullable();
            $table->string('preferred_language', 10)->default('en');
            $table->enum('status', ['active','suspended','pending_verification'])->default('pending_verification');
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('fcm_token')->nullable();
            $table->string('referral_code')->unique()->nullable();
            $table->foreignId('referred_by')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
