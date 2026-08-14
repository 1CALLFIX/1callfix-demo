<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 (CMS/content audit) finding: content_pages had no
 * draft/published status at all — a page went live the instant it was
 * saved, unlike its sibling table `faqs`, which already has `is_active`.
 * That asymmetry was inert while nothing read pages publicly, but this
 * migration lands alongside a real public read API (GET /api/pages/{slug})
 * that now makes it consequential — a page an admin is still drafting
 * must not be reachable until explicitly published. Defaults to true so
 * every existing page (there are none in production yet — confirmed no
 * seeder ever creates one) stays visible, matching current behavior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_pages', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('content_pages', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
