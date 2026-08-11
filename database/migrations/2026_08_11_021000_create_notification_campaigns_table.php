<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The campaign/message-level record ("what was sent, to whom, when") that
// sits ABOVE notification_logs (which stays the per-recipient, per-channel
// delivery audit trail -- not replaced, not duplicated). Two sources
// produce a NotificationLog row today: a transactional action calling
// $user->notify() directly (no campaign_id), or CampaignService::send()
// iterating AudienceResolver's recipients and calling $user->notify() the
// exact same way (campaign_id set) -- same Notification/Channel/Adapter
// pipeline either way, see notification_logs' new campaign_id column.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_campaigns', function (Blueprint $table) {
            $table->id();
            // 'transactional' exists in the enum for documentation/reporting
            // completeness (that's what CreateBookingAction etc. produce
            // directly) but the composer only ever creates business/system/
            // operations rows -- transactional sends never go through a
            // Campaign row.
            $table->enum('category', ['transactional', 'business', 'system', 'operations'])->default('business');
            $table->string('type'); // e.g. promotion, app_announcement, app_update, maintenance, rider_meeting, vendor_meeting, staff_announcement
            $table->string('title');
            $table->text('message');
            $table->string('image_url')->nullable();
            $table->string('action_url')->nullable(); // deep link
            $table->string('module')->nullable(); // modules.code
            $table->enum('recipient_type', ['customers', 'providers', 'staff', 'everyone', 'specific_user'])->default('everyone');
            $table->foreignId('specific_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('scope_type', ['global', 'country', 'city', 'zone', 'franchise'])->default('global');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->json('filters')->nullable(); // e.g. {"prime_only":true,"active_only":true,"online_only":true}
            $table->string('channels')->default('mail'); // comma list: mail,sms,push,in_app
            $table->enum('priority', ['normal', 'high'])->default('normal');
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
            $table->foreignId('meeting_id')->nullable()->constrained('notification_meetings')->nullOnDelete();
            $table->enum('status', ['draft', 'scheduled', 'sending', 'sent', 'failed', 'cancelled'])->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_campaigns');
    }
};
