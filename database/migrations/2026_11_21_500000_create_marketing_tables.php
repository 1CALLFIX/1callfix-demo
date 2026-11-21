<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digital Marketing (owner 2026-11-21: "do all our tasks from our own platform", daily posts on Facebook, Instagram,
 * Google Business, skipped when the module is off; invite old customers to the new app).
 *
 * social_posts             one planned post: picture, caption (English + Telugu), link, module, city, day and time.
 * social_post_deliveries   one row per post and platform: shared by hand today ("Done ✓"), by the automatic
 *                          publisher later (Meta / Google approval pending).
 * marketing_contacts       old customers who are not on the app yet (Excel / CSV import), invited by email or WhatsApp,
 *                          marked Joined when their mobile signs up.
 *
 * New tables only; no existing table changes. down() refuses while any of them holds rows (planned posts and
 * imported contacts are the owner's work), otherwise drops them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);                      // for the team, not shown on the post
            $table->text('caption_en')->nullable();
            $table->text('caption_te')->nullable();
            $table->string('hashtags', 300)->nullable();
            $table->string('image_path', 500)->nullable();      // public disk path, or a full https URL of an existing picture
            $table->string('link_url', 500)->nullable();        // page the post points to; UTM tags are added per platform
            $table->string('module', 16)->nullable();            // service, parcel, shop, food, rental, hotel, events, listings
            $table->unsignedBigInteger('franchise_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->json('platforms');                           // ["facebook","instagram","google_business","whatsapp"]
            $table->timestamp('scheduled_for')->nullable();
            $table->string('status', 12)->default('draft');      // draft | scheduled | posted | skipped
            $table->string('skip_reason', 200)->nullable();
            $table->string('source', 20)->default('manual');     // manual | banner | offer | top_item | new_shop | job | app
            $table->string('source_ref', 100)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
            $table->index(['franchise_id', 'scheduled_for']);
            $table->index(['source', 'source_ref']);
        });

        Schema::create('social_post_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->string('platform', 20);
            $table->string('status', 10)->default('pending');   // pending | posted | skipped | failed
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->string('post_url', 500)->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamps();

            $table->unique(['social_post_id', 'platform']);
        });

        Schema::create('marketing_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->nullable();
            $table->string('phone', 20)->nullable()->unique();   // +91XXXXXXXXXX
            $table->string('email', 190)->nullable();
            $table->unsignedBigInteger('franchise_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->string('city_name', 80)->nullable();          // as written in the file
            $table->string('import_batch', 40)->nullable();
            $table->timestamp('email_invited_at')->nullable();
            $table->timestamp('whatsapp_invited_at')->nullable();
            $table->unsignedBigInteger('joined_user_id')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->string('unsubscribe_token', 64)->unique();
            $table->unsignedBigInteger('imported_by')->nullable();
            $table->timestamps();

            $table->index('email');
            $table->index(['franchise_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['social_posts', 'marketing_contacts'] as $table) {
            if (Schema::hasTable($table) && \Illuminate\Support\Facades\DB::table($table)->exists()) {
                throw new RuntimeException("{$table} holds the team's posts or contacts; refusing to drop it.");
            }
        }
        Schema::dropIfExists('social_post_deliveries');
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('marketing_contacts');
    }
};
