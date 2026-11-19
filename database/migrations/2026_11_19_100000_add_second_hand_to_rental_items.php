<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Second-hand (owner 2026-11-19): people also post used things they SELL, on the same engine as Rent anything
 * ("People's posts"). `deal` says which: rent (default, every existing row) or sell. A sale post has one price
 * ("Price can be discussed" = negotiable), optionally "Bill available" (has_bill), and is marked sold by its owner
 * (sold_at: hidden from lists; its page stays up saying "Sold", so links never break). Contact only: no payment
 * runs through 1CallFix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_items', function (Blueprint $table) {
            $table->string('deal', 8)->default('rent')->after('zone_id'); // rent | sell
            $table->boolean('negotiable')->default(false)->after('deposit');
            $table->boolean('has_bill')->default(false)->after('negotiable');
            $table->timestamp('sold_at')->nullable()->after('expires_at');
            $table->index(['deal', 'status', 'is_active']);
        });
        // The same permission now checks both kinds of post (Admin → People's posts); say so on the Roles screen.
        \Illuminate\Support\Facades\DB::table('permissions')->where('slug', 'rental_items.manage')
            ->update(['label' => "People's posts (rent and second-hand): approve, handle reports, switch off", 'updated_at' => now()]);
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('permissions')->where('slug', 'rental_items.manage')
            ->update(['label' => 'Rent anything: approve posted items, handle reports, switch an item off', 'updated_at' => now()]);
        Schema::table('rental_items', function (Blueprint $table) {
            $table->dropIndex(['deal', 'status', 'is_active']);
            $table->dropColumn(['deal', 'negotiable', 'has_bill', 'sold_at']);
        });
    }
};
