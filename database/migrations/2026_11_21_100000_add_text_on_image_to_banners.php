<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banners whose picture already carries its own words (owner 2026-11-21, the HD module banners): when ticked, the
 * site does not print the banner's title over the bottom of the picture. The title is still kept and used as the
 * link's name for screen readers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->boolean('text_on_image')->default(false)->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn('text_on_image');
        });
    }
};
