<?php

namespace Tests\Feature\Cms;

use App\Livewire\Cms\Manage;
use App\Models\PartnerBenefit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * The "Partner benefits" section added to Cms\Manage — the admin CRUD
 * behind the public /coming-soon/partners benefits grid. Same shape as
 * CmsManageTest's FAQ coverage: create + validation, update, toggle,
 * delete, plus the move-up/down reorder and the fixed-icon whitelist.
 */
class PartnerBenefitManageTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    /**
     * The create migration seeds four starter rows. Clear them so each
     * test's sort_order / neighbour assertions are deterministic.
     */
    protected function setUp(): void
    {
        parent::setUp();
        PartnerBenefit::query()->delete();
    }

    private function actor()
    {
        return $this->makeUserWithPermission('cms.manage', 'global');
    }

    public function test_benefit_can_be_created(): void
    {
        Livewire::actingAs($this->actor())->test(Manage::class)
            ->set('benefitIcon', 'wallet')
            ->set('benefitTitle', 'Steady work')
            ->set('benefitDescription', 'Real jobs in your area.')
            ->call('saveBenefit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('partner_benefits', [
            'icon' => 'wallet',
            'title' => 'Steady work',
            'is_active' => 1,
        ]);
    }

    public function test_new_benefit_goes_to_the_end_of_the_order(): void
    {
        PartnerBenefit::create(['icon' => 'clock', 'title' => 'First', 'description' => 'x', 'sort_order' => 7, 'is_active' => true]);

        Livewire::actingAs($this->actor())->test(Manage::class)
            ->set('benefitIcon', 'shield')
            ->set('benefitTitle', 'Second')
            ->set('benefitDescription', 'y')
            ->call('saveBenefit')
            ->assertHasNoErrors();

        $this->assertSame(8, PartnerBenefit::where('title', 'Second')->value('sort_order'));
    }

    public function test_icon_must_be_one_of_the_fixed_set(): void
    {
        Livewire::actingAs($this->actor())->test(Manage::class)
            ->set('benefitIcon', 'skull-and-crossbones')
            ->set('benefitTitle', 'Bad icon')
            ->set('benefitDescription', 'nope')
            ->call('saveBenefit')
            ->assertHasErrors(['benefitIcon']);

        $this->assertDatabaseMissing('partner_benefits', ['title' => 'Bad icon']);
    }

    public function test_title_and_description_are_required(): void
    {
        Livewire::actingAs($this->actor())->test(Manage::class)
            ->set('benefitIcon', 'wallet')
            ->set('benefitTitle', '')
            ->set('benefitDescription', '')
            ->call('saveBenefit')
            ->assertHasErrors(['benefitTitle', 'benefitDescription']);
    }

    public function test_update_benefit_changes_fields(): void
    {
        $benefit = PartnerBenefit::create(['icon' => 'wallet', 'title' => 'Old', 'description' => 'Old body', 'sort_order' => 1, 'is_active' => true]);

        Livewire::actingAs($this->actor())->test(Manage::class)
            ->call('editBenefit', $benefit->id)
            ->set('editBenefitIcon', 'banknotes')
            ->set('editBenefitTitle', 'New title')
            ->set('editBenefitDescription', 'New body')
            ->set('editBenefitIsActive', false)
            ->call('updateBenefit')
            ->assertHasNoErrors();

        $benefit->refresh();
        $this->assertSame('banknotes', $benefit->icon);
        $this->assertSame('New title', $benefit->title);
        $this->assertSame('New body', $benefit->description);
        $this->assertFalse($benefit->is_active);
    }

    public function test_toggle_benefit_active_flips_state(): void
    {
        $benefit = PartnerBenefit::create(['icon' => 'wallet', 'title' => 'T', 'description' => 'd', 'sort_order' => 1, 'is_active' => true]);

        Livewire::actingAs($this->actor())->test(Manage::class)->call('toggleBenefitActive', $benefit->id);

        $this->assertFalse($benefit->fresh()->is_active);
    }

    public function test_move_benefit_swaps_sort_order_with_its_neighbour(): void
    {
        $a = PartnerBenefit::create(['icon' => 'wallet', 'title' => 'A', 'description' => 'd', 'sort_order' => 1, 'is_active' => true]);
        $b = PartnerBenefit::create(['icon' => 'clock', 'title' => 'B', 'description' => 'd', 'sort_order' => 2, 'is_active' => true]);

        Livewire::actingAs($this->actor())->test(Manage::class)->call('moveBenefit', $a->id, 'down');

        $this->assertSame(2, $a->fresh()->sort_order);
        $this->assertSame(1, $b->fresh()->sort_order);
    }

    public function test_move_benefit_at_the_edge_is_a_noop(): void
    {
        $a = PartnerBenefit::create(['icon' => 'wallet', 'title' => 'A', 'description' => 'd', 'sort_order' => 1, 'is_active' => true]);

        Livewire::actingAs($this->actor())->test(Manage::class)->call('moveBenefit', $a->id, 'up');

        $this->assertSame(1, $a->fresh()->sort_order);
    }

    public function test_delete_benefit_removes_it_when_permitted(): void
    {
        $benefit = PartnerBenefit::create(['icon' => 'wallet', 'title' => 'D', 'description' => 'd', 'sort_order' => 1, 'is_active' => true]);

        Livewire::actingAs($this->actor())->test(Manage::class)
            ->set('confirmingDeleteBenefitId', $benefit->id)
            ->call('deleteBenefit');

        $this->assertDatabaseMissing('partner_benefits', ['id' => $benefit->id]);
    }

    public function test_user_without_permission_cannot_reach_the_screen(): void
    {
        Livewire::actingAs($this->makeUserWithNoPermissions())->test(Manage::class)->assertForbidden();
    }

    public function test_franchise_scoped_grant_does_not_cover_benefit_writes(): void
    {
        $franchise = $this->makeFranchise();
        $actor = $this->makeUserWithPermission('cms.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('benefitIcon', 'wallet')
            ->set('benefitTitle', 'Franchise benefit')
            ->set('benefitDescription', 'x')
            ->call('saveBenefit')
            ->assertHasErrors(['permission']);

        $this->assertDatabaseMissing('partner_benefits', ['title' => 'Franchise benefit']);
    }
}
