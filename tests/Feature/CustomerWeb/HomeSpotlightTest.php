<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\HomeSpotlight\Manage;
use App\Models\HomeSpotlight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-HOME-SPOTLIGHT-001 — admin-curated home collage.
 */
class HomeSpotlightTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private function admin()
    {
        return $this->makeSuperAdmin();
    }

    public function test_admin_saves_slots_and_empty_slots_are_dropped(): void
    {
        [, $service] = $this->makeCategoryAndService();

        Livewire::actingAs($this->admin())->test(Manage::class)
            ->set('spots.1.type', 'service')->set('spots.1.target', (string) $service->id)->set('spots.1.badge', 'New')
            ->set('spots.2.target', '')
            ->call('save');

        $this->assertSame(1, HomeSpotlight::count());
        $row = HomeSpotlight::first();
        $this->assertSame([1, 'service', $service->id, 'New'], [$row->position, $row->target_type, $row->target_id, $row->badge]);
    }

    public function test_switching_type_clears_the_target_and_a_category_can_be_saved(): void
    {
        [$category] = $this->makeCategoryAndService();

        Livewire::actingAs($this->admin())->test(Manage::class)
            ->set('spots.3.target', '999')
            ->set('spots.3.type', 'category')
            ->assertSet('spots.3.target', '')
            ->set('spots.3.target', (string) $category->id)
            ->call('save');

        $this->assertDatabaseHas('home_spotlights', ['position' => 3, 'target_type' => 'category', 'target_id' => $category->id]);
    }

    public function test_home_shows_curated_tiles_first_with_badge_and_links(): void
    {
        [, $service] = $this->makeCategoryAndService();
        $service->update(['name' => 'Spotlit Cooler', 'cover_image' => 'https://example.test/a.jpg']);
        HomeSpotlight::create(['position' => 1, 'target_type' => 'service', 'target_id' => $service->id, 'badge' => 'Just launched']);
        [$category] = $this->makeCategoryAndService();
        HomeSpotlight::create(['position' => 2, 'target_type' => 'category', 'target_id' => $category->id]);

        $html = $this->get(route('customer.home'))->assertOk()->getContent();

        $this->assertStringContainsString('Spotlit Cooler', $html);
        $this->assertStringContainsString('Just launched', $html);
        $this->assertStringContainsString(route('customer.services.show', $service), $html);
        $this->assertStringContainsString(route('customer.categories.show', $category), $html);
    }

    public function test_inactive_slot_is_not_shown_in_the_collage(): void
    {
        [, $hidden] = $this->makeCategoryAndService();
        $hidden->update(['name' => 'Hidden Cooler', 'cover_image' => 'https://example.test/b.jpg']);
        HomeSpotlight::create(['position' => 1, 'target_type' => 'service', 'target_id' => $hidden->id, 'is_active' => false]);

        // Four active slots => no automatic top-up that could re-introduce it.
        foreach (range(2, 5) as $pos) {
            [, $svc] = $this->makeCategoryAndService();
            $svc->update(['name' => "Shown Cooler $pos", 'cover_image' => "https://example.test/s$pos.jpg"]);
            HomeSpotlight::create(['position' => $pos, 'target_type' => 'service', 'target_id' => $svc->id]);
        }

        $html = $this->get(route('customer.home'))->assertOk()->getContent();
        $collage = substr($html, strpos($html, 'aria-label="Featured services"'));
        $collage = substr($collage, 0, strpos($collage, '</section>'));

        $this->assertStringContainsString('Shown Cooler 2', $collage);
        $this->assertStringNotContainsString('Hidden Cooler', $collage);
    }
}
