<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-11-14: arrange the AC services as choices.
 *  - Split AC Service: General / Jet pump / Foam + jet pump / Chemical wash / Full service (the 5 existing services).
 *  - Window AC Service: Foam (the existing ₹1,199 service) / Chemical wash (new service, ₹1,599).
 * Found by slug; anything missing is skipped, and running it twice changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $find = fn (string $slug) => DB::table('services')->where('slug', $slug)->whereNull('deleted_at')->first();

        $general = $find('split-ac-general-service');
        if ($general && $general->choice_label === null) {
            DB::table('services')->where('id', $general->id)
                ->update(['choice_group_name' => 'Split AC Service', 'choice_label' => 'General service', 'updated_at' => now()]);

            foreach ([
                'split-ac-jet-service' => 'Jet pump service',
                'split-ac-foam-service' => 'Foam + jet pump service',
                'split-ac-chemical-wash' => 'Chemical wash',
                'split-ac-full-service' => 'Full service',
            ] as $slug => $label) {
                $member = $find($slug);
                if ($member && $member->choice_label === null && (int) $member->subcategory_id === (int) $general->subcategory_id) {
                    DB::table('services')->where('id', $member->id)
                        ->update(['choice_of' => $general->id, 'choice_label' => $label, 'updated_at' => now()]);
                }
            }
        }

        $window = $find('window-ac-service');
        if ($window && $window->choice_label === null) {
            DB::table('services')->where('id', $window->id)
                ->update(['choice_group_name' => 'Window AC Service', 'choice_label' => 'Foam service', 'updated_at' => now()]);

            $slug = 'window-ac-chemical-wash';
            $taken = fn (string $s) => DB::table('services')->where('slug', $s)->exists()
                || DB::table('slug_redirects')->where('old_slug', $s)->exists();
            if (! $taken($slug)) {
                $row = (array) $window;
                unset($row['id']);
                DB::table('services')->insert(array_merge($row, [
                    'name' => 'Window AC Chemical Wash',
                    'slug' => $slug,
                    'external_id' => null,
                    'base_price' => 1599,
                    'discount_price' => null,
                    'choice_of' => $window->id,
                    'choice_group_name' => null,
                    'choice_label' => 'Chemical wash',
                    'sort_order' => (int) $window->sort_order + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }

    /** Data only: the column drop in the previous migration removes the grouping; the new service is kept. */
    public function down(): void {}
};
