<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-07: a ♡ Like (Save) button next to Share on every service, product, shop, restaurant and dish, like
 * Flipkart / Meesho / Urban Company. A liked item lands on Account → Saved. The row keeps the name, link and photo
 * the customer saw, so any module (hotel rooms, rentals, future ones) can be saved without new columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('item_key', 60); // service:12, product:40, store:7
            $table->string('title', 255);
            $table->string('url', 500);     // our own path, e.g. /nellore/food/test-restaurant#dish-5
            $table->string('image_url', 1000)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_items');
    }
};
