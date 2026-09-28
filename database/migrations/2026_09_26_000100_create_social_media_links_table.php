<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per social platform, admin-managed (Settings → Platform /
     * Branding). `profile_url` drives the footer icon row today.
     *
     * access_token / token_expiry / connected_at are deliberately unused:
     * they are the landing spot for a future OAuth auto-posting module so
     * that module needs no schema rework. Nothing reads or writes them yet.
     */
    public function up(): void
    {
        Schema::create('social_media_links', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 32)->unique();
            $table->string('profile_url', 500)->nullable();
            $table->text('access_token')->nullable();      // future: encrypted OAuth token
            $table->timestamp('token_expiry')->nullable(); // future
            $table->timestamp('connected_at')->nullable(); // future
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_media_links');
    }
};
