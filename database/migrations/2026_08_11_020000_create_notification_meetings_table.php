<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The business object ("Mandatory Rider Training, 20 Aug, 10 AM, Nellore").
// A meeting doesn't itself send anything -- MeetingService turns it into one
// or more notification_campaigns rows (an initial announcement + however
// many configured reminders), each of which goes through the exact same
// Campaign -> AudienceResolver -> Notification -> Channel -> Adapter path
// as any other campaign. No separate RSVP/attendance table yet (deliberately
// deferred, see report) -- meeting_id on notification_campaigns is the only
// hook a future RSVP feature would need.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_meetings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->string('location')->nullable();
            $table->string('meeting_link')->nullable();
            $table->enum('recipient_type', ['customers', 'providers', 'staff', 'everyone', 'specific_user'])->default('providers');
            $table->foreignId('specific_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('scope_type', ['global', 'country', 'city', 'zone', 'franchise'])->default('global');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('module')->nullable();
            $table->foreignId('organizer_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Minutes before starts_at for each reminder, e.g. [1440, 120] =
            // one day before + two hours before. Empty array = no reminders,
            // just the initial announcement. Never hard-coded.
            $table->json('reminder_offsets_minutes')->nullable();
            $table->enum('status', ['scheduled', 'completed', 'cancelled'])->default('scheduled');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_meetings');
    }
};
