<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Business Account foundation — pulled into Phase A per the approved plan's
// amendment 9, NOT deferred to a later phase. owner_user_id reuses the
// existing User/auth system; no new identity model. This table exists so a
// plan subscription can belong to a business rather than only a single
// individual — Parcel/Food/etc. booking functionality is explicitly NOT
// built here (amendment 9's own caveat).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('business_type')->nullable();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['pending', 'active', 'suspended'])->default('pending');
            $table->enum('kyc_status', ['not_required', 'pending', 'approved', 'rejected'])->default('not_required');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_accounts');
    }
};
