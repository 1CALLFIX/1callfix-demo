<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// F1/F2 (function check 2026-10-17) — waiving a customer charge is a MANUAL MONEY ACTION (CLAUDE.md). Until now the
// booking page let anyone with bookings.reassign / bookings.cancel waive a cancellation charge, waive the admin-cancel
// fee or lower a disputed work-done amount, with no limit and no second person. This adds:
//  - charge_waivers: one row per waiver (requested -> applied / rejected / failed), the approval queue;
//  - permission bookings.waive_charge (Super Admin holds it; assignable to roles from /admin/roles).
return new class extends Migration
{
    private const SLUG = 'bookings.waive_charge';

    public function up(): void
    {
        Schema::create('charge_waivers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id')->index();
            $table->unsignedBigInteger('franchise_id')->nullable()->index(); // null = HQ-only
            $table->string('kind', 32); // cancellation_request | cancel_fee | interim_reduction
            $table->unsignedBigInteger('cancellation_request_id')->nullable();
            $table->decimal('amount', 10, 2); // rupees the customer no longer pays
            $table->json('payload')->nullable(); // interim_reduction: corrected amount + resolution note
            $table->string('status', 24)->index(); // awaiting_approval | applied | rejected | failed
            $table->unsignedBigInteger('requested_by_id');
            $table->string('request_reason', 500);
            $table->timestamp('requested_at');
            $table->unsignedBigInteger('approved_by_id')->nullable();
            $table->string('approval_reason', 500)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by_id')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamp('last_escalated_at')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('permissions')->insert([
            'slug' => self::SLUG,
            'label' => 'Waive a customer charge (cancellation charge, cancel fee, disputed work-done amount)',
            'group' => 'Finance',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $superAdminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');
        if ($superAdminRoleId) {
            DB::table('permission_role')->insert([
                'role_id' => $superAdminRoleId,
                'permission_id' => DB::table('permissions')->where('slug', self::SLUG)->value('id'),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('slug', self::SLUG)->value('id');
        DB::table('permission_role')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();

        Schema::dropIfExists('charge_waivers');
    }
};
