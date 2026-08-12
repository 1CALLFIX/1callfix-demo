<?php

namespace Tests\Feature\Rbac;

use App\Livewire\Banners\Manage;
use App\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * banners.manage was seeded but never checked anywhere (Slice 2 finding
 * #4). A banner's franchise_id is a targeting axis, not just ownership —
 * blank means "runs everywhere". A franchise-scoped grant can manage
 * banners targeted at its own franchise; only a global grant can create or
 * touch a platform-wide one.
 */
class BannersAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_user_without_permission_cannot_create_banner(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('title', 'Blocked Banner')
            ->set('imageFile', UploadedFile::fake()->image('banner.png'))
            ->set('placement', 'top')
            ->call('save')
            ->assertHasErrors(['permission']);

        $this->assertDatabaseMissing('banners', ['title' => 'Blocked Banner']);
    }

    public function test_franchise_scoped_grant_can_create_banner_for_that_franchise(): void
    {
        $franchise = $this->makeFranchise();
        $actor = $this->makeUserWithPermission('banners.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('title', 'Franchise Banner')
            ->set('imageFile', UploadedFile::fake()->image('banner.png'))
            ->set('placement', 'top')
            ->set('franchiseId', (string) $franchise->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('banners', ['title' => 'Franchise Banner', 'franchise_id' => $franchise->id]);
    }

    public function test_franchise_scoped_grant_cannot_create_platform_wide_banner(): void
    {
        // Blank franchiseId = runs everywhere; only a global grant may do this.
        $franchise = $this->makeFranchise();
        $actor = $this->makeUserWithPermission('banners.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('title', 'Platform Wide Banner')
            ->set('imageFile', UploadedFile::fake()->image('banner.png'))
            ->set('placement', 'top')
            // franchiseId left blank
            ->call('save')
            ->assertHasErrors(['permission']);

        $this->assertDatabaseMissing('banners', ['title' => 'Platform Wide Banner']);
    }

    public function test_franchise_scoped_grant_cannot_create_banner_for_a_different_franchise(): void
    {
        $ownFranchise = $this->makeFranchise();
        $otherFranchise = $this->makeFranchise();
        $actor = $this->makeUserWithPermission('banners.manage', 'franchise', $ownFranchise->id);

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('title', 'Cross Franchise Banner')
            ->set('imageFile', UploadedFile::fake()->image('banner.png'))
            ->set('placement', 'top')
            ->set('franchiseId', (string) $otherFranchise->id)
            ->call('save')
            ->assertHasErrors(['permission']);
    }

    public function test_global_scoped_grant_can_create_platform_wide_banner(): void
    {
        $actor = $this->makeUserWithPermission('banners.manage', 'global');

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('title', 'Global Banner')
            ->set('imageFile', UploadedFile::fake()->image('banner.png'))
            ->set('placement', 'top')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('banners', ['title' => 'Global Banner', 'franchise_id' => null]);
    }

    public function test_delete_denied_without_permission(): void
    {
        $banner = Banner::create([
            'title' => 'Existing Banner', 'image' => 'banners/x.png',
            'placement' => 'top', 'is_active' => true, 'sort_order' => 1,
        ]);
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('confirmingDeleteId', $banner->id)
            ->call('deleteBanner')
            ->assertHasErrors(['permission']);

        $this->assertDatabaseHas('banners', ['id' => $banner->id]);
    }
}
