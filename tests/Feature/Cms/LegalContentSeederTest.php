<?php

namespace Tests\Feature\Cms;

use App\Models\ContentPage;
use Database\Seeders\LegalContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Coverage for LegalContentSeeder (closes KNOWN_RISKS_AND_DECISIONS.md
 * item 17 — real legal content, supplied by the business, never existed).
 * Runs against the real source files in storage/legal-content/ (present
 * in this checkout) rather than fixtures, since the whole point of this
 * seeder is reading and upserting that exact real content unmodified.
 */
class LegalContentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_both_real_pages_active_and_readable_via_the_public_api(): void
    {
        $this->seed(LegalContentSeeder::class);

        $this->assertDatabaseCount('content_pages', 2);

        $privacy = ContentPage::where('slug', 'privacy-policy')->first();
        $terms = ContentPage::where('slug', 'terms-and-conditions')->first();

        $this->assertNotNull($privacy);
        $this->assertTrue($privacy->is_active);
        $this->assertStringContainsString('1CALLFix', $privacy->content);

        $this->assertNotNull($terms);
        $this->assertTrue($terms->is_active);
        $this->assertStringContainsString('1CALLFix', $terms->content);

        $this->getJson('/api/pages/privacy-policy')->assertOk()->assertJsonPath('title', 'Privacy Policy');
        $this->getJson('/api/pages/terms-and-conditions')->assertOk()->assertJsonPath('title', 'Terms of Use');
    }

    public function test_is_idempotent_and_never_duplicates_a_row_on_re_run(): void
    {
        $this->seed(LegalContentSeeder::class);
        $this->seed(LegalContentSeeder::class);

        $this->assertDatabaseCount('content_pages', 2);
    }

    public function test_skips_a_page_without_seeding_a_placeholder_when_its_source_file_is_missing(): void
    {
        $missingPath = storage_path('legal-content/privacy-policy.md');
        $backupPath = $missingPath.'.test-backup';

        File::move($missingPath, $backupPath);

        try {
            $this->seed(LegalContentSeeder::class);

            $this->assertDatabaseCount('content_pages', 1);
            $this->assertDatabaseMissing('content_pages', ['slug' => 'privacy-policy']);
            $this->assertDatabaseHas('content_pages', ['slug' => 'terms-and-conditions']);
        } finally {
            File::move($backupPath, $missingPath);
        }
    }
}
