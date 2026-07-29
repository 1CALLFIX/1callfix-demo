<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('franchises', function (Blueprint $table) {
            // Short, unique, human-readable code used in order numbers, e.g. "NLR" for Nellore.
            $table->string('code', 10)->unique()->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('franchises', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
