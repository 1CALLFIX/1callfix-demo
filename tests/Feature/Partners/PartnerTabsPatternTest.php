<?php

namespace Tests\Feature\Partners;

use Tests\TestCase;

/**
 * Owner 2026-11-26: "if all the apps follow the same pattern it is easier to find any issue". Every partner app's
 * work screen uses the one shared tab (x-partner.tab): big, coloured, icon, count badge, and 🕘 for past work.
 * A new partner app's work screen goes on SCREENS. A hand-made tab row fails here.
 */
class PartnerTabsPatternTest extends TestCase
{
    private const SCREENS = [
        'service partner Jobs' => 'resources/views/components/provider/jobs-tabs.blade.php',
        'service partner Job history' => 'resources/views/livewire/provider/history.blade.php',
        'shop / restaurant Orders' => 'resources/views/livewire/seller/orders.blade.php',
        'hotel Bookings' => 'resources/views/livewire/hotel/bookings.blade.php',
        'function hall Bookings' => 'resources/views/livewire/venue/bookings.blade.php',
        'house owner Visits' => 'resources/views/livewire/venue/visits.blade.php',
    ];

    public function test_every_partner_work_screen_uses_the_shared_tab_with_a_past_work_tab(): void
    {
        foreach (self::SCREENS as $name => $path) {
            $view = file_get_contents(base_path($path));
            // Job history is reached through the Jobs tabs it includes.
            $usesTab = str_contains($view, '<x-partner.tab') || str_contains($view, '<x-provider.jobs-tabs');

            $this->assertTrue($usesTab, "{$name} must use the shared <x-partner.tab> ({$path}).");
            $this->assertStringNotContainsString('role="tab"', $view, "{$name} has a hand-made tab; use <x-partner.tab> ({$path}).");
        }
    }

    public function test_the_past_work_tab_is_always_the_green_clock(): void
    {
        foreach (['provider/jobs-tabs', 'seller/orders', 'hotel/bookings', 'venue/bookings', 'venue/visits'] as $screen) {
            $path = str_starts_with($screen, 'provider/') ? "resources/views/components/{$screen}.blade.php" : "resources/views/livewire/{$screen}.blade.php";
            $this->assertMatchesRegularExpression("/'emerald', '🕘'|color=\"emerald\" icon=\"🕘\"/u", file_get_contents(base_path($path)), "{$screen}: past work must be the green 🕘 tab.");
        }
    }

    public function test_the_shared_tab_renders_big_with_its_colour_icon_and_count(): void
    {
        $button = $this->blade('<x-partner.tab :active="true" color="emerald" icon="🕘" :count="3">Past</x-partner.tab>');
        $button->assertSee('data-partner-tab', false);
        $button->assertSee('min-h-11', false);
        $button->assertSee('bg-emerald-600', false);
        $button->assertSee('aria-selected="true"', false);
        $button->assertSee('🕘');
        $button->assertSee('3');

        $link = $this->blade('<x-partner.tab href="/provider/history" color="emerald">Job history</x-partner.tab>');
        $link->assertSee('href="/provider/history"', false);
        $link->assertSee('wire:navigate', false);
        $link->assertDontSee('aria-current', false);
    }
}
