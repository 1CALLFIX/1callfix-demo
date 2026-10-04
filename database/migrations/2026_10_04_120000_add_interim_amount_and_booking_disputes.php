<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A2 (final mid-work cancellation rule). Additive only; nothing is dropped.
//
//  - bookings.interim_amount: the ONE amount the professional declares for work already done when a job is held
//    for spares (labour and parts together). Replaces the progress % / parts cost pair in the charge; those
//    legacy columns stay for history and are no longer written.
//  - booking_disputes: a customer's overpricing complaint raised from the order page AFTER payment. An admin
//    resolves it by hand. A refund decision is carried on the same row and runs through the manual-money approval
//    model (maker-checker, limits, scope, escalation), never automatically.
//  - permission bookings.refund_dispute (Super Admin holds it; assignable to roles from /admin/roles).
return new class extends Migration
{
    private const SLUG = 'bookings.refund_dispute';

    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('interim_amount', 10, 2)->nullable()->after('interim_progress_percent');
        });

        Schema::create('booking_disputes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id')->index();
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('franchise_id')->nullable()->index(); // null = HQ-only
            $table->text('reason');
            $table->decimal('amount_paid', 10, 2)->default(0);          // what the customer paid, frozen when raised
            $table->string('status', 16)->default('open')->index();     // open | resolved
            $table->unsignedBigInteger('resolved_by_id')->nullable();
            $table->string('outcome', 24)->nullable();                  // no_change | refund | adjusted_elsewhere
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            // refund decision -> approval model
            $table->decimal('refund_amount', 10, 2)->nullable();
            $table->string('refund_status', 24)->nullable()->index();   // awaiting_request | awaiting_approval | refunded | failed
            $table->unsignedBigInteger('refund_requested_by_id')->nullable();
            $table->text('refund_request_reason')->nullable();
            $table->timestamp('refund_requested_at')->nullable();
            $table->unsignedBigInteger('refund_approved_by_id')->nullable();
            $table->text('refund_approval_reason')->nullable();
            $table->timestamp('refund_approved_at')->nullable();
            $table->timestamp('refund_rejected_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->text('refund_failure_message')->nullable();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamp('last_escalated_at')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('permissions')->insert([
            'slug' => self::SLUG, 'label' => 'Request / approve refunds decided in a booking dispute', 'group' => 'Finance',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($roleId = DB::table('roles')->where('slug', 'super_admin')->value('id')) {
            DB::table('permission_role')->insert([
                'role_id' => $roleId, 'permission_id' => DB::table('permissions')->where('slug', self::SLUG)->value('id'),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('slug', self::SLUG)->value('id');
        if ($id) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }

        Schema::dropIfExists('booking_disputes');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('interim_amount');
        });
    }
};
