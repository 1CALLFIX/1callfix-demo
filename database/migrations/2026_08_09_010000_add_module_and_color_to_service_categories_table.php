<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `module` is the equivalent of the reference panel's "Vendor Type" column —
// which vertical (Service / Parcel / Food / …) a category belongs to. Every
// existing category predates the super-app split and is a home-services one,
// so the 'service' default backfills them correctly with no data migration.
//
// Deliberately a plain string, not a DB enum: adding a vertical is a routine
// event here (Car Rental was added mid-project), and altering a MySQL enum
// means rewriting the table. The allowed values live in App\Support\Modules
// and are enforced by validation instead.
//
// `color` replaces the image-URL field on the category form — the admin
// picks a background tint for the category's icon chip rather than hosting
// an image. The existing `image` column stays: Glover's import files carry
// photo URLs and the list still renders them when present.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            $table->string('module')->default('service')->after('parent_id')->index();
            $table->string('color', 7)->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            $table->dropIndex(['module']);
            $table->dropColumn(['module', 'color']);
        });
    }
};
