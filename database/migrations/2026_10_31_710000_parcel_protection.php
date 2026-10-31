<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parcel Stage 2 — protection (owner decision 2026-10-31): an optional add-on; the sender declares the parcel's
 * value and pays a fee (% of it, min/max, Super Admin settings; unset = off). If the parcel is lost or damaged they
 * file a claim with photos; the payout goes through the MANUAL MONEY ACTIONS approval model only (CLAUDE.md) —
 * permission, scope, limits, maker-checker, queue with escalation, reason, audit — never automatically.
 * Additive only: two nullable columns on parcel_orders, one new table, two permissions (Super Admin by default).
 */
return new class extends Migration
{
    private const SLUGS = [
        'parcel_claims.manage' => 'Decide parcel protection claims (accept / decline)',
        'parcel_claims.payout' => 'Pay parcel protection claims (approval model)',
    ];

    public function up(): void
    {
        Schema::table('parcel_orders', function (Blueprint $table) {
            $table->decimal('declared_value', 10, 2)->nullable()->after('package_contents');
            $table->decimal('protection_fee', 10, 2)->nullable()->after('declared_value');
        });

        Schema::create('parcel_protection_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parcel_order_id')->constrained('parcel_orders')->cascadeOnDelete();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 20); // lost | damaged
            $table->text('description');
            $table->json('photos');
            $table->decimal('amount_claimed', 10, 2);
            $table->string('status', 20)->default('requested'); // requested | accepted | declined | paid
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();

            // Payout — approval model (same shape as marketplace_returns / booking_disputes).
            $table->decimal('payout_amount', 10, 2)->default(0);
            $table->string('payout_status', 30)->nullable(); // awaiting_request | awaiting_approval | paid | failed
            $table->foreignId('payout_requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('payout_request_reason')->nullable();
            $table->timestamp('payout_requested_at')->nullable();
            $table->foreignId('payout_approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('payout_approval_reason')->nullable();
            $table->timestamp('payout_approved_at')->nullable();
            $table->timestamp('payout_rejected_at')->nullable();
            $table->text('payout_failure_message')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamp('last_escalated_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'payout_status']);
            $table->index('franchise_id');
        });

        $now = now();
        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');
        foreach (self::SLUGS as $slug => $label) {
            DB::table('permissions')->insert(['slug' => $slug, 'label' => $label, 'group' => 'Parcel', 'created_at' => $now, 'updated_at' => $now]);
            if ($superAdminRoleId) {
                DB::table('permission_role')->insert([
                    'role_id' => $superAdminRoleId, 'permission_id' => DB::table('permissions')->where('slug', $slug)->value('id'),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::SLUGS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('slug', array_keys(self::SLUGS))->delete();
        Schema::dropIfExists('parcel_protection_claims');

        Schema::table('parcel_orders', function (Blueprint $table) {
            $table->dropColumn(['declared_value', 'protection_fee']);
        });
    }
};
