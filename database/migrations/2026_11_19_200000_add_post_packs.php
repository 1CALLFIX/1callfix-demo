<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paid packs for people's posts (owner 2026-11-19): Featured, Boost and two bulk Featured packs on rent and second-hand
 * posts, paid through the existing listing fee engine (listing_fees, listing_type "item").
 *
 * - rental_items.boosted_until: a Boosted post shows in the "Boosted" row at the top of its page until then.
 * - listing_fees.franchise_share: the franchise's share of this fee, fixed when it is bought (HQ holds the money).
 * - listing_fees.credits / credits_used / credits_until: a bulk pack's Featured credits, usable on any of the
 *   person's posts until credits_until.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_items', function (Blueprint $table) {
            $table->timestamp('boosted_until')->nullable()->after('featured_until')->index();
        });

        Schema::table('listing_fees', function (Blueprint $table) {
            $table->decimal('franchise_share', 10, 2)->nullable()->after('amount');
            $table->unsignedSmallInteger('credits')->nullable()->after('days');
            $table->unsignedSmallInteger('credits_used')->default(0)->after('credits');
            $table->timestamp('credits_until')->nullable()->after('credits_used');
        });
    }

    public function down(): void
    {
        Schema::table('listing_fees', function (Blueprint $table) {
            $table->dropColumn(['franchise_share', 'credits', 'credits_used', 'credits_until']);
        });
        Schema::table('rental_items', function (Blueprint $table) {
            $table->dropIndex(['boosted_until']);
            $table->dropColumn('boosted_until');
        });
    }
};
