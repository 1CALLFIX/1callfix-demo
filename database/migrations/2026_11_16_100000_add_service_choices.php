<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service choices (owner 2026-11-14): sibling services shown as one card with choice buttons.
 * Display only: every choice stays its own service with its own price, so the money path is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('choice_of')->nullable()->after('subcategory_id')->constrained('services')->nullOnDelete();
            $table->string('choice_group_name', 120)->nullable()->after('choice_of');
            $table->string('choice_label', 80)->nullable()->after('choice_group_name');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('choice_of');
            $table->dropColumn(['choice_group_name', 'choice_label']);
        });
    }
};
