<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Adds on-hold tracking to bookings, split into two categories:
//   - customer_side: routine, provider stays assigned, follow-up call goes to customer
//   - provider_side: red flag, urgent, follow-up call goes to provider, feeds
//     into provider reliability tracking (rating_avg / provider_badges) later
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('hold_category', ['customer_side', 'provider_side'])
                ->nullable()->after('status');
            $table->enum('hold_reason', [
                'awaiting_spares',
                'awaiting_customer_approval',
                'awaiting_payment_decision',
                'provider_unresponsive',
                'payment_not_reconciled',
                'other_provider_issue',
                'other_customer_issue',
            ])->nullable()->after('hold_category');
            $table->text('hold_note')->nullable()->after('hold_reason');
            $table->timestamp('on_hold_since')->nullable()->after('hold_note');
        });

        // bookings.status is a plain MySQL enum column — add 'on_hold' as a
        // valid value alongside the existing ones via a raw MODIFY COLUMN.
        DB::statement("
            ALTER TABLE bookings MODIFY COLUMN status ENUM(
                'pending','searching_provider','assigned','provider_en_route',
                'in_progress','on_hold','completed','cancelled','disputed'
            ) NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['hold_category', 'hold_reason', 'hold_note', 'on_hold_since']);
        });

        DB::statement("
            ALTER TABLE bookings MODIFY COLUMN status ENUM(
                'pending','searching_provider','assigned','provider_en_route',
                'in_progress','completed','cancelled','disputed'
            ) NOT NULL DEFAULT 'pending'
        ");
    }
};
