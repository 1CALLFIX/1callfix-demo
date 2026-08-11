<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The audit trail Notifications needs to be more than "a settings page that
// saves a channel list" -- every send attempt (through any channel) gets a
// row here via a NotificationSent/NotificationFailed listener (see
// AppServiceProvider::boot()), not hand-rolled logging inside each channel.
// That's what "verify the actual event -> adapter flow" is checked against.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->string('channel'); // mail | sms | push | database
            $table->string('notification_type'); // FQCN of the Notification class
            $table->string('event')->nullable(); // e.g. booking.created, payout.paid
            $table->enum('status', ['sent', 'failed'])->default('sent');
            $table->text('error')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
