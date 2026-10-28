<?php

namespace Tests\Feature\Partners;

use App\Actions\SetProviderOnlineStatusAction;
use App\Exceptions\CannotGoOnlineException;
use App\Livewire\Partners\Index as PartnersIndex;
use App\Livewire\Provider\Dashboard as ProviderDashboard;
use App\Livewire\Providers\Index as ProvidersIndex;
use App\Livewire\Providers\Show as ProvidersShow;
use App\Livewire\Stores\Manage as StoresManage;
use App\Livewire\Workers\Index as WorkersIndex;
use App\Livewire\Workers\Show as WorkersShow;
use App\Models\FieldWorker;
use App\Models\FieldWorkerCapability;
use App\Models\FieldWorkerDocument;
use App\Models\Franchise;
use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Store;
use App\Models\User;
use App\Models\Zone;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Admin → Partners (owner decision 2026-10-26): one page, People + Businesses tabs, Needs review first, and each
 * city approves its own partners (franchise owner) without ever reaching another city's.
 */
class PartnersPageTest extends TestCase
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

    /** The real seeded Franchise Owner role, at one franchise. */
    private function franchiseOwner(Franchise $franchise): User
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Owner '.Str::random(4), 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'franchise_owner', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('slug', 'franchise_owner')->value('id'), 'scope_type' => 'franchise', 'scope_id' => $franchise->id]);

        return $user;
    }

    private function pendingRider(Franchise $franchise, Zone $zone, string $type = 'parcel_rider'): FieldWorker
    {
        $rider = $this->makeParcelRiderIn($franchise, $zone);
        $rider->update(['kyc_status' => 'pending', 'is_online' => false]);
        if ($type !== 'parcel_rider') {
            $rider->capabilities()->delete();
            FieldWorkerCapability::create(['field_worker_id' => $rider->id, 'capability_type' => $type]);
        }
        Storage::disk('local')->put("kyc/w{$rider->id}.jpg", 'x');
        FieldWorkerDocument::create(['field_worker_id' => $rider->id, 'type' => 'id_proof', 'status' => 'pending', 'is_current' => true, 'disk_path' => "kyc/w{$rider->id}.jpg"]);

        return $rider->fresh();
    }

    private function pendingProvider(Franchise $franchise, Zone $zone): Provider
    {
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'pending', 'is_online' => false]);
        ProviderDocument::create(['provider_id' => $provider->id, 'type' => 'id_proof', 'status' => 'pending', 'is_current' => true]);

        return $provider->fresh();
    }

    public function test_the_page_has_people_and_businesses_tabs_with_needs_review_counts(): void
    {
        $this->pendingRider($this->franchiseA, $this->zoneA);
        $this->pendingProvider($this->franchiseA, $this->zoneA);
        $this->pendingProvider($this->franchiseA, $this->zoneA);

        $this->actingAs($this->makeSuperAdmin())->get(route('admin.partners.index'))->assertOk()
            ->assertSeeInOrder(['People', '1', 'Businesses', '2'])
            ->assertSee('Needs review');
    }

    public function test_lists_open_on_needs_review_oldest_wait_first_with_hours(): void
    {
        $this->travel(-30)->hours();
        $old = $this->pendingRider($this->franchiseA, $this->zoneA);
        $this->travelBack();
        $new = $this->pendingRider($this->franchiseA, $this->zoneA);
        $approved = $this->makeParcelRiderIn($this->franchiseA, $this->zoneA);

        Livewire::actingAs($this->makeSuperAdmin())->test(WorkersIndex::class, ['embedded' => true])
            ->assertSet('statusFilter', 'review')
            ->assertViewHas('workers', fn ($w) => $w->pluck('id')->all() === [$old->id, $new->id])
            ->assertSee('30 h')
            ->set('statusFilter', '')
            ->assertViewHas('workers', fn ($w) => $w->pluck('id')->contains($approved->id));
    }

    public function test_type_filters_narrow_people_and_businesses(): void
    {
        $rider = $this->pendingRider($this->franchiseA, $this->zoneA);
        $driver = $this->pendingRider($this->franchiseA, $this->zoneA, 'taxi_driver');
        $pro = $this->pendingProvider($this->franchiseA, $this->zoneA);
        $restaurant = $this->pendingProvider($this->franchiseA, $this->zoneA);
        Store::create(['provider_id' => $restaurant->id, 'franchise_id' => $this->franchiseA->id, 'zone_id' => $this->zoneA->id, 'module' => 'food', 'name' => 'Biryani House', 'address_line' => 'Main Rd', 'lat' => 1, 'lng' => 1, 'is_active' => true, 'is_open' => true]);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(WorkersIndex::class, ['embedded' => true])->set('typeFilter', 'taxi_driver')
            ->assertViewHas('workers', fn ($w) => $w->pluck('id')->all() === [$driver->id]);

        $businesses = Livewire::actingAs($admin)->test(ProvidersIndex::class, ['embedded' => true]);
        $businesses->set('typeFilter', 'food')->assertViewHas('providers', fn ($p) => $p->pluck('id')->all() === [$restaurant->id]);
        $businesses->set('typeFilter', 'service')->assertViewHas('providers', fn ($p) => $p->pluck('id')->all() === [$pro->id]);
        $this->assertNotNull($rider);
    }

    public function test_a_franchise_owner_sees_only_their_own_citys_partners_and_filters_cannot_widen_it(): void
    {
        $ownRider = $this->pendingRider($this->franchiseA, $this->zoneA);
        $this->pendingRider($this->franchiseB, $this->zoneB);
        $ownPro = $this->pendingProvider($this->franchiseA, $this->zoneA);
        $this->pendingProvider($this->franchiseB, $this->zoneB);
        $owner = $this->franchiseOwner($this->franchiseA);

        $this->actingAs($owner)->get(route('admin.partners.index', ['tab' => 'people']))->assertOk()
            ->assertSeeInOrder(['People', '1', 'Businesses', '1']);

        Livewire::actingAs($owner)->test(WorkersIndex::class, ['embedded' => true])
            ->assertViewHas('workers', fn ($w) => $w->pluck('id')->all() === [$ownRider->id])
            ->assertViewHas('franchiseOptions', fn ($o) => array_keys($o) === [$this->franchiseA->id])
            ->set('franchiseFilter', (string) $this->franchiseB->id)
            ->assertViewHas('workers', fn ($w) => $w->isEmpty())
            ->set('franchiseFilter', '')->set('zoneFilter', (string) $this->zoneB->id)
            ->assertViewHas('workers', fn ($w) => $w->isEmpty());

        Livewire::actingAs($owner)->test(ProvidersIndex::class, ['embedded' => true])
            ->assertViewHas('providers', fn ($p) => $p->pluck('id')->all() === [$ownPro->id])
            ->set('statusFilter', '')->set('cityFilter', (string) $this->franchiseB->city_id)
            ->assertViewHas('providers', fn ($p) => $p->isEmpty());
    }

    public function test_a_franchise_owner_cannot_open_review_or_read_documents_of_another_citys_partners_by_direct_url(): void
    {
        $foreignRider = $this->pendingRider($this->franchiseB, $this->zoneB);
        $foreignPro = $this->pendingProvider($this->franchiseB, $this->zoneB);
        $ownRider = $this->pendingRider($this->franchiseA, $this->zoneA);
        $owner = $this->franchiseOwner($this->franchiseA);

        $this->actingAs($owner);
        $this->get(route('admin.workers.show', $foreignRider->id))->assertNotFound();
        $this->get(route('admin.providers.show', $foreignPro->id))->assertNotFound();
        $this->get(route('admin.kyc.documents.field-worker', $foreignRider->documents()->value('id')))->assertNotFound();
        $this->get(route('admin.kyc.documents.field-worker', $ownRider->documents()->value('id')))->assertOk();
        $this->get(route('admin.workers.show', $ownRider->id))->assertOk();
    }

    public function test_a_franchise_owner_approves_their_own_partners_with_the_existing_review_rules(): void
    {
        $rider = $this->pendingRider($this->franchiseA, $this->zoneA);
        $pro = $this->pendingProvider($this->franchiseA, $this->zoneA);
        $owner = $this->franchiseOwner($this->franchiseA);

        $page = Livewire::actingAs($owner)->test(WorkersShow::class, ['workerId' => $rider->id]);
        $doc = $rider->currentDocuments()->sole();
        $page->call('rejectDocument', $doc->id)->assertSet('flashType', 'error');
        $this->assertSame('pending', $doc->fresh()->status, 'a reject reason is always required');
        $page->call('approveDocument', $doc->id)->assertSet('flashType', 'success');
        $this->assertSame($owner->id, $doc->fresh()->reviewed_by);

        $proDoc = $pro->currentDocuments()->sole();
        Livewire::actingAs($owner)->test(ProvidersShow::class, ['providerId' => $pro->id])->call('approveDocument', $proDoc->id);
        $this->assertSame('approved', $proDoc->fresh()->status);
        $this->assertSame($owner->id, $proDoc->fresh()->reviewed_by);
    }

    public function test_old_workers_and_drivers_urls_redirect_to_the_people_tab_keeping_the_query(): void
    {
        $this->actingAs($this->makeSuperAdmin());

        $this->get('/admin/workers?statusFilter=approved')->assertStatus(301)
            ->assertRedirect(route('admin.partners.index', ['tab' => 'people', 'statusFilter' => 'approved']));
        $this->get('/admin/drivers')->assertStatus(301)
            ->assertRedirect(route('admin.partners.index', ['tab' => 'people', 'typeFilter' => 'taxi_driver']));
        $this->get(route('admin.partners.index', ['tab' => 'people', 'statusFilter' => 'approved']))->assertOk();
    }

    public function test_a_pending_provider_cannot_go_online_and_an_approved_one_can(): void
    {
        $pro = $this->pendingProvider($this->franchiseA, $this->zoneA);

        Livewire::actingAs($pro->user)->test(ProviderDashboard::class)
            ->call('goOnline', 1.5, 1.5)
            ->assertSet('error', fn ($e) => str_contains($e, 'under review'));
        $this->assertFalse($pro->fresh()->is_online);

        $pro->update(['kyc_status' => 'rejected']);
        try {
            app(SetProviderOnlineStatusAction::class)->execute($pro->fresh(), true, 1.5, 1.5);
            $this->fail('a rejected provider went online');
        } catch (CannotGoOnlineException $e) {
            $this->assertStringContainsString('not approved', $e->getMessage());
        }

        $pro->update(['kyc_status' => 'approved']);
        app(SetProviderOnlineStatusAction::class)->execute($pro->fresh(), true, 1.5, 1.5);
        $this->assertTrue($pro->fresh()->is_online);
        app(SetProviderOnlineStatusAction::class)->execute($pro->fresh(), false);
        $this->assertFalse($pro->fresh()->is_online);
    }

    public function test_a_pending_rider_gets_no_parcel_offers(): void
    {
        $s = $this->makeParcelOrderScenario();
        $s['rider']->update(['kyc_status' => 'pending']);

        $this->assertFalse(app(DispatchService::class)->findWorkerCandidates($s['order'])->pluck('provider.id')->contains($s['rider']->id));

        $s['rider']->update(['kyc_status' => 'approved']);
        $this->assertTrue(app(DispatchService::class)->findWorkerCandidates($s['order']->fresh())->pluck('provider.id')->contains($s['rider']->id));
    }

    public function test_listing_screens_only_offer_and_accept_owners_from_the_users_own_area(): void
    {
        $own = $this->makeProviderIn($this->franchiseA, $this->zoneA);
        $foreign = $this->makeProviderIn($this->franchiseB, $this->zoneB);
        $actor = $this->makeUserWithPermission('stores.manage', 'franchise', $this->franchiseA->id);

        Livewire::actingAs($actor)->test(StoresManage::class)
            ->assertViewHas('providers', fn ($p) => $p->pluck('id')->all() === [$own->id])
            ->set('providerId', $foreign->id)->set('zoneId', $this->zoneA->id)->set('module', 'food')
            ->set('name', 'Foreign owner')->set('addressLine', 'Rd')->set('lat', 1)->set('lng', 1)
            ->call('createStore')
            ->assertHasErrors(['providerId']);
        $this->assertSame(0, Store::count());

        Livewire::actingAs($actor)->test(StoresManage::class)
            ->set('providerId', $own->id)->set('zoneId', $this->zoneA->id)->set('module', 'food')
            ->set('name', 'Own owner')->set('addressLine', 'Rd')->set('lat', 1)->set('lng', 1)
            ->call('createStore')
            ->assertHasNoErrors();
        $this->assertSame($own->id, Store::sole()->provider_id);
    }
}
