<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-commerce Phase 3b — shop returns (owner decision 2026-10-24: 3-day window, damaged or wrong item only). A return
 * is decided by an admin, received back by the seller, and its refund goes through the MANUAL MONEY ACTIONS approval
 * model (CLAUDE.md) with its own permission, limits, maker-checker and escalation — the same columns as
 * booking_disputes. Two permissions, Super Admin only by default, assignable from /admin/roles.
 */
return new class extends Migration
{
    private const SLUGS = [
        'marketplace_returns.manage' => 'Decide shop returns (accept / decline, item received)',
        'marketplace_returns.refund' => 'Refund shop returns (approval model)',
    ];

    public function up(): void
    {
        Schema::create('marketplace_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 20); // damaged | wrong_item
            $table->text('description');
            $table->json('photos');
            $table->json('items'); // [{order_item_id, quantity}]
            $table->string('status', 20)->default('requested'); // requested | accepted | declined | received | refunded
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('received_at')->nullable();

            // Refund — approval model (same shape as booking_disputes).
            $table->decimal('refund_amount', 10, 2)->default(0);
            $table->string('refund_destination', 20)->nullable(); // original | wallet
            $table->string('refund_status', 30)->nullable(); // awaiting_request | awaiting_approval | refunded | failed
            $table->foreignId('refund_requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('refund_request_reason')->nullable();
            $table->timestamp('refund_requested_at')->nullable();
            $table->foreignId('refund_approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('refund_approval_reason')->nullable();
            $table->timestamp('refund_approved_at')->nullable();
            $table->timestamp('refund_rejected_at')->nullable();
            $table->string('refund_gateway_id')->nullable();
            $table->text('refund_failure_message')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamp('last_escalated_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'refund_status']);
            $table->index('franchise_id');
        });

        $now = now();
        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');
        foreach (self::SLUGS as $slug => $label) {
            DB::table('permissions')->insert(['slug' => $slug, 'label' => $label, 'group' => 'Marketplace', 'created_at' => $now, 'updated_at' => $now]);
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
        Schema::dropIfExists('marketplace_returns');
    }
};
