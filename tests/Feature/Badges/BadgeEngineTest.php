<?php

namespace Tests\Feature\Badges;

use App\Livewire\Badges\Manage as BadgesManage;
use App\Models\Badge;
use App\Models\BadgeAssignment;
use App\Models\Service;
use App\Services\BadgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Universal Badge Engine (Phase 1 of the full-day mission). NEW is the one
 * badge with a real automatic rule (recently_created, admin-configurable
 * within_days -- see badges table's migration docblock for why POPULAR/
 * TRENDING/etc. are 'manual' instead: no existing popularity/trending
 * statistics engine exists to honestly drive an automatic rule for them).
 */
class BadgeEngineTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function makeService(): Service
    {
        [, $service] = $this->makeCategoryAndService();

        return $service;
    }

    private function manualBadge(array $overrides = []): Badge
    {
        return Badge::where('mode', 'manual')->first() ?? Badge::create(array_merge([
            'key' => 'test-manual-'.Str::random(6), 'label' => 'TEST', 'mode' => 'manual',
            'text_color' => '#fff', 'bg_color' => '#000', 'is_active' => true,
        ], $overrides));
    }

    // ============================== Lifecycle ==============================

    public function test_seeded_badge_definitions_exist_with_the_required_examples(): void
    {
        $keys = Badge::pluck('key')->all();

        foreach (['new', 'popular', 'trending', 'featured', 'best_value', 'limited', 'flash_sale'] as $expected) {
            $this->assertContains($expected, $keys);
        }
    }

    public function test_assigning_a_manual_badge_makes_it_appear_in_badgesFor(): void
    {
        $service = $this->makeService();
        $badge = Badge::where('key', 'featured')->first();

        app(BadgeService::class)->assign($badge, $service);

        $badges = app(BadgeService::class)->badgesFor($service);
        $this->assertContains('featured', array_column($badges, 'key'));
    }

    public function test_new_badge_applies_automatically_to_a_recently_created_service_without_any_assignment_row(): void
    {
        $service = $this->makeService(); // created_at defaults to now()

        $badges = app(BadgeService::class)->badgesFor($service);

        $this->assertContains('new', array_column($badges, 'key'));
        $this->assertSame(0, BadgeAssignment::count(), 'NEW must never persist an assignment row.');
    }

    public function test_new_badge_does_not_apply_to_an_old_service(): void
    {
        $service = $this->makeService();
        $service->created_at = now()->subDays(30);
        $service->save();

        $badges = app(BadgeService::class)->badgesFor($service->fresh());

        $this->assertNotContains('new', array_column($badges, 'key'));
    }

    public function test_new_badge_within_days_is_admin_configurable_not_hardcoded(): void
    {
        $service = $this->makeService();
        $service->created_at = now()->subDays(20);
        $service->save();

        $this->assertNotContains('new', array_column(app(BadgeService::class)->badgesFor($service->fresh()), 'key'));

        Badge::where('key', 'new')->update(['rule_config' => ['within_days' => 25]]);

        $this->assertContains('new', array_column(app(BadgeService::class)->badgesFor($service->fresh()), 'key'));
    }

    public function test_automatic_badges_cannot_be_manually_assigned(): void
    {
        $service = $this->makeService();
        $newBadge = Badge::where('key', 'new')->first();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('automatic');
        app(BadgeService::class)->assign($newBadge, $service);
    }

    public function test_revoking_a_badge_removes_it_from_badgesFor_but_keeps_the_audit_row(): void
    {
        $service = $this->makeService();
        $badge = Badge::where('key', 'popular')->first();
        $assignment = app(BadgeService::class)->assign($badge, $service);

        app(BadgeService::class)->revoke($assignment);

        $this->assertNotContains('popular', array_column(app(BadgeService::class)->badgesFor($service), 'key'));
        $this->assertDatabaseHas('badge_assignments', ['id' => $assignment->id, 'is_active' => false]);
    }

    // ============================== Expiry ==============================

    public function test_an_expired_manual_badge_no_longer_appears(): void
    {
        $service = $this->makeService();
        $badge = Badge::where('key', 'limited')->first();
        app(BadgeService::class)->assign($badge, $service, 'global', null, Carbon::now()->addMinute());

        $this->assertContains('limited', array_column(app(BadgeService::class)->badgesFor($service), 'key'));

        $this->travel(2)->minutes();

        $this->assertNotContains('limited', array_column(app(BadgeService::class)->badgesFor($service), 'key'), 'Must disappear automatically once expires_at passes, with no cron/sync step required.');
    }

    public function test_a_badge_not_yet_started_does_not_appear(): void
    {
        $service = $this->makeService();
        $badge = Badge::where('key', 'featured')->first();
        $assignment = app(BadgeService::class)->assign($badge, $service);
        $assignment->update(['starts_at' => now()->addDay()]);

        $this->assertNotContains('featured', array_column(app(BadgeService::class)->badgesFor($service), 'key'));
    }

    public function test_a_badge_with_no_expiry_never_expires(): void
    {
        $service = $this->makeService();
        $badge = Badge::where('key', 'flash_sale')->first();
        app(BadgeService::class)->assign($badge, $service, 'global', null, null);

        $this->travel(90)->days();

        $this->assertContains('flash_sale', array_column(app(BadgeService::class)->badgesFor($service), 'key'));
    }

    // ============================== Scope ==============================

    public function test_a_zone_scoped_badge_only_applies_within_that_zone(): void
    {
        $service = $this->makeService();
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $otherZone = \App\Models\Zone::create(['franchise_id' => $franchise->id, 'name' => 'Other Zone', 'boundary_polygon' => [['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]], 'is_active' => true]);
        $badge = Badge::where('key', 'trending')->first();
        app(BadgeService::class)->assign($badge, $service, 'zone', $zone->id);

        $this->assertContains('trending', array_column(app(BadgeService::class)->badgesFor($service, ['zone_id' => $zone->id]), 'key'));
        $this->assertNotContains('trending', array_column(app(BadgeService::class)->badgesFor($service, ['zone_id' => $otherZone->id]), 'key'));
        $this->assertNotContains('trending', array_column(app(BadgeService::class)->badgesFor($service, []), 'key'), 'No viewer scope at all must not match a zone-scoped badge.');
    }

    public function test_a_global_badge_applies_to_every_viewer_scope(): void
    {
        $service = $this->makeService();
        $badge = Badge::where('key', 'best_value')->first();
        app(BadgeService::class)->assign($badge, $service, 'global');

        $this->assertContains('best_value', array_column(app(BadgeService::class)->badgesFor($service, ['zone_id' => 999]), 'key'));
        $this->assertContains('best_value', array_column(app(BadgeService::class)->badgesFor($service, []), 'key'));
    }

    // ============================== Duplicates ==============================

    public function test_duplicate_active_assignment_of_the_same_badge_same_entity_same_scope_is_rejected(): void
    {
        $service = $this->makeService();
        $badge = Badge::where('key', 'featured')->first();
        app(BadgeService::class)->assign($badge, $service);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already carries');
        app(BadgeService::class)->assign($badge, $service);
    }

    public function test_a_revoked_assignment_does_not_block_a_fresh_one(): void
    {
        $service = $this->makeService();
        $badge = Badge::where('key', 'featured')->first();
        $first = app(BadgeService::class)->assign($badge, $service);
        app(BadgeService::class)->revoke($first);

        $second = app(BadgeService::class)->assign($badge, $service);

        $this->assertNotNull($second->id);
        $this->assertContains('featured', array_column(app(BadgeService::class)->badgesFor($service), 'key'));
    }

    public function test_the_same_badge_can_be_assigned_to_the_same_entity_at_two_different_scopes(): void
    {
        $service = $this->makeService();
        [, , , $zoneA] = $this->makeFranchiseTree();
        [, , , $zoneB] = $this->makeFranchiseTree();
        $badge = Badge::where('key', 'featured')->first();

        app(BadgeService::class)->assign($badge, $service, 'zone', $zoneA->id);
        $second = app(BadgeService::class)->assign($badge, $service, 'zone', $zoneB->id);

        $this->assertNotNull($second->id);
    }

    // ============================== Inactive badges ==============================

    public function test_an_inactive_badge_definition_does_not_appear_even_with_an_active_assignment(): void
    {
        $service = $this->makeService();
        $badge = Badge::where('key', 'popular')->first();
        app(BadgeService::class)->assign($badge, $service);
        $badge->update(['is_active' => false]);

        $this->assertNotContains('popular', array_column(app(BadgeService::class)->badgesFor($service), 'key'));
    }

    public function test_an_inactive_automatic_badge_definition_never_applies(): void
    {
        $service = $this->makeService();
        Badge::where('key', 'new')->update(['is_active' => false]);

        $this->assertNotContains('new', array_column(app(BadgeService::class)->badgesFor($service), 'key'));
    }

    // ============================== Customer serialization ==============================

    public function test_badgesFor_returns_a_json_serializable_display_shape(): void
    {
        $service = $this->makeService();
        $badge = Badge::where('key', 'flash_sale')->first();
        app(BadgeService::class)->assign($badge, $service);

        $badges = app(BadgeService::class)->badgesFor($service);
        $encoded = json_encode($badges);

        $this->assertJson($encoded);
        $this->assertArrayHasKey('key', $badges[0]);
        $this->assertArrayHasKey('label', $badges[0]);
        $this->assertArrayHasKey('text_color', $badges[0]);
        $this->assertArrayHasKey('bg_color', $badges[0]);
        $this->assertArrayHasKey('priority', $badges[0]);
    }

    public function test_badges_are_ordered_by_priority_descending(): void
    {
        $service = $this->makeService();
        // Direct property assignment, not update() -- created_at isn't in
        // Service::$fillable (confirmed by reading the model), so a mass-
        // assignment update() silently no-ops on it.
        $service->created_at = now()->subDays(30); // old enough that automatic NEW (priority 100) doesn't interfere with this ordering check
        $service->save();
        app(BadgeService::class)->assign(Badge::where('key', 'best_value')->first(), $service); // priority 70
        app(BadgeService::class)->assign(Badge::where('key', 'limited')->first(), $service); // priority 95

        $keys = array_column(app(BadgeService::class)->badgesFor($service->fresh()), 'key');
        $this->assertSame('limited', $keys[0], 'Higher priority must sort first.');
    }

    // ============================== Admin authorization ==============================

    public function test_screen_denied_without_badges_view_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(BadgesManage::class)->assertForbidden();
    }

    public function test_toggle_active_denied_without_badges_manage(): void
    {
        $actor = $this->makeUserWithPermission('badges.view', 'global');
        $badge = Badge::where('key', 'popular')->first();

        Livewire::actingAs($actor)->test(BadgesManage::class)
            ->call('toggleBadgeActive', $badge->id)
            ->assertSet('flashType', 'error');

        $this->assertTrue($badge->fresh()->is_active);
    }

    public function test_assign_denied_outside_the_actors_own_zone(): void
    {
        $service = $this->makeService();
        [, , , $myZone] = $this->makeFranchiseTree();
        [, , , $otherZone] = $this->makeFranchiseTree();
        $badge = Badge::where('key', 'featured')->first();
        $actor = $this->makeUserWithPermission('badges.manage', 'zone', $myZone->id);
        $this->grantPermission($actor, 'badges.view', 'zone', $myZone->id);

        Livewire::actingAs($actor)->test(BadgesManage::class)
            ->set('assignBadgeId', $badge->id)
            ->set('selectedServiceId', $service->id)
            ->set('scopeType', 'zone')
            ->set('scopeZoneId', $otherZone->id)
            ->call('assign')
            ->assertSet('flashType', 'error');

        $this->assertDatabaseMissing('badge_assignments', ['badge_id' => $badge->id, 'badgeable_id' => $service->id]);
    }

    public function test_assign_allowed_within_the_actors_own_zone(): void
    {
        $service = $this->makeService();
        [, , , $myZone] = $this->makeFranchiseTree();
        $badge = Badge::where('key', 'featured')->first();
        $actor = $this->makeUserWithPermission('badges.manage', 'zone', $myZone->id);
        $this->grantPermission($actor, 'badges.view', 'zone', $myZone->id);

        Livewire::actingAs($actor)->test(BadgesManage::class)
            ->set('assignBadgeId', $badge->id)
            ->set('selectedServiceId', $service->id)
            ->set('scopeType', 'zone')
            ->set('scopeZoneId', $myZone->id)
            ->call('assign')
            ->assertSet('flashType', 'success');

        $this->assertDatabaseHas('badge_assignments', ['badge_id' => $badge->id, 'badgeable_id' => $service->id, 'scope_type' => 'zone', 'scope_id' => $myZone->id]);
    }

    public function test_revoke_denied_outside_the_actors_own_zone(): void
    {
        $service = $this->makeService();
        [, , , $zone] = $this->makeFranchiseTree();
        $badge = Badge::where('key', 'featured')->first();
        $assignment = app(BadgeService::class)->assign($badge, $service, 'zone', $zone->id);

        $actor = $this->makeUserWithPermission('badges.manage', 'zone', 999999);
        $this->grantPermission($actor, 'badges.view', 'global');

        Livewire::actingAs($actor)->test(BadgesManage::class)
            ->call('revoke', $assignment->id)
            ->assertSet('flashType', 'error');

        $this->assertTrue($assignment->fresh()->is_active);
    }

    public function test_super_admin_can_manage_every_scope(): void
    {
        $service = $this->makeService();
        [, , , $zone] = $this->makeFranchiseTree();
        $badge = Badge::where('key', 'featured')->first();
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(BadgesManage::class)
            ->set('assignBadgeId', $badge->id)
            ->set('selectedServiceId', $service->id)
            ->set('scopeType', 'zone')
            ->set('scopeZoneId', $zone->id)
            ->call('assign')
            ->assertSet('flashType', 'success');
    }
}
