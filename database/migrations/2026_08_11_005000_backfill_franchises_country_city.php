<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Step 2 of 3. Find-or-create a Country/City row for every distinct
// franchises.country/city string, then point the new FK columns at them.
//
// Matching is an exact string comparison — "Nellore" and "nellore" would
// create two City rows. Not normalized on purpose: silently coercing case
// is a data decision, not a technical one, and today's production data is
// small enough (one real franchise) to verify by hand after this runs
// rather than guess at a normalization rule up front.
//
// `code` / `currency_code` / `default_timezone` are backfill-only
// assumptions (India / INR / IST) for whatever country strings exist
// today — correct for current data, not a general-purpose guess, and
// confined to this one-time migration rather than becoming a pattern used
// elsewhere.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('franchises')->select('id', 'country', 'city')->get()->each(function ($franchise) {
            $countryId = DB::table('countries')->where('name', $franchise->country)->value('id');

            if (! $countryId) {
                $countryId = DB::table('countries')->insertGetId([
                    'name' => $franchise->country,
                    'code' => strtoupper(substr($franchise->country, 0, 2)),
                    'currency_code' => 'INR',
                    'default_timezone' => 'Asia/Kolkata',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $cityId = DB::table('cities')
                ->where('country_id', $countryId)
                ->where('name', $franchise->city)
                ->value('id');

            if (! $cityId) {
                $cityId = DB::table('cities')->insertGetId([
                    'country_id' => $countryId,
                    'name' => $franchise->city,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('franchises')->where('id', $franchise->id)->update([
                'country_id' => $countryId,
                'city_id' => $cityId,
            ]);
        });
    }

    public function down(): void
    {
        // Deliberately a no-op: reversing this would mean deleting Country/
        // City rows that may have gained other references (zones, other
        // franchises) since this ran. Rolling back to step 1's state means
        // just nulling the FK columns, which step 1's own down() doesn't do
        // either — if this needs undoing, do it by hand after inspecting
        // what's been created since.
    }
};
