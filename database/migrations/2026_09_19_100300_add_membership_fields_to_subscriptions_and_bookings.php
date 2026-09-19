<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - subscriptions.registered_address_id: the ONE saved address an address-locked
 *   membership is valid for (existing `addresses` table — no second address
 *   system). nullOnDelete is a backstop only; deleting a membership's address
 *   is refused in the UI.
 * - subscriptions.expiry_reminder_sent_at: makes the expiry reminder fire once
 *   per period; cleared whenever a new period starts.
 * - bookings.is_priority: set at creation when the customer's membership
 *   carries Priority Based Service. Read only by ServiceMatchingJob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('registered_address_id')->nullable()->after('plan_id')
                ->constrained('addresses')->nullOnDelete();
            $table->timestamp('expiry_reminder_sent_at')->nullable()->after('grace_period_ends_at');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('is_priority')->default(false)->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('is_priority');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registered_address_id');
            $table->dropColumn('expiry_reminder_sent_at');
        });
    }
};
