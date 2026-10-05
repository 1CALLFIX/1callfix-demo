<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// F3: every slug an item ever had (or an old URL format it was reachable at) stays alive as a permanent 301.
// `scope` is the slug namespace: categories and services share "catalog" (one /{city}/{slug} space),
// cities use "city". unique(scope, old_slug): one old slug points at exactly one item.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slug_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 32);
            $table->string('old_slug', 191);
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();

            $table->unique(['scope', 'old_slug']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        // Same rollback guard as the attribution migrations: redirects are public URLs; never drop them silently.
        if (Schema::hasTable('slug_redirects') && DB::table('slug_redirects')->exists()) {
            throw new RuntimeException('slug_redirects holds data; refusing to drop it.');
        }

        Schema::dropIfExists('slug_redirects');
    }
};
