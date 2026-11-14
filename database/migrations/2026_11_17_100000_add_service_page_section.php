<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-page sections (owner 2026-11-14, the Urban Company way): when a customer opens a subcategory such as Split AC,
 * its services show in coloured sections ("Service", "Repair & gas", "Install & move"), each with an Add button.
 * Display only: every item stays its own service with its own price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('page_section', 60)->nullable()->after('choice_label');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('page_section');
        });
    }
};
