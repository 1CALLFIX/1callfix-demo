<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listing fee (owner 2026-11-17): an owner pays to put a property on the app (and optionally to feature it), online
 * through the HQ gateway or from their wallet. Its own small table on purpose: the payments.purpose enum is not widened
 * (a production change with its own risk) and a listing fee is not a booking. Default fee is 0 = free, so nothing
 * changes until the Super Admin sets a fee for a city. Written for any listing type (`listing_type`), property first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_fees', function (Blueprint $table) {
            $table->id();
            $table->string('listing_type', 40);
            $table->unsignedBigInteger('listing_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('franchise_id')->nullable();
            $table->string('kind', 12); // listing | featured
            $table->decimal('amount', 10, 2);
            $table->unsignedSmallInteger('days');
            $table->string('method', 10); // wallet | online
            $table->string('status', 10)->default('pending'); // pending | paid
            $table->string('gateway', 30)->nullable();
            $table->string('gateway_order_id', 80)->nullable()->unique();
            $table->string('gateway_payment_id', 80)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['listing_type', 'listing_id', 'status']);
        });

        Schema::table('house_listings', function (Blueprint $table) {
            $table->timestamp('featured_until')->nullable()->after('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('house_listings', fn (Blueprint $table) => $table->dropColumn('featured_until'));
        Schema::dropIfExists('listing_fees');
    }
};
