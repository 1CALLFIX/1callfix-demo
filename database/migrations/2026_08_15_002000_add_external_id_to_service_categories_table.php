<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Catalog Import capability (mission Phase 14 input). The existing
 * import flow (built before this migration) reused a source file's `id`
 * column as this table's real primary key directly, specifically to keep
 * cross-sheet references (subcategories.category_id, services.category_id)
 * lined up with Glover's real historical `1.8.12 categories IMP.xlsx`
 * format. That's real PK-reuse risk (a future import could collide with an
 * unrelated real row, or force IDs into a range nothing else expects).
 * `external_id` decouples the two: it's the SOURCE system's identity
 * (whatever a historical `id` column or an explicit `external_id` column
 * says), matched on for idempotent create/update, while the real `id`
 * column stays a normal auto-increment nothing external ever dictates.
 * Nullable (admin-created rows never had a source), unique (a real DB
 * guarantee against duplicate re-import, not just an app-level check).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            $table->string('external_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            $table->dropColumn('external_id');
        });
    }
};
