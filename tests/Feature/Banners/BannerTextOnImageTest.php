<?php

namespace Tests\Feature\Banners;

use App\Livewire\Banners\Manage;
use App\Livewire\Customer\Home;
use App\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-21 (HD module banners): a banner whose picture already carries its own headline is ticked
 * "Words are on the picture", and the site then does not print its title over the bottom of the picture. The title
 * is kept as the link's name for screen readers. Unticked banners keep the title overlay exactly as before.
 */
class BannerTextOnImageTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function banner(string $title, bool $textOnImage): Banner
    {
        return Banner::create([
            'title' => $title, 'text_on_image' => $textOnImage, 'image' => 'banners/'.md5($title).'.jpg',
            'link' => 'https://example.test/offer', 'placement' => 'top', 'is_active' => true, 'sort_order' => 1,
        ]);
    }

    /** The title printed as the visible overlay line (not the slide's aria-label or the screen-reader link name). */
    private function overlayPattern(string $title): string
    {
        return '/<p[^>]*drop-shadow[^>]*>\s*'.preg_quote($title, '/').'\s*<\/p>/';
    }

    public function test_admin_ticks_words_are_on_the_picture_when_adding_and_editing(): void
    {
        $admin = $this->makeUserWithPermission('banners.manage', 'global');

        Livewire::actingAs($admin)->test(Manage::class)
            ->assertSee('Words are on the picture')
            ->set('title', 'Food HD')
            ->set('placement', 'page_food')
            ->set('textOnImage', true)
            ->set('imageFile', UploadedFile::fake()->image('food.jpg', 1920, 640))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('textOnImage', false); // the form resets for the next banner

        $banner = Banner::where('title', 'Food HD')->sole();
        $this->assertTrue($banner->text_on_image);

        Livewire::actingAs($admin)->test(Manage::class)
            ->call('edit', $banner->id)
            ->assertSet('editTextOnImage', true)
            ->set('editTextOnImage', false)
            ->call('update')
            ->assertHasNoErrors();

        $this->assertFalse($banner->fresh()->text_on_image);
    }

    public function test_new_and_existing_banners_default_to_the_title_overlay(): void
    {
        $banner = Banner::create([
            'title' => 'Old banner', 'image' => 'banners/old.jpg', 'placement' => 'top', 'is_active' => true, 'sort_order' => 1,
        ]);

        $this->assertFalse($banner->fresh()->text_on_image);
    }

    public function test_homepage_hides_the_title_overlay_only_for_banners_with_words_on_the_picture(): void
    {
        $this->banner('Designed HD banner', true);
        $this->banner('Plain photo banner', false);

        $html = Livewire::test(Home::class)->html();

        $this->assertDoesNotMatchRegularExpression($this->overlayPattern('Designed HD banner'), $html);
        $this->assertMatchesRegularExpression($this->overlayPattern('Plain photo banner'), $html);
        // Still named for screen readers.
        $this->assertStringContainsString('<span class="sr-only">Designed HD banner</span>', $html);
    }

    public function test_api_tells_the_apps_whether_the_words_are_on_the_picture(): void
    {
        $this->banner('Designed HD banner', true);

        $this->getJson('/api/banners?placement=top')->assertOk()
            ->assertJsonPath('banners.0.title', 'Designed HD banner')
            ->assertJsonPath('banners.0.text_on_image', true);
    }
}
