<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// F3: cities get a clean, admin-editable slug for the /{city} public URL.
// Nullable + unique: rows created later get one from the City model; the
// backfill below gives every existing city a clean slug ("Nellore" -> "nellore"),
// adding -2, -3 only on a collision.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->string('slug', 120)->nullable()->after('name');
        });

        $taken = [];
        foreach (DB::table('cities')->orderBy('id')->get(['id', 'name']) as $city) {
            $base = Str::slug((string) $city->name) ?: 'city';
            $slug = $base;
            for ($n = 2; in_array($slug, $taken, true); $n++) {
                $slug = $base.'-'.$n;
            }
            $taken[] = $slug;
            DB::table('cities')->where('id', $city->id)->update(['slug' => $slug]);
        }

        Schema::table('cities', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
