<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Turns the existing decorative `banners` table into a real ad-revenue slot:
// zone-level targeting (optional, franchise-wide if left blank), a paid
// window (starts_at/expires_at), and who's paying for it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->foreignId('zone_id')->nullable()->after('franchise_id')
                ->constrained()->nullOnDelete();
            $table->timestamp('starts_at')->nullable()->after('link');
            $table->timestamp('expires_at')->nullable()->after('starts_at');
            $table->string('advertiser_name')->nullable()->after('expires_at');
            $table->string('advertiser_contact')->nullable()->after('advertiser_name');
            $table->decimal('price_paid', 10, 2)->nullable()->after('advertiser_contact');
            // null price_paid = internal/house banner (your own promos), not a
            // paid ad slot — keeps house banners and revenue banners in one
            // table without needing a separate "is this a paid ad" flag.
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropForeign(['zone_id']);
            $table->dropColumn(['zone_id', 'starts_at', 'expires_at', 'advertiser_name', 'advertiser_contact', 'price_paid']);
        });
    }
};
