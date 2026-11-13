<?php

namespace Tests\Feature\Halls;

use App\Actions\RegisterHallAction;
use App\Livewire\Halls\Manage as HallsManage;
use App\Livewire\Halls\Settings as HallSettingsPage;
use App\Livewire\Venue\Dashboard;
use App\Livewire\Venue\Dates;
use App\Livewire\Venue\HallEditor;
use App\Models\Hall;
use App\Models\HallBlockedDate;
use App\Models\Setting;
use App\Support\HallSettings;
use App\Support\PartnerPage\PartnerPageData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Function halls R1 (owner 2026-11-13: "start with events and then start with rentals"; halls first): a hall owner
 * joins through /partners, finishes the hall (slots, prices, photos) in its own portal and sends it for review; the
 * team approves it, and every later change to a live hall's name / address / photos / description, in Admin →
 * Function halls. Every rule value is a Super Admin setting (Hall Settings).
 */
class HallPortalTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function owner(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $hall = app(RegisterHallAction::class)->execute(
            zone: $zone, hallName: 'Sri Lakshmi Function Hall', existing: null,
            registration: ['address' => 'Trunk Road, Nellore', 'lat' => '14.44', 'lng' => '79.98'], name: 'Ravi',
            nationalPhone: '9'.fake()->numerify('#########'), plainPassword: 'secret-pass-1',
        );

        return [$hall->provider->user, $hall->fresh(), $franchise];
    }

    private function fill($page)
    {
        return $page->set('hallType', 'Function hall')->set('seating', '400')->set('floating', '700')->set('isAc', true)
            ->set('about', 'AC hall with dining for 300.')->set('amenities', ['Dining hall', 'Stage'])->set('deposit', '10000')
            ->set('hallSlots', [
                ['id' => null, 'name' => 'Full day', 'start_time' => '06:00', 'end_time' => '23:00', 'price' => '60000'],
                ['id' => null, 'name' => 'Evening', 'start_time' => '18:00', 'end_time' => '02:00', 'price' => '40000'],
            ]);
    }

    private function photos($page)
    {
        return $page->set('cover', UploadedFile::fake()->image('cover.jpg', 1600, 1067))
            ->set('newPhotos', [UploadedFile::fake()->image('a.jpg', 1600, 1067), UploadedFile::fake()->image('b.jpg', 1600, 1067), UploadedFile::fake()->image('c.jpg', 1600, 1067)]);
    }

    private function approvedHall(): array
    {
        Storage::fake('public');
        [$user, $hall] = $this->owner();
        $this->photos($this->fill(Livewire::actingAs($user)->test(HallEditor::class, ['hallId' => $hall->id])))->call('submit')->assertHasNoErrors();
        Livewire::actingAs($this->makeSuperAdmin())->test(HallsManage::class)->call('approve', $hall->id);

        return [$user, $hall->fresh()];
    }

    // ── settings ──

    public function test_settings_are_super_admin_only_with_the_proposed_rules_and_gst_is_optional(): void
    {
        $this->assertSame('25', HallSettings::get('hall.advance_percent'));
        $this->assertSame('10', HallSettings::get('hall.commission_percent'));
        $this->assertSame('2', HallSettings::get('hall.owner_accept_hours'));
        $this->assertSame(0.0, HallSettings::gstPercentFor(60000), 'No bands: no GST.');
        $this->assertNotNull(HallSettings::validate('hall.gst_slabs', '10000 : 0'), 'Bands must end with a * line.');
        Setting::set('hall.gst_slabs', "10000 : 0\n* : 18");
        $this->assertSame(18.0, HallSettings::gstPercentFor(60000));

        Livewire::actingAs($this->makeUserWithNoPermissions())->test(HallSettingsPage::class)->assertForbidden();
        Livewire::actingAs($this->makeSuperAdmin())->test(HallSettingsPage::class)->assertOk()->assertSee('Advance paid online to book')->assertSee('Function halls →');
    }

    // ── joining ──

    public function test_partner_page_card_hands_off_only_when_super_admin_takes_hall_owners(): void
    {
        $card = fn () => collect(PartnerPageData::roles())->firstWhere('code', 'halls');
        $this->assertNotNull($card());
        $this->assertSame('Function hall owner', $card()['label']);
        $this->assertFalse($card()['hands_off']);

        Setting::set('hall.recruiting_owners', '1');
        $this->assertTrue($card()['hands_off']);
    }

    public function test_sign_up_page_asks_for_the_hall_name_when_handed_a_halls_lead(): void
    {
        Setting::set('hall.recruiting_owners', '1');
        $lead = \App\Models\PartnerLead::create(['name' => 'Ravi', 'phone' => '9876543210', 'city' => 'Nellore', 'role' => 'halls', 'status' => 'handed_off', 'source' => 'partner_page', 'consent_at' => now(), 'consent_text' => 'ok']);
        session()->put('partner_lead', ['id' => $lead->id, 'role' => 'halls', 'city' => '']);

        Livewire::test(\App\Livewire\Provider\Auth\Register::class)
            ->assertSet('partnerRole', 'halls')
            ->assertSee('List your function hall on')->assertSee('Hall name');
    }

    public function test_sign_up_creates_a_draft_switched_off_hall_with_its_own_documents(): void
    {
        [$user, $hall] = $this->owner();

        $this->assertSame('draft', $hall->status);
        $this->assertFalse($hall->is_active);
        $this->assertSame('sri-lakshmi-function-hall', $hall->slug);
        $this->assertEqualsWithDelta(14.44, (float) $hall->lat, 0.0001);
        $this->assertTrue($user->holdsPartnerRole('halls'));
        $this->assertFalse($user->holdsPartnerRole('service'), 'A hall-only account is not a service partner.');
        $this->assertFalse($user->canApplyAsPartner('halls'), 'One hall per account through sign-up.');
        $this->assertSame(['hall'], app(\App\Services\Kyc\KycDocumentService::class)->applicableTypesFor($hall->provider));
    }

    public function test_an_organiser_can_also_list_a_hall_on_the_same_account(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $user = $this->makeCustomer();
        $hall = app(RegisterHallAction::class)->execute(zone: $zone, hallName: 'Garden Lawn', existing: $user, registration: ['address' => 'Nellore']);

        $this->assertSame($user->id, $hall->provider->user_id);
        $this->assertTrue($user->fresh()->holdsPartnerRole('halls'));
    }

    // ── portal ──

    public function test_portal_is_for_hall_owners_only(): void
    {
        [$user] = $this->owner();
        $this->actingAs($user)->get(route('venue.dashboard'))->assertOk()->assertSee('Sri Lakshmi Function Hall')->assertSee('Finish your hall and send for review');
        $this->actingAs($user)->get(route('venue.dates'))->assertOk()->assertSee('Blocked dates');
        $this->actingAs($this->makeCustomer())->get(route('venue.dashboard'))->assertForbidden();
    }

    public function test_owner_finishes_the_hall_with_slots_and_photos_and_sends_it_for_review(): void
    {
        Storage::fake('public');
        [$user, $hall] = $this->owner();
        $this->assertFalse(collect(Dashboard::checklist($hall))->every('done'));

        $page = $this->fill(Livewire::actingAs($user)->test(HallEditor::class, ['hallId' => $hall->id]))->call('submit');
        $this->assertSame('draft', $hall->fresh()->status, 'No photos yet: saved as a draft, not sent.');
        $page->assertSee('Add a cover photo.');
        $this->assertSame(2, $hall->slots()->count());
        $this->assertTrue($hall->slots()->where('name', 'Evening')->firstOrFail()->endsNextDay());

        $this->photos($page)->call('submit')->assertHasNoErrors();
        $hall->refresh();
        $this->assertSame('in_review', $hall->status);
        $this->assertCount(3, $hall->photos);
        $this->assertTrue(collect(Dashboard::checklist($hall->load('slots')))->every('done'));

        // In review: locked for the owner.
        Livewire::actingAs($user)->test(HallEditor::class, ['hallId' => $hall->id])->set('name', 'Changed')->call('save')->assertHasErrors('hall');
        $this->assertSame('Sri Lakshmi Function Hall', $hall->fresh()->name);
    }

    /** Owner 2026-11-13 (ease of use thumb rule): one tap adds a slot with its usual times; only the price is typed. */
    public function test_slot_buttons_add_ready_slots_and_the_page_has_no_typed_map_numbers(): void
    {
        [$user, $hall] = $this->owner();
        $page = Livewire::actingAs($user)->test(HallEditor::class, ['hallId' => $hall->id])
            ->call('addPreset', 'morning')->call('addPreset', 'evening')->call('addPreset', 'morning');
        $names = array_column($page->get('hallSlots'), 'name');
        $this->assertSame(['Morning', 'Evening'], $names, 'The empty starter row is replaced, and Morning is not added twice.');
        $this->assertSame('17:00', $page->get('hallSlots')[1]['start_time']);

        $page->assertSee('Location saved')->assertSee('Morning</button>', false)->assertDontSee('Map latitude');
    }

    public function test_slot_rules_are_checked(): void
    {
        [$user, $hall] = $this->owner();
        $page = $this->fill(Livewire::actingAs($user)->test(HallEditor::class, ['hallId' => $hall->id]));

        $page->set('hallSlots.0.price', '0')->set('hallSlots.1.name', 'full day')->call('save')->assertHasErrors(['hallSlots.0.price', 'hallSlots.1.name']);
        $page->set('hallSlots.0.price', '60000')->set('hallSlots.1.name', 'Evening')->set('hallSlots.1.end_time', '18:00')->call('save')->assertHasErrors(['hallSlots.1.end_time']);
        $page->set('hallSlots.1.end_time', '02:00')->set('hallType', 'Castle')->call('save')->assertHasErrors(['hallType']);
        $this->assertSame(0, $hall->slots()->count());
    }

    public function test_an_owner_never_sees_another_owners_hall(): void
    {
        [$user] = $this->owner();
        [, $other] = $this->owner();

        $this->actingAs($user)->get(route('venue.halls.edit', $other->id))->assertNotFound();
        Livewire::actingAs($user)->test(Dates::class)->set('hallId', $other->id)->set('date', now()->addDays(3)->toDateString())->call('block')->assertNotFound();
    }

    public function test_owner_adds_a_second_hall_from_the_portal(): void
    {
        [$user, $hall] = $this->owner();
        $this->fill(Livewire::actingAs($user)->test(HallEditor::class))->set('name', 'Mini Party Hall')->set('addressLine', 'Magunta Layout, Nellore')->call('save')->assertHasNoErrors();

        $second = Hall::where('name', 'Mini Party Hall')->firstOrFail();
        $this->assertSame($hall->provider_id, $second->provider_id);
        $this->assertSame($hall->franchise_id, $second->franchise_id);
        $this->assertSame('draft', $second->status);
    }

    public function test_blocked_dates_for_a_whole_day_or_one_slot(): void
    {
        [$user, $hall] = $this->approvedHall();
        $evening = $hall->slots()->where('name', 'Evening')->firstOrFail();
        $day = now('Asia/Kolkata')->addDays(10)->toDateString();

        $page = Livewire::actingAs($user)->test(Dates::class)
            ->set('date', $day)->set('slotId', (string) $evening->id)->set('note', 'Reddy wedding')->call('block')->assertHasNoErrors()
            ->assertSee('Reddy wedding');
        $page->set('date', $day)->set('slotId', (string) $evening->id)->call('block')->assertHasErrors('date');
        $page->set('date', now('Asia/Kolkata')->subDay()->toDateString())->call('block')->assertHasErrors('date');
        $this->assertSame(1, HallBlockedDate::count());

        $page->call('unblock', HallBlockedDate::firstOrFail()->id);
        $this->assertSame(0, HallBlockedDate::count());
    }

    // ── admin ──

    public function test_admin_approves_the_hall_and_can_send_it_back(): void
    {
        [$user, $hall] = $this->approvedHall();
        $this->assertSame('approved', $hall->status);
        $this->assertTrue($hall->is_active);

        $admin = $this->makeSuperAdmin();
        Livewire::actingAs($admin)->test(HallsManage::class)->call('sendBack', $hall->id)->assertHasErrors('reason')
            ->set('reason', 'Please add a photo of the dining hall')->call('sendBack', $hall->id);
        $this->assertSame('rejected', $hall->fresh()->status);
        $this->assertFalse($hall->fresh()->is_active, 'Sent back: no bookings until approved again.');
        $this->actingAs($user)->get(route('venue.dashboard'))->assertSee('Please add a photo of the dining hall');
    }

    public function test_a_hall_missing_photos_cannot_be_approved(): void
    {
        [$user, $hall] = $this->owner();
        $hall->update(['status' => 'in_review']);
        Livewire::actingAs($this->makeSuperAdmin())->test(HallsManage::class)->call('approve', $hall->id);
        $this->assertSame('in_review', $hall->fresh()->status);
    }

    public function test_a_live_hall_changes_prices_at_once_and_its_name_and_photos_wait_for_the_team(): void
    {
        [$user, $hall] = $this->approvedHall();
        $oldCover = $hall->cover_image;

        Livewire::actingAs($user)->test(HallEditor::class, ['hallId' => $hall->id])
            ->set('hallSlots.0.price', '65000')->set('name', 'Sri Lakshmi Convention')
            ->set('cover', UploadedFile::fake()->image('new.jpg', 1600, 1067))->call('save')->assertHasNoErrors()
            ->assertSee('show once we check them');

        $hall->refresh();
        $this->assertSame(65000.0, (float) $hall->slots()->where('name', 'Full day')->value('price'), 'Prices change at once.');
        $this->assertSame('Sri Lakshmi Function Hall', $hall->name, 'Customers keep the old name meanwhile.');
        $this->assertSame($oldCover, $hall->cover_image);
        $this->assertSame('approved', $hall->status, 'The hall stays live.');
        $this->assertSame(['name', 'cover_image'], array_keys($hall->pending_changes));

        $admin = $this->makeSuperAdmin();
        Livewire::actingAs($admin)->test(HallsManage::class)->set('statusFilter', 'changes')->assertSee('Sri Lakshmi Function Hall')
            ->call('viewHall', $hall->id)->assertSee('Sri Lakshmi Convention')->call('approveChanges', $hall->id);
        $hall->refresh();
        $this->assertSame('Sri Lakshmi Convention', $hall->name);
        $this->assertSame('sri-lakshmi-function-hall', $hall->slug, 'The public link stays the same.');
        $this->assertNotSame($oldCover, $hall->cover_image);
        $this->assertNull($hall->pending_changes);

        // A change the team does not accept: nothing changes, the owner sees why.
        Livewire::actingAs($user)->test(HallEditor::class, ['hallId' => $hall->id])->set('about', 'Cheapest hall in town!!!')->call('save');
        Livewire::actingAs($admin)->test(HallsManage::class)->set('reason', 'No price claims in the description')->call('rejectChanges', $hall->id);
        $this->assertSame('AC hall with dining for 300.', $hall->fresh()->about);
        $this->actingAs($user)->get(route('venue.halls.edit', $hall->id))->assertSee('No price claims in the description');
    }

    public function test_owner_switches_bookings_off_and_on(): void
    {
        [$user, $hall] = $this->approvedHall();
        Livewire::actingAs($user)->test(HallEditor::class, ['hallId' => $hall->id])->call('toggleActive');
        $this->assertFalse($hall->fresh()->is_active);
        Livewire::actingAs($user)->test(HallEditor::class, ['hallId' => $hall->id])->call('toggleActive');
        $this->assertTrue($hall->fresh()->is_active);
    }

    public function test_admin_screen_needs_the_permission_and_stays_in_its_own_franchise(): void
    {
        [, $hall, $franchise] = $this->owner();
        [, $other] = $this->owner();
        $other->update(['status' => 'in_review']);

        Livewire::actingAs($this->makeUserWithNoPermissions())->test(HallsManage::class)->assertForbidden();

        $local = $this->makeUserWithPermission('halls.manage', 'franchise', $franchise->id);
        Livewire::actingAs($local)->test(HallsManage::class)->set('statusFilter', '')
            ->assertSee($hall->name)->call('viewHall', $other->id)->assertNotFound();
    }

    /** Owner 2026-11-13: "a test rental property in each category". */
    public function test_test_accounts_make_one_approved_hall_of_every_kind(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'test-pass-123', '--franchise' => $franchise->id])->assertSuccessful();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'test-pass-123', '--franchise' => $franchise->id])->assertSuccessful();

        $halls = Hall::whereHas('provider.user', fn ($q) => $q->where('email', 'test.hall@1callfix.test'))->get();
        $this->assertCount(7, $halls, 'Running it twice never makes duplicates.');
        $this->assertEqualsCanonicalizing(HallSettings::lines('hall.types'), $halls->pluck('hall_type')->all(), 'Every kind of hall has one.');
        foreach ($halls as $hall) {
            $this->assertSame('approved', $hall->status);
            $this->assertTrue($hall->is_active);
            $this->assertSame([], HallEditor::submitProblems($hall), $hall->name.' is complete.');
        }
        $this->assertSame(3, $halls->firstWhere('name', 'Test Function Hall')->slots()->count());

        $this->artisan('test-accounts', ['action' => 'disable'])->assertSuccessful();
        $this->assertSame(0, Hall::whereIn('id', $halls->pluck('id'))->where('is_active', true)->count());
    }
}
