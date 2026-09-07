<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 push notifications — admin operational alerts opt-in.
 *
 * `fcm_token` (create_users_table) already carries the device token for
 * every actor. This flag is the NARROW-SCOPING mechanism for admins: an
 * admin who ticks "Enable order alerts" on the dashboard gets a push on
 * booking-created / payment-captured (App\Services\AdminOpsAlertService)
 * WITHOUT being swept into Notification Center broadcasts, which target a
 * campaign audience and never consult this column. Customers/providers
 * receive their transactional pushes by role and do not need it — this is
 * an admin-only preference, default off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('push_ops_alerts')->default(false)->after('fcm_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('push_ops_alerts');
        });
    }
};
