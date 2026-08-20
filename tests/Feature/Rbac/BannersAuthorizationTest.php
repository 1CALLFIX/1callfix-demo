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

    /**
     * Since Phase 11 (Admin Menu/Settings completeness audit) added a
     * mount()-level banners.manage gate to this whole screen (it never had
     * a separate .view permission), an actor with zero permissions is now
     * denied at mount() with a 403 — never reaches save() to trigger its
     * own action-level check below.
     */
    public function test_user_without_permission_cannot_view_or_create_banner(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(Manage::class)->assertForbidden();

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

        Livewire::actingAs($actor)->test(Manage::class)->assertForbidden();

        $this->assertDatabaseHas('banners', ['id' => $banner->id]);
    }

    /**
     * Admin Command Center completion session, Growth Command Center phase
     * (2026-08-20) — render() never applied row-level scope at all (write
     * actions above were already correctly scoped, this closes the
     * read-only list-visibility gap). A franchise-scoped grant must see
     * its own franchise's targeted banners, a platform-wide (null
     * franchise_id) banner since that runs everywhere too, but not another
     * franchise's specifically-targeted banner.
     */
    public function test_franchise_scoped_grant_sees_own_franchise_and_global_banners_but_not_another_franchises(): void
    {
        $mine = $this->makeFranchise();
        $other = $this->makeFranchise();

        Banner::create(['title' => 'Mine', 'image' => 'banners/mine.png', 'placement' => 'top', 'is_active' => true, 'sort_order' => 1, 'franchise_id' => $mine->id]);
        Banner::create(['title' => 'Other Franchise', 'image' => 'banners/other.png', 'placement' => 'top', 'is_active' => true, 'sort_order' => 1, 'franchise_id' => $other->id]);
        Banner::create(['title' => 'Global', 'image' => 'banners/global.png', 'placement' => 'top', 'is_active' => true, 'sort_order' => 1]);

        $actor = $this->makeUserWithPermission('banners.manage', 'franchise', $mine->id);

        Livewire::actingAs($actor)->test(Manage::class)
            ->assertSee('Mine')
            ->assertSee('Global')
            ->assertDontSee('Other Franchise');
    }

    public function test_global_scoped_grant_sees_every_franchises_banners(): void
    {
        $a = $this->makeFranchise();
        $b = $this->makeFranchise();
        Banner::create(['title' => 'Franchise A Banner', 'image' => 'banners/a.png', 'placement' => 'top', 'is_active' => true, 'sort_order' => 1, 'franchise_id' => $a->id]);
        Banner::create(['title' => 'Franchise B Banner', 'image' => 'banners/b.png', 'placement' => 'top', 'is_active' => true, 'sort_order' => 1, 'franchise_id' => $b->id]);

        $actor = $this->makeUserWithPermission('banners.manage', 'global');

        Livewire::actingAs($actor)->test(Manage::class)
            ->assertSee('Franchise A Banner')
            ->assertSee('Franchise B Banner');
    }
}
