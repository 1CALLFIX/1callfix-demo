<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "KYC / Withdrawal Restriction Support Request" -- the ONLY path a
// Franchise has to influence a Partner's withdrawal restriction. Franchise
// staff (kyc.support_requests.create) can raise one; only Central Admin
// (kyc.support_requests.decide -- a SEPARATE permission, never held by the
// same franchise-scoped role by default) can approve/reject/request more
// information. Approval creates a kyc_withdrawal_exceptions row; the
// Franchise never gets a direct "enable withdrawal" action anywhere in
// this codebase.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_support_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('raised_by')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->json('missing_documents')->nullable();
            $table->text('assistance_provided')->nullable();
            $table->enum('urgency', ['low', 'normal', 'high'])->default('normal');
            $table->enum('status', ['open', 'approved', 'rejected', 'more_info_requested', 'closed'])->default('open');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('exception_id')->nullable()->constrained('kyc_withdrawal_exceptions')->nullOnDelete();
            $table->timestamps();

            $table->index(['provider_id', 'status']);
            $table->index(['franchise_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_support_requests');
    }
};
