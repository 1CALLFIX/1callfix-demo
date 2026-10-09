<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the About, Contact and Refund policy pages as empty DRAFTS so the owner can write the content in
 * Admin → CMS and switch them on. Inactive pages 404 publicly, so nothing empty is ever shown. A page that already
 * exists under the same slug is left completely alone.
 */
return new class extends Migration
{
    private const PAGES = [
        ['slug' => 'about', 'title' => 'About us', 'footer_order' => 10],
        ['slug' => 'contact', 'title' => 'Contact us', 'footer_order' => 20],
        ['slug' => 'refund-policy', 'title' => 'Refund policy', 'footer_order' => 30],
    ];

    public function up(): void
    {
        foreach (self::PAGES as $page) {
            if (DB::table('content_pages')->where('slug', $page['slug'])->exists()) {
                continue;
            }

            DB::table('content_pages')->insert($page + [
                'content' => null,
                'is_active' => false,
                'show_in_footer' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Only remove a draft nobody has written yet; never delete content the owner added.
        DB::table('content_pages')
            ->whereIn('slug', array_column(self::PAGES, 'slug'))
            ->where('is_active', false)
            ->where(fn ($q) => $q->whereNull('content')->orWhere('content', ''))
            ->delete();
    }
};
