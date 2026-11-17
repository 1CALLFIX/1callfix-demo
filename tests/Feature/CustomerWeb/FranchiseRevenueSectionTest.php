<?php

namespace Tests\Feature\CustomerWeb;

use App\Models\Setting;
use App\Support\InfoPages\FranchisePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Owner 2026-11-17: the "what your city can earn" model on /franchise, all Super Admin figures. */
class FranchiseRevenueSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_figures_add_up(): void
    {
        $m = FranchisePage::revenueModel(FranchisePage::defaults()['streams']);

        $this->assertSame(1718.0, $m['orders']);
        $this->assertSame(1940000.0, $m['gmv']);
        $this->assertSame(231500.0, $m['earn']);
        $this->assertSame(
            [25000.0, 57500.0, 33000.0, 3000.0, 30000.0, 50000.0, 8000.0, 25000.0],
            array_column($m['rows'], 'earn'),
        );
    }

    public function test_the_page_shows_the_section_with_three_buttons_the_total_and_the_estimate_note(): void
    {
        $html = $this->get('/franchise')->assertOk()->getContent();

        $this->assertStringContainsString('data-franchise-revenue', $html);
        $this->assertSame(8, substr_count($html, 'data-franchise-revenue-row'));
        foreach (['Just starting', 'Growing', 'Our target', '₹2,31,500', '₹57,500'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringContainsString('not a promise of income', $html);
        $this->assertStringContainsString('Royalty 8%', $html, 'the royalty is 8% of the income earned');
        $this->assertStringContainsString('percentage of the total income', $html);
    }

    public function test_no_rows_or_no_buttons_hides_the_section_and_an_emptied_royalty_hides_its_line(): void
    {
        Setting::set(FranchisePage::CONTENT, json_encode(['streams' => []] + FranchisePage::defaults()));
        $this->assertStringNotContainsString('data-franchise-revenue', $this->get('/franchise')->getContent());

        Setting::set(FranchisePage::CONTENT, json_encode(['rev_royalty_pct' => ''] + FranchisePage::defaults()));
        $this->assertStringNotContainsString('Royalty 8%', $this->get('/franchise')->getContent());

        Setting::set(FranchisePage::CONTENT, json_encode(['rev_scen_low_pct' => '', 'rev_scen_mid_pct' => '', 'rev_scen_high_pct' => ''] + FranchisePage::defaults()));
        $this->assertStringNotContainsString('data-franchise-revenue', $this->get('/franchise')->getContent());
    }

    public function test_only_numbers_can_be_saved_in_the_revenue_rows_and_buttons(): void
    {
        $c = FranchisePage::defaults();
        $c['streams'][0]['orders'] = 'lots';
        $c['streams'][1]['pct'] = '150';
        $c['rev_scen_high_pct'] = '250';
        $c['rev_cost'] = '75,000';
        $errors = FranchisePage::validate($c);

        $this->assertArrayHasKey('streams.0.orders', $errors);
        $this->assertArrayHasKey('streams.1.pct', $errors);
        $this->assertArrayHasKey('rev_scen_high_pct', $errors);
        $this->assertArrayHasKey('rev_cost', $errors);
        $this->assertSame([], FranchisePage::validate(FranchisePage::defaults()));
    }

    public function test_the_migration_swaps_only_our_original_wording(): void
    {
        $migration = require base_path('database/migrations/2026_11_17_120000_franchise_page_practical_costs.php');
        $old = ['alone_title' => 'What it costs to start each business on your own', 'alone_total' => '₹66–96 Lakhs'];

        Setting::set(FranchisePage::CONTENT, json_encode($old + FranchisePage::defaults()));
        $migration->up();
        $this->assertSame('₹30–59 Lakhs', FranchisePage::content()['alone_total']);
        $this->assertSame('What it takes to start each business on your own', FranchisePage::content()['alone_title']);

        Setting::set(FranchisePage::CONTENT, json_encode(['alone_total' => '₹1 crore'] + FranchisePage::defaults()));
        $migration->up();
        $this->assertSame('₹1 crore', FranchisePage::content()['alone_total'], 'a figure the Super Admin wrote stays');
    }
}
