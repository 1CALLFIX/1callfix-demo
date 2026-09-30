<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// REF 1CF-CMS-ROOT-PAGES-001 — admin-controlled footer links and search
// description for CMS pages served at 1callfix.com/{slug}. Additive only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_pages', function (Blueprint $table) {
            $table->boolean('show_in_footer')->default(false)->after('is_active');
            $table->unsignedSmallInteger('footer_order')->default(0)->after('show_in_footer');
            $table->string('meta_description', 300)->nullable()->after('footer_order');
        });
    }

    public function down(): void
    {
        Schema::table('content_pages', function (Blueprint $table) {
            $table->dropColumn(['show_in_footer', 'footer_order', 'meta_description']);
        });
    }
};
