<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live Visitors (owner 2026-11-21: "how many users are online, what they are checking and what they are going to
 * book"). Filled by POST /visit-ping from public/js/visit-ping.js on customer pages (App\Services\Visitors\VisitTracker).
 *
 * page_views        one row per customer page opened. visitor_id is a random id kept in that browser (not personal);
 *                   user_id only when signed in. Location columns carry the page's city / the visitor's area so the
 *                   admin screen is row-scoped like every other (AuthorizationService::scopeQuery). Pruned after
 *                   config('visitors.keep_days') by visitors:prune.
 * visitor_presence  one row per visitor: what they are on now and when last seen (a ping every minute while the page is
 *                   open). "Online now" = seen in the last few minutes. Pruned after 2 days.
 *
 * Additive only; no existing table changes. down() drops both tables (visit history only, no money or orders).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            $table->char('visitor_id', 32);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('page_type', 16);           // home, browse, item, search, cart, checkout, other
            $table->string('module', 16)->nullable();  // service, parcel, shop, food, rental, hotel, events, listings
            $table->string('route_name', 60)->nullable();
            $table->string('path', 255);
            $table->string('title', 150)->nullable();
            $table->string('channel', 8);              // app | web
            $table->string('referrer_host', 100)->nullable();
            $table->unsignedBigInteger('zone_id')->nullable();
            $table->unsignedBigInteger('franchise_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['page_type', 'created_at']);
            $table->index(['visitor_id', 'created_at']);
            $table->index(['franchise_id', 'created_at']);
            $table->index(['city_id', 'created_at']);
        });

        Schema::create('visitor_presence', function (Blueprint $table) {
            $table->char('visitor_id', 32)->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('page_type', 16);
            $table->string('module', 16)->nullable();
            $table->string('path', 255);
            $table->string('title', 150)->nullable();
            $table->string('channel', 8);
            $table->unsignedBigInteger('zone_id')->nullable();
            $table->unsignedBigInteger('franchise_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedInteger('pages')->default(1);   // pages opened in this visit
            $table->timestamp('started_at');                 // this visit (a gap over 30 minutes starts a new one)
            $table->timestamp('last_seen_at');

            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_presence');
        Schema::dropIfExists('page_views');
    }
};
