<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Play account deletion (owner 2026-11-05). closed_at: sign-in stopped (status suspended); erased_at: personal
 * details wiped 30 days later; deletion_requested_at: a partner asked, an admin closes it after dues are settled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deletion_requested_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('erased_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['deletion_requested_at', 'closed_at', 'erased_at']);
        });
    }
};
