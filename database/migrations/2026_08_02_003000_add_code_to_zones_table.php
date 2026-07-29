<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            // Mirrors franchises.code — reserved for future per-zone order numbering
            // if a single franchise ever needs multiple distinctly-coded zones.
            $table->string('code', 10)->unique()->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
