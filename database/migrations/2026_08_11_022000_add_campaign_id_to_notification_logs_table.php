<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Links a delivery-level log row back to the campaign that produced it --
// null for transactional sends (CreateBookingAction etc.), set for
// Notification Center sends (CampaignService::send()). notification_logs'
// shape/role is otherwise unchanged.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('notifiable_id')->constrained('notification_campaigns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
            $table->dropColumn('campaign_id');
        });
    }
};
