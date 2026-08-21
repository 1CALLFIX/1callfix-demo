<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The auditable, scoped, time-bound exception mechanism the mission
// explicitly asked for INSTEAD OF a raw payments_enabled boolean. A
// Provider is never granted a withdrawal exception directly by anyone --
// this table is only ever written by KycSupportRequestService::decide()
// when a Central Admin APPROVES a franchise-raised support request (see
// kyc_support_requests.exception_id). KycWithdrawalPolicyService checks
// for an active (not expired, not revoked) row here as one input among
// several -- never a standalone toggle.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_withdrawal_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->timestamp('starts_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['provider_id', 'revoked_at', 'expires_at'], 'kyc_withdrawal_exc_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_withdrawal_exceptions');
    }
};
