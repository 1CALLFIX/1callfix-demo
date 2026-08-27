<?php

namespace Tests\Feature\CustomerWeb;

use App\Models\Badge;
use App\Models\BadgeAssignment;
use App\Models\Banner;
use App\Models\FlashSale;
use App\Models\FlashSaleTarget;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceOption;
use App\Models\ServiceOptionGroup;
use App\Models\ServiceSubcategory;
use App\Services\Qa\QaCleaner;
use App\Services\Qa\QaManifest;
use App\Services\Qa\QaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The demo/QA dataset the customer app is developed and demonstrated against
 * (Phase C).
 *
 * Two things are under test, and the second matters more than the first:
 *
 *  1. The seeded catalog actually exercises the features — a navigable
 *     taxonomy, priced options, badges in both states, banners in both slots,
 *     a live sale and a dead one, reviews.
 *  2. `qa:clean` removes ALL of it and leaves nothing behind. A demo dataset
 *     that cannot be fully reversed is not a demo dataset, it is pollution —
 *     and the tables Phase C added to the seeder (option groups, options,
 *     badge assignments, flash sales, targets, reviews) are exactly the ones
 *     a cleaner written before them would have missed.
 */
class DemoDatasetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The manifest is written to the `local` disk. Faking it keeps a real
     * storage/app/qa-seed-manifest.json from being created — or, worse, an
     * existing one from being overwritten — while a test runs.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_the_seeded_catalog_exercises_the_discovery_features(): void
    {
        (new QaSeeder)->run('small');

        $categories = ServiceCategory::where('module', 'service')->get();

        $this->assertGreaterThanOrEqual(5, $categories->count(), 'The demo catalog needs a real taxonomy to navigate.');
        $this->assertTrue($categories->contains(fn ($c) => ! $c->is_active), 'An inactive category is a required negative case.');
        $this->assertGreaterThanOrEqual(5, ServiceSubcategory::count());
        $this->assertGreaterThanOrEqual(30, Service::count());

        $services = Service::all();
        $this->assertTrue($services->contains(fn ($s) => ! $s->is_active), 'An inactive service is a required negative case.');
        $this->assertTrue($services->contains(fn ($s) => $s->discount_price !== null), 'Some services must carry a discount.');
        $this->assertTrue($services->contains(fn ($s) => $s->discount_price === null), 'Some services must not.');
        $this->assertTrue($services->contains(fn ($s) => $s->price_type === 'quote_on_inspection'));
        $this->assertTrue($services->contains(fn ($s) => $s->cover_image === null), 'The no-image card path must appear in the demo data.');

        $this->assertGreaterThanOrEqual(10, ServiceOptionGroup::count());
        $this->assertGreaterThanOrEqual(30, ServiceOption::count());
        $this->assertTrue(ServiceOptionGroup::where('is_required', true)->exists());
        $this->assertTrue(ServiceOptionGroup::where('allow_multiple', true)->exists());
        $this->assertTrue(
            $services->contains(fn ($s) => $s->optionGroups()->count() === 0),
            'A service with no options at all must appear too — it renders differently.',
        );
    }

    /**
     * The automatic NEW badge is evaluated against created_at. If every seeded
     * service were created "now" the entire catalog would be NEW, which would
     * demonstrate nothing.
     */
    public function test_the_seeded_catalog_has_both_new_and_established_services(): void
    {
        (new QaSeeder)->run('small');

        $withinDays = (int) (Badge::where('key', 'new')->value('rule_config')['within_days'] ?? 14);
        $cutoff = now()->subDays($withinDays);

        $services = Service::all();

        $this->assertTrue($services->contains(fn ($s) => $s->created_at->greaterThan($cutoff)), 'Some services must be new enough to carry the NEW badge.');
        $this->assertTrue($services->contains(fn ($s) => $s->created_at->lessThan($cutoff)), 'Most services must be old enough not to.');
    }

    public function test_the_seeded_badges_cover_visible_expired_and_zone_scoped_assignments(): void
    {
        (new QaSeeder)->run('small');

        $this->assertGreaterThan(0, BadgeAssignment::currentlyVisible()->count(), 'Some badges must be live.');
        $this->assertGreaterThan(0, BadgeAssignment::where('expires_at', '<', now())->count(), 'An expired assignment is a required negative case.');
        $this->assertGreaterThan(0, BadgeAssignment::where('scope_type', 'zone')->count(), 'A zone-scoped assignment is a required negative case.');
    }

    public function test_the_seeded_banners_fill_both_slots_and_include_every_hidden_case(): void
    {
        (new QaSeeder)->run('small');

        $this->assertGreaterThan(1, Banner::placement('top')->currentlyLive()->count(), 'The hero carousel needs more than one slide.');
        $this->assertGreaterThan(1, Banner::placement('mid')->currentlyLive()->count(), 'The mid-page slider needs its own slides.');

        $this->assertTrue(Banner::where('is_active', false)->exists());
        $this->assertTrue(Banner::where('expires_at', '<', now())->exists());
        $this->assertTrue(Banner::where('starts_at', '>', now())->exists());
        $this->assertTrue(Banner::whereNotNull('zone_id')->exists());
        $this->assertTrue(Banner::whereNotNull('franchise_id')->exists());
        $this->assertTrue(Banner::where('module', '!=', 'service')->whereNotNull('module')->exists());
    }

    public function test_the_seeded_flash_sales_include_exactly_one_that_is_live(): void
    {
        (new QaSeeder)->run('small');

        $sales = FlashSale::all();

        $this->assertGreaterThanOrEqual(3, $sales->count());
        $this->assertCount(1, $sales->filter(fn (FlashSale $s) => $s->isCurrentlyActive()), 'Exactly one sale should be live, so Offers has content without being noise.');
        $this->assertTrue($sales->contains(fn (FlashSale $s) => $s->status === 'draft'));
        $this->assertTrue($sales->contains(fn (FlashSale $s) => $s->status === 'completed'));
    }

    public function test_the_seeded_reviews_give_some_services_a_rating_and_leave_others_unrated(): void
    {
        (new QaSeeder)->run('small');

        $this->assertGreaterThan(0, Review::count(), 'Without reviews no service in the demo data would show a rating.');

        $ratedServiceIds = Review::query()
            ->join('bookings', 'reviews.booking_id', '=', 'bookings.id')
            ->distinct()->pluck('bookings.service_id');

        $this->assertGreaterThan(0, $ratedServiceIds->count());
        $this->assertGreaterThan(
            $ratedServiceIds->count(),
            Service::where('is_active', true)->count(),
            'Some services must remain unrated — the unrated card path has to appear too.',
        );
    }

    // ==================== Reversibility ====================

    public function test_qa_clean_removes_every_catalog_row_the_seeder_created(): void
    {
        (new QaSeeder)->run('small');

        $this->assertGreaterThan(0, ServiceOptionGroup::count());
        $this->assertGreaterThan(0, BadgeAssignment::count());
        $this->assertGreaterThan(0, FlashSale::count());
        $this->assertGreaterThan(0, Review::count());

        (new QaCleaner)->run();

        // Nothing else in this test created any of these, so anything left is
        // residue the cleaner does not know about.
        foreach ([
            ServiceCategory::class, ServiceSubcategory::class, Service::class,
            ServiceOptionGroup::class, ServiceOption::class,
            BadgeAssignment::class, Banner::class,
            FlashSale::class, FlashSaleTarget::class, Review::class,
        ] as $model) {
            $this->assertSame(0, $model::count(), $model.' rows survived qa:clean.');
        }

        $this->assertFalse(QaManifest::exists(), 'The manifest must be removed so the next seed run can track its own data.');
    }

    /**
     * Badge DEFINITIONS are seeded by migration and are real application
     * configuration, not QA data. The cleaner must not take them with it, or
     * the next seed run has no badges to assign and the catalog silently
     * loses its NEW badge.
     */
    public function test_qa_clean_leaves_the_badge_definitions_alone(): void
    {
        $before = Badge::count();
        $this->assertGreaterThan(0, $before);

        (new QaSeeder)->run('small');
        (new QaCleaner)->run();

        $this->assertSame($before, Badge::count());
    }

    public function test_the_seeder_refuses_to_run_twice_without_a_clean(): void
    {
        // The manifest is what makes cleanup exact; a second run over the top
        // of an existing one would leave the first run's rows untracked.
        (new QaSeeder)->run('small');

        $this->artisan('qa:seed', ['--scale' => 'small', '--yes' => true])
            ->expectsOutputToContain('manifest already exists')
            ->assertExitCode(1);
    }
}
