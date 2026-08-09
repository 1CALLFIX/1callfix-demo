<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A service always belongs to a top-level category (services.category_id,
// already existed). subcategory_id is optional on top of that — some
// categories need further breakdown (e.g. "Electrical" -> "Fan Installation"
// -> the priced service), others don't need a subcategory layer at all.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('subcategory_id')->nullable()->after('category_id')
                ->constrained('service_subcategories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropForeign(['subcategory_id']);
            $table->dropColumn('subcategory_id');
        });
    }
};
