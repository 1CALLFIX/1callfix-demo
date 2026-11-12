<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hotels H4: a verified-stay review rates cleanliness, staff, location and value (1–5 each) beside the overall rating;
 * the hotel replies in provider_reply. replied_at for "replied N days ago".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->json('hotel_scores')->nullable()->after('comment');
            $table->timestamp('replied_at')->nullable()->after('provider_reply');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['hotel_scores', 'replied_at']);
        });
    }
};
