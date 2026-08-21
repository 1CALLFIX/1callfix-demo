<?php

namespace Database\Seeders;

use App\Models\ContentPage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Seeds real legal content into content_pages (KNOWN_RISKS_AND_DECISIONS.md
 * item 17 -- Terms & Conditions/Privacy Policy never existed as real
 * content anywhere in this project's history). Source text lives in
 * storage/legal-content/*.md, supplied directly by the business -- this
 * seeder only reads and upserts it, it never authors or alters the text.
 *
 * Deliberately does NOT fall back to a placeholder if a source file is
 * missing or empty -- skips that page and reports it, since a fabricated
 * legal document would be worse than an honest gap (same discipline the
 * risk-register item this closes was written under).
 *
 * Slugs/format match the existing public API contract exactly
 * (GET /api/pages/{slug} -- see App\Http\Controllers\API\ContentController
 * and tests/Feature/Cms/ContentApiTest.php, whose own fixture already uses
 * slug 'privacy-policy'). Idempotent via updateOrCreate -- safe to re-run
 * after the source text is revised, never creates a duplicate row.
 */
class LegalContentSeeder extends Seeder
{
    /** @var array<int, array{slug: string, title: string, file: string}> */
    private const PAGES = [
        [
            'slug' => 'privacy-policy',
            'title' => 'Privacy Policy',
            'file' => 'legal-content/privacy-policy.md',
        ],
        [
            'slug' => 'terms-and-conditions',
            'title' => 'Terms of Use',
            'file' => 'legal-content/terms-and-conditions.md',
        ],
    ];

    public function run(): void
    {
        foreach (self::PAGES as $page) {
            $path = storage_path($page['file']);

            if (! File::exists($path)) {
                $this->command?->error("Missing source file: {$path} -- skipping '{$page['slug']}', not seeding a placeholder.");

                continue;
            }

            $content = File::get($path);

            if (trim($content) === '') {
                $this->command?->error("Source file is empty: {$path} -- skipping '{$page['slug']}', not seeding a placeholder.");

                continue;
            }

            ContentPage::updateOrCreate(
                ['slug' => $page['slug']],
                [
                    'title' => $page['title'],
                    'content' => $content,
                    'is_active' => true,
                ]
            );

            $this->command?->info("Seeded content_pages.{$page['slug']} ({$page['title']}).");
        }
    }
}
