<?php

namespace Tests\Feature\Events;

use App\Actions\RegisterOrganiserAction;
use App\Livewire\Events\Manage as EventsManage;
use App\Livewire\Events\Settings as EventSettingsPage;
use App\Livewire\Organiser\Dashboard;
use App\Livewire\Organiser\Details;
use App\Livewire\Organiser\EventEditor;
use App\Models\Event;
use App\Models\EventOrganiser;
use App\Models\Setting;
use App\Support\EventSettings;
use App\Support\PartnerPage\PartnerPageData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Events E1 (owner 2026-11-13): an event organiser joins through /partners, waits for approval, builds events with
 * ticket types in its own portal and sends each for review; the team approves organisers and events in Admin → Events.
 * Every rule value is a Super Admin setting (Event Settings).
 */
class OrganiserPortalTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function organiser(string $approval = 'pending'): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $organiser = app(RegisterOrganiserAction::class)->execute(
            zone: $zone, organiserName: 'Nellore Nights', existing: null,
            registration: ['address' => 'Trunk Road, Nellore'], name: 'Ravi', nationalPhone: '9'.fake()->numerify('#########'),
            plainPassword: 'secret-pass-1',
        );
        if ($approval !== 'pending') {
            $organiser->update(['approval_status' => $approval, 'is_active' => $approval === 'approved']);
        }

        return [$organiser->provider->user, $organiser->fresh(), $franchise];
    }

    /** A complete event form, starting in 30 days (platform time zone). */
    private function fillEvent($page)
    {
        $start = now('Asia/Kolkata')->addDays(30)->setTime(20, 0);

        return $page->set('title', 'Year End Bash')->set('category', 'New Year')
            ->set('venueName', 'Grand Hall')->set('venueAddress', 'Trunk Road, Nellore')
            ->set('startsAt', $start->format('Y-m-d\TH:i'))->set('endsAt', $start->copy()->addHours(5)->format('Y-m-d\TH:i'))
            ->set('minAge', '18')
            ->set('tickets', [
                ['id' => null, 'name' => 'Early Bird', 'description' => '', 'price' => '499', 'quantity' => '50', 'admits' => '1', 'max_per_order' => '10', 'sale_ends_at' => ''],
                ['id' => null, 'name' => 'Couple', 'description' => 'Entry for two', 'price' => '1399', 'quantity' => '40', 'admits' => '2', 'max_per_order' => '5', 'sale_ends_at' => ''],
            ]);
    }

    // ── settings ──

    public function test_gst_bands_fail_closed_and_the_settings_page_is_super_admin_only(): void
    {
        $this->assertNull(EventSettings::gstPercentFor(800), 'No bands set: no ticket can be sold.');
        Setting::set('event.gst_slabs', "500 : 0\n* : 18");
        $this->assertSame(0.0, EventSettings::gstPercentFor(500));
        $this->assertSame(18.0, EventSettings::gstPercentFor(501));
        $this->assertNotNull(EventSettings::validate('event.gst_slabs', '500 - 0'));
        $this->assertSame('10', EventSettings::get('event.commission_percent'));

        Livewire::actingAs($this->makeUserWithNoPermissions())->test(EventSettingsPage::class)->assertForbidden();
        Livewire::actingAs($this->makeSuperAdmin())->test(EventSettingsPage::class)->assertOk()->assertSee('Commission on ticket sales');
    }

    // ── joining ──

    public function test_partner_page_card_hands_off_only_when_super_admin_takes_organisers(): void
    {
        $card = fn () => collect(PartnerPageData::roles())->firstWhere('code', 'events');
        $this->assertNotNull($card(), 'The Events module is in the registry, so /partners shows the card.');
        $this->assertSame('Event organiser', $card()['label']);
        $this->assertFalse($card()['hands_off']);

        Setting::set('event.recruiting_organisers', '1');
        $this->assertTrue($card()['hands_off']);
    }

    public function test_sign_up_page_asks_for_the_organiser_name_when_handed_an_events_lead(): void
    {
        Setting::set('event.recruiting_organisers', '1');
        $lead = \App\Models\PartnerLead::create(['name' => 'Ravi', 'phone' => '9876543210', 'city' => 'Nellore', 'role' => 'events', 'status' => 'handed_off', 'source' => 'partner_page', 'consent_at' => now(), 'consent_text' => 'ok']);
        session()->put('partner_lead', ['id' => $lead->id, 'role' => 'events', 'city' => '']);

        Livewire::test(\App\Livewire\Provider\Auth\Register::class)
            ->assertSet('partnerRole', 'events')
            ->assertSee('Sell your event tickets on')->assertSee('Organiser or company name');
    }

    public function test_sign_up_creates_a_pending_switched_off_organiser_with_its_own_documents(): void
    {
        [$user, $organiser] = $this->organiser();

        $this->assertSame('pending', $organiser->approval_status);
        $this->assertFalse($organiser->is_active);
        $this->assertTrue($user->holdsPartnerRole('events'));
        $this->assertFalse($user->holdsPartnerRole('service'), 'An organiser-only account is not a service partner.');
        $this->assertFalse($user->canApplyAsPartner('events'), 'One organiser per account through sign-up.');
        $this->assertSame(['organiser'], app(\App\Services\Kyc\KycDocumentService::class)->applicableTypesFor($organiser->provider));
    }

    public function test_a_hotel_can_also_become_an_organiser_on_the_same_account(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $user = $this->makeCustomer();
        $organiser = app(RegisterOrganiserAction::class)->execute(zone: $zone, organiserName: 'Party People', existing: $user, registration: ['address' => 'Nellore']);

        $this->assertSame($user->id, $organiser->provider->user_id);
        $this->assertTrue($user->fresh()->holdsPartnerRole('events'));
    }

    // ── portal ──

    public function test_portal_is_for_organisers_only(): void
    {
        [$user] = $this->organiser();
        $this->actingAs($user)->get(route('organiser.dashboard'))->assertOk()->assertSee('Waiting for approval');
        $this->actingAs($this->makeCustomer())->get(route('organiser.dashboard'))->assertForbidden();
    }

    public function test_organiser_builds_an_event_with_ticket_types_and_sends_it_for_review(): void
    {
        Storage::fake('public');
        [$user, $organiser] = $this->organiser('approved');

        $page = $this->fillEvent(Livewire::actingAs($user)->test(EventEditor::class))->call('submit');
        $event = Event::firstOrFail();
        $this->assertSame('draft', $event->status, 'No cover photo yet: saved as a draft, not sent.');
        $page->assertSee('Add a cover photo.');
        $this->assertSame(2, $event->ticketTypes()->count());
        $this->assertSame('year-end-bash', $event->slug);
        $this->assertSame(2, $event->ticketTypes()->where('name', 'Couple')->value('admits'));
        $this->assertSame('14:30', $event->starts_at->format('H:i'), '8 PM in India is stored as 14:30 UTC.');

        $page->set('cover', UploadedFile::fake()->image('poster.jpg', 1600, 900))->call('submit')->assertHasNoErrors();
        $event->refresh();
        $this->assertSame('in_review', $event->status);
        $this->assertNotNull($event->cover_image);

        // In review: locked for the organiser.
        Livewire::actingAs($user)->test(EventEditor::class, ['eventId' => $event->id])->set('title', 'Changed')->call('save')->assertHasErrors('event');
        $this->assertSame('Year End Bash', $event->fresh()->title);
    }

    public function test_ticket_rules_are_checked(): void
    {
        [$user] = $this->organiser();
        $page = $this->fillEvent(Livewire::actingAs($user)->test(EventEditor::class));

        $page->set('tickets.0.price', '0')->set('tickets.1.name', 'Early Bird')->call('save')
            ->assertHasErrors(['tickets.0.price', 'tickets.1.name']);
        $page->set('tickets.0.price', '499')->set('tickets.1.name', 'Couple')->set('tickets.0.max_per_order', '99')->call('save')
            ->assertHasErrors(['tickets.0.max_per_order']);
        $page->set('tickets.0.max_per_order', '10')->set('endsAt', '2020-01-01T10:00')->call('save')->assertHasErrors(['endsAt']);
        $this->assertSame(0, Event::count());
    }

    public function test_an_organiser_never_sees_another_organisers_event(): void
    {
        [$user] = $this->organiser();
        [$other] = $this->organiser();
        $this->fillEvent(Livewire::actingAs($other)->test(EventEditor::class))->call('save');
        $event = Event::firstOrFail();

        $this->actingAs($user)->get(route('organiser.events.edit', $event->id))->assertNotFound();
    }

    public function test_details_and_checklist(): void
    {
        [$user, $organiser] = $this->organiser();
        $this->assertFalse(collect(Dashboard::checklist($organiser))->every('done'));

        Livewire::actingAs($user)->test(Details::class)->set('about', 'DJ nights since 2019.')->set('gstNumber', 'bad')->call('save')->assertHasErrors('gstNumber')
            ->set('gstNumber', '37ABCDE1234F1Z5')->call('save')->assertHasNoErrors();
        $this->fillEvent(Livewire::actingAs($user)->test(EventEditor::class))->call('save');

        $this->assertTrue(collect(Dashboard::checklist($organiser->fresh()))->every('done'));
    }

    // ── admin ──

    public function test_admin_approves_the_organiser_then_the_event_and_can_send_it_back(): void
    {
        Storage::fake('public');
        [$user, $organiser] = $this->organiser();
        $this->fillEvent(Livewire::actingAs($user)->test(EventEditor::class))
            ->set('cover', UploadedFile::fake()->image('poster.jpg', 1600, 900))->call('submit');
        $event = Event::firstOrFail();
        $this->assertSame('in_review', $event->status, 'A pending organiser can already send an event for review.');

        $admin = $this->makeSuperAdmin();
        Livewire::actingAs($admin)->test(EventsManage::class)->call('approveEvent', $event->id);
        $this->assertSame('in_review', $event->fresh()->status, 'Organiser not approved yet: the event waits.');

        Livewire::actingAs($admin)->test(EventsManage::class)->call('setTab', 'organisers')->assertSee('Nellore Nights')->call('approveOrganiser', $organiser->id);
        $this->assertSame('approved', $organiser->fresh()->approval_status);
        $this->assertTrue($organiser->fresh()->is_active);

        Livewire::actingAs($admin)->test(EventsManage::class)->assertSee('Year End Bash')->call('viewEvent', $event->id)->call('approveEvent', $event->id);
        $this->assertSame('approved', $event->fresh()->status);

        Livewire::actingAs($admin)->test(EventsManage::class)->call('rejectEvent', $event->id)->assertHasErrors('rejectReason')
            ->set('rejectReason', 'Please use the real venue photo')->call('rejectEvent', $event->id);
        $this->assertSame('rejected', $event->fresh()->status);
        $this->actingAs($user)->get(route('organiser.events.edit', $event->id))->assertSee('Please use the real venue photo');

        // Sent back: editable again.
        Livewire::actingAs($user)->test(EventEditor::class, ['eventId' => $event->id])->set('title', 'Year End Bash 2026')->call('save')->assertHasNoErrors();
        $this->assertSame('year-end-bash-2026', $event->fresh()->slug);
    }

    public function test_admin_screen_needs_the_permission_and_stays_in_its_own_franchise(): void
    {
        [, $organiser, $franchise] = $this->organiser();
        [, $other] = $this->organiser();

        Livewire::actingAs($this->makeUserWithNoPermissions())->test(EventsManage::class)->assertForbidden();

        $local = $this->makeUserWithPermission('events.manage', 'franchise', $franchise->id);
        Livewire::actingAs($local)->test(EventsManage::class)->call('setTab', 'organisers')
            ->assertSee($organiser->name)->call('approveOrganiser', $other->id)->assertNotFound();
        $this->assertSame('pending', $other->fresh()->approval_status);
    }

    public function test_test_accounts_make_an_approved_organiser_with_an_event_in_review(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'test-pass-123', '--franchise' => $franchise->id])->assertSuccessful();

        $organiser = EventOrganiser::whereHas('provider.user', fn ($q) => $q->where('email', 'test.organiser@1callfix.test'))->firstOrFail();
        $this->assertSame('approved', $organiser->approval_status);
        $this->assertSame(3, $organiser->events()->firstOrFail()->ticketTypes()->count());

        $this->artisan('test-accounts', ['action' => 'disable'])->assertSuccessful();
        $this->assertFalse($organiser->fresh()->is_active);
    }
}
