<?php

namespace Tests\Feature\Admin;

use App\Livewire\SearchBox\Manage;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-SEARCHBOX-ADMIN-001 — admin-controlled header search box.
 */
class SearchBoxSettingsTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    /** @return array<int, string> the placeholder examples rendered for the header's first search box */
    private function examples(string $html): array
    {
        preg_match('/data-placeholder-examples="([^"]*)"/', $html, $m);

        return json_decode(html_entity_decode($m[1] ?? '[]'), true) ?: [];
    }

    private function service(string $name): Service
    {
        [, $service] = $this->makeCategoryAndService();
        $service->update(['name' => $name]);

        return $service;
    }

    public function test_defaults_are_unchanged_until_an_admin_saves_something(): void
    {
        $html = Blade::render('<x-customer.header />');

        $this->assertStringContainsString('data-rotate-ms="3000"', $html);
        foreach ($this->examples($html) as $example) {
            $this->assertStringStartsWith("Search for '", $example);
        }
        $this->assertStringNotContainsString('::placeholder', $html);
    }

    public function test_admin_picks_order_size_colour_prefix_and_speed_reach_the_website(): void
    {
        $a = $this->service('Alpha Cooler Care');
        $b = $this->service('Beta Geyser Fix');

        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)
            ->set('picks.1', (string) $b->id)->set('picks.2', (string) $a->id)
            ->set('size', 'large')->set('color', '#1E40AF')->set('prefix', 'Find')->set('seconds', 5)
            ->call('save')->assertHasNoErrors();

        $html = Blade::render('<x-customer.header />');

        $this->assertSame(["Find 'Beta Geyser Fix'", "Find 'Alpha Cooler Care'"], $this->examples($html));
        $this->assertStringContainsString('data-rotate-ms="5000"', $html);
        $this->assertStringContainsString('text-base', $html);
        $this->assertStringContainsString('::placeholder { color: #1e40af;', $html);
    }

    public function test_invalid_colour_and_out_of_range_speed_are_rejected(): void
    {
        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)
            ->set('color', 'red; background:url(x)')->set('seconds', 99)
            ->call('save')->assertHasErrors(['color', 'seconds']);

        $this->assertNull(Setting::get('search_box.text_color'));
    }

    public function test_inactive_services_are_dropped_and_an_empty_list_falls_back_to_automatic(): void
    {
        $off = $this->service('Switched Off Service');
        $off->update(['is_active' => false]);

        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)
            ->set('picks.1', (string) $off->id)->call('save');

        $this->assertSame('', (string) Setting::get('search_box.services'));
        foreach ($this->examples(Blade::render('<x-customer.header />')) as $example) {
            $this->assertStringNotContainsString('Switched Off Service', $example);
        }
    }

    public function test_the_duplicate_blue_search_button_is_gone_from_the_header(): void
    {
        $html = Blade::render('<x-customer.header />');

        // The header's first (pill) search form has only the magnifier icon, no submit button.
        $start = strpos($html, 'data-search-bar');
        $form = substr($html, $start, strpos($html, '</form>', $start) - $start);

        $this->assertStringNotContainsString('type="submit"', $form);
        $this->assertSame(1, substr_count($form, '<svg'), 'a single magnifier icon remains');
    }

    public function test_managing_the_search_box_needs_permission(): void
    {
        Livewire::actingAs($this->makeUserWithNoPermissions())->test(Manage::class)->assertForbidden();
    }
}
