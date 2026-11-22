<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Posting through Buffer (owner 2026-11-22): each delivery remembers how it went out (direct = our own Facebook
 * connection, buffer, manual) and Buffer's post id, so the schedule can ask Buffer whether the post went live and pick
 * up its address. Two nullable columns; existing rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_post_deliveries', function (Blueprint $table) {
            $table->string('via', 10)->nullable()->after('status');
            $table->string('external_id', 100)->nullable()->after('post_url');
        });
    }

    public function down(): void
    {
        Schema::table('social_post_deliveries', function (Blueprint $table) {
            $table->dropColumn(['via', 'external_id']);
        });
    }
};
