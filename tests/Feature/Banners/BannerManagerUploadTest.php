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
 * The manual upload path through the admin Banner Manager — the real-world
 * exercise of create / edit / delete that adding banners by hand (rather than
 * seeding) depends on. Also pins the new soft-delete behaviour: a deleted
 * banner keeps its row (for audit / restore) and keeps its image file, and
 * never renders on the storefront.
 */
class BannerManagerUploadTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function admin()
    {
        return $this->makeUserWithPermission('banners.manage', 'global');
    }

    public function test_creating_a_banner_stores_the_uploaded_image_on_the_public_disk(): void
    {
        Livewire::actingAs($this->admin())->test(Manage::class)
            ->set('title', 'Hero Launch')
            ->set('placement', 'top')
            ->set('imageFile', UploadedFile::fake()->image('hero.jpg', 1600, 560))
            ->call('save')
            ->assertHasNoErrors();

        $banner = Banner::where('title', 'Hero Launch')->sole();

        $this->assertStringStartsWith('banners/', $banner->image);
        Storage::disk('public')->assertExists($banner->image);
    }

    public function test_editing_swaps_the_image_and_removes_the_old_file(): void
    {
        Livewire::actingAs($this->admin())->test(Manage::class)
            ->set('title', 'Editable')
            ->set('placement', 'mid')
            ->set('imageFile', UploadedFile::fake()->image('one.jpg'))
            ->call('save')
            ->assertHasNoErrors();

        $banner = Banner::where('title', 'Editable')->sole();
        $old = $banner->image;

        Livewire::actingAs($this->admin())->test(Manage::class)
            ->call('edit', $banner->id)
            ->set('editImageFile', UploadedFile::fake()->image('two.jpg'))
            ->call('update')
            ->assertHasNoErrors();

        $new = $banner->fresh()->image;

        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertExists($new);
        Storage::disk('public')->assertMissing($old);
    }

    public function test_deleting_a_banner_soft_deletes_it_and_keeps_the_image(): void
    {
        Livewire::actingAs($this->admin())->test(Manage::class)
            ->set('title', 'Doomed')
            ->set('placement', 'top')
            ->set('imageFile', UploadedFile::fake()->image('x.jpg'))
            ->call('save');

        $banner = Banner::where('title', 'Doomed')->sole();
        $image = $banner->image;

        Livewire::actingAs($this->admin())->test(Manage::class)
            ->call('confirmDelete', $banner->id)
            ->call('deleteBanner');

        $this->assertSoftDeleted('banners', ['id' => $banner->id]);
        $this->assertNull(Banner::find($banner->id));
        $this->assertNotNull(Banner::withTrashed()->find($banner->id));
        Storage::disk('public')->assertExists($image);
    }

    public function test_a_soft_deleted_banner_never_renders_on_the_homepage(): void
    {
        $banner = Banner::create([
            'title' => 'Gone From Home',
            'image' => 'banners/x.png',
            'placement' => 'top',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $banner->delete();

        Livewire::test(Home::class)->assertDontSee('Gone From Home');
    }

    public function test_reorder_swaps_sort_order_within_a_slot(): void
    {
        $admin = $this->admin();

        foreach (['First', 'Second'] as $title) {
            Livewire::actingAs($admin)->test(Manage::class)
                ->set('title', $title)
                ->set('placement', 'top')
                ->set('imageFile', UploadedFile::fake()->image($title.'.jpg'))
                ->call('save')
                ->assertHasNoErrors();
        }

        $first = Banner::where('title', 'First')->sole();
        $second = Banner::where('title', 'Second')->sole();
        $this->assertLessThan($second->sort_order, $first->sort_order);

        Livewire::actingAs($admin)->test(Manage::class)->call('moveDown', $first->id);

        $this->assertGreaterThan($second->fresh()->sort_order, $first->fresh()->sort_order);
    }
}
