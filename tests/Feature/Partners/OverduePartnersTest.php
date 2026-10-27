<?php

namespace Tests\Feature\Partners;

use App\Livewire\Providers\Index as ProvidersIndex;
use App\Livewire\SiteControls\Manage;
use App\Livewire\Workers\Index as WorkersIndex;
use App\Models\ActivityLog;
use App\Models\FieldWorker;
use App\Models\FieldWorkerDocument;
use App\Models\Franchise;
use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Support\PartnerReview;
use App\Support\SiteControls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Overdue partners (owner 2026-10-27): a partner waiting past the red hours gets a red row and a pulsing Overdue
 * badge, the Needs review tab and the Partners tabs show the overdue count, and Partners in the admin menu gets a
 * red dot. Amber and red hours are Super Admin settings; every count stays inside the user's own city.
 */
class OverduePartnersTest extends TestCase
{
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private Franchise $franchiseA;

    private Zone $zoneA;

    private Franchise $franchiseB;

    private Zone $zoneB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [, , $this->franchiseA, $this->zoneA] = $this->makeFranchiseTree();
        [, , $this->franchiseB, $this->zoneB] = $this->makeFranchiseTree();
    }

    private function franchiseOwner(Franchise $franchise): User
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Owner '.Str::random(4), 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'franchise_owner', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('slug', 'franchise_owner')->value('id'), 'scope_type' => 'franchise', 'scope_id' => $franchise->id]);

        return $user;
    }

    /** A rider whose documents were sent $hours ago. */
    private function riderWaiting(int $hours, ?Franchise $franchise = null, ?Zone $zone = null): FieldWorker
    {
        $this->travel(-$hours)->hours();
        $rider = $this->makeParcelRiderIn($franchise ?? $this->franchiseA, $zone ?? $this->zoneA);
        $rider->update(['kyc_status' => 'pending', 'is_online' => false]);
        FieldWorkerDocument::create(['field_worker_id' => $rider->id, 'type' => 'id_proof', 'status' => 'pending', 'is_current' => true, 'disk_path' => "kyc/w{$rider->id}.jpg"]);
        $this->travelBack();

        return $rider->fresh();
    }

    /** A provider whose documents were sent $hours ago. */
    private function providerWaiting(int $hours, ?Franchise $franchise = null, ?Zone $zone = null): Provider
    {
        $this->travel(-$hours)->hours();
        $provider = $this->makeProviderIn($franchise ?? $this->franchiseA, $zone ?? $this->zoneA);
        $provider->update(['kyc_status' => 'pending', 'is_online' => false]);
        ProviderDocument::create(['provider_id' => $provider->id, 'type' => 'id_proof', 'status' => 'pending', 'is_current' => true]);
        $this->travelBack();

        return $provider->fresh();
    }

    public function test_default_hours_are_24_amber_and_48_red(): void
    {
        $this->assertSame(24, SiteControls::reviewAmberHours());
        $this->assertSame(48, SiteControls::reviewRedHours());
        $this->assertNull(PartnerReview::level(23));
        $this->assertSame('amber', PartnerReview::level(24));
        $this->assertSame('amber', PartnerReview::level(47));
        $this->assertSame('red', PartnerReview::level(48));
    }

    public function test_an_overdue_rider_gets_a_red_row_a_pulsing_badge_and_a_count_on_the_needs_review_tab(): void
    {
        $this->riderWaiting(50);
        $this->riderWaiting(30);

        Livewire::actingAs($this->makeSuperAdmin())->test(WorkersIndex::class, ['embedded' => true])
            ->assertViewHas('counts', fn ($c) => $c['review'] === 2 && $c['overdue'] === 1)
            ->assertSeeInOrder(['50 h', 'Overdue', '30 h'])
            ->assertSee('1 overdue')
            ->assertSee('border-l-red-600', false)
            ->assertSee('motion-safe:animate-pulse', false);
    }

    public function test_a_wait_under_the_red_hours_is_amber_with_no_overdue_marks(): void
    {
        $this->riderWaiting(30);

        Livewire::actingAs($this->makeSuperAdmin())->test(WorkersIndex::class, ['embedded' => true])
            ->assertSee('30 h')
            ->assertSee('text-amber-700', false)
            ->assertDontSee('Overdue')
            ->assertDontSee('overdue')
            ->assertDontSee('border-l-red-600', false);
    }

    public function test_an_overdue_provider_is_marked_on_the_businesses_list_and_the_partners_tabs(): void
    {
        $this->providerWaiting(49);
        $this->providerWaiting(2);
        $this->riderWaiting(1);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(ProvidersIndex::class, ['embedded' => true])
            ->assertViewHas('counts', fn ($c) => $c['review'] === 2 && $c['overdue'] === 1)
            ->assertSeeInOrder(['49 h', 'Overdue', '2 h']);

        $this->actingAs($admin)->get(route('admin.partners.index', ['tab' => 'businesses']))->assertOk()
            ->assertSeeInOrder(['People', '1', 'Businesses', '2', '1 overdue']);
    }

    public function test_an_approved_partner_with_an_old_document_is_never_overdue(): void
    {
        $rider = $this->riderWaiting(100);
        $rider->update(['kyc_status' => 'approved']);

        $this->assertSame(0, PartnerReview::overdueFor($this->makeSuperAdmin()));
    }

    public function test_partners_in_the_admin_menu_gets_a_red_dot_on_every_admin_page(): void
    {
        $admin = $this->makeSuperAdmin();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertDontSee('Partners — ');

        $this->riderWaiting(60);
        $this->providerWaiting(60);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('Partners — 2 overdue')
            ->assertSee('(2 overdue)');
    }

    public function test_a_franchise_owner_counts_only_their_own_citys_overdue_partners(): void
    {
        $this->riderWaiting(60, $this->franchiseB, $this->zoneB);
        $this->providerWaiting(60, $this->franchiseB, $this->zoneB);
        $owner = $this->franchiseOwner($this->franchiseA);

        $this->assertSame(0, PartnerReview::overdueFor($owner));
        $this->actingAs($owner)->get(route('admin.partners.index'))->assertOk()->assertDontSee('overdue');

        $this->riderWaiting(60);
        $this->assertSame(1, PartnerReview::overdueFor($owner));
        $this->actingAs($owner)->get(route('admin.partners.index', ['tab' => 'people']))->assertOk()
            ->assertSee('1 overdue')->assertDontSee('2 overdue');
    }

    public function test_super_admin_sets_the_amber_and_red_hours_with_an_audit_trail(): void
    {
        $admin = $this->makeSuperAdmin();
        $this->riderWaiting(12);
        $this->assertSame(0, PartnerReview::overdueFor($admin));

        Livewire::actingAs($admin)->test(Manage::class)
            ->assertSet('reviewAmberHours', '24')->assertSet('reviewRedHours', '48')
            ->set('reviewAmberHours', '10')->set('reviewRedHours', '5')
            ->call('save')->assertHasErrors('reviewRedHours');

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('reviewAmberHours', '4')->set('reviewRedHours', '10')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('4', Setting::get(SiteControls::REVIEW_AMBER_HOURS));
        $this->assertSame('10', Setting::get(SiteControls::REVIEW_RED_HOURS));
        $this->assertTrue(ActivityLog::where('description', 'Setting '.SiteControls::REVIEW_RED_HOURS.' changed')->exists());
        $this->assertSame(1, PartnerReview::overdueFor($admin));
        $this->assertSame('amber', PartnerReview::level(5));
    }

    public function test_the_review_hours_cannot_be_changed_by_anyone_but_super_admin(): void
    {
        Livewire::actingAs($this->franchiseOwner($this->franchiseA))->test(Manage::class)->assertForbidden();
    }
}
