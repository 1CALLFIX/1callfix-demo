<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Function halls R4 (owner 2026-11-13): a damage claim on a hall deposit. The owner says what was damaged and how much
 * of the deposit they ask for; the team decides how much the hall gets (the rest goes back to the customer) under the
 * MANUAL MONEY ACTIONS approval model (CLAUDE.md): permission hall_damage.payout (Super Admin by default), franchise /
 * HQ limits, maker-checker above a threshold, a queue with escalation, a reason at every step, a full audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hall_damage_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hall_booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('deposit_amount', 12, 2);
            $table->decimal('asked_amount', 12, 2); // what the owner asked for (at most the deposit)
            $table->string('note', 1000);
            // open → (request) → awaiting_approval → settled | failed (retry) ; a rejected request goes back to open
            $table->string('status', 20)->default('open');
            $table->decimal('hall_amount', 12, 2)->nullable(); // decided: what the hall gets; the rest goes back to the customer
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_reason', 500)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approval_reason', 500)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->string('failure_message', 1000)->nullable();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamp('last_escalated_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'franchise_id']);
        });

        $now = now();
        if (! DB::table('permissions')->where('slug', 'hall_damage.payout')->exists()) {
            DB::table('permissions')->insert([
                'slug' => 'hall_damage.payout', 'label' => 'Function halls: decide damage claims on deposits (approval model)',
                'group' => 'Rental', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $permissionId = DB::table('permissions')->where('slug', 'hall_damage.payout')->value('id');
        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');
        if ($superAdminRoleId && ! DB::table('permission_role')->where('role_id', $superAdminRoleId)->where('permission_id', $permissionId)->exists()) {
            DB::table('permission_role')->insert(['role_id' => $superAdminRoleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'hall_damage.payout')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
        Schema::dropIfExists('hall_damage_claims');
    }
};
