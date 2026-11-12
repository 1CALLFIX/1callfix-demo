<?php

namespace Tests\Feature\Partners;

use App\Actions\ReviewProviderKycAction;
use App\Livewire\Partner\Join;
use App\Livewire\PartnerDocuments\Manage as PartnerDocuments;
use App\Models\KycDocumentRequirement;
use App\Models\PartnerLead;
use App\Models\Setting;
use App\Notifications\KycNotification;
use App\Services\Kyc\KycDocumentService;
use App\Support\Modules;
use App\Support\PartnerPage\PartnerPageSettings as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\HotelFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Partners part B (owner 2026-11-12: "every business ... should feel very easy to join"): each kind of business has its
 * own document list (Super Admin edits it), "Go live" shows progress and a "Help me go live" call request, partners get
 * plain messages through joining, and each role card on /partners can state its terms.
 */
class PartnersPartBTest extends TestCase
{
    use BookingFixtureHelpers;
    use HotelFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private function hotelOwner()
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $hotel = $this->makeAccommodation($franchise, $zone);
        $hotel->provider->update(['kyc_status' => 'pending']);

        return $hotel->provider->fresh();
    }

    // ───────────────────────── a list per business ─────────────────────────

    public function test_each_business_gets_its_own_document_list(): void
    {
        $kyc = app(KycDocumentService::class);
        $hotel = $this->hotelOwner();
        $types = $kyc->requirementsForSubject($hotel)->pluck('document_type')->all();
        $this->assertContains('property_proof', $types);
        $this->assertNotContains('skill_certificate', $types, 'A hotel is never asked for a skill certificate.');

        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $restaurant = $this->makeStore($franchise, $zone, null, ['module' => Modules::FOOD])->provider;
        $this->assertContains('fssai_licence', $kyc->requirementsForSubject($restaurant)->where('is_required', true)->pluck('document_type')->all());

        // A service provider who also opened a shop needs both lists (merged, no duplicates).
        $shop = $this->makeStore($franchise, $zone)->provider;
        $shop->update(['skills' => [1]]);
        $both = $kyc->requirementsForSubject($shop->fresh())->pluck('document_type');
        $this->assertContains('address_proof', $both->all());
        $this->assertSame($both->count(), $both->unique()->count());
    }

    public function test_a_hotel_cannot_be_approved_without_its_property_proof(): void
    {
        $hotel = $this->hotelOwner();
        foreach (['id_proof', 'bank_details'] as $type) {
            $hotel->documents()->create(['type' => $type, 'disk_path' => "x/$type", 'original_filename' => 'x.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10, 'status' => 'approved', 'is_current' => true, 'uploaded_by' => $hotel->user_id, 'upload_source' => 'self']);
        }

        $this->expectExceptionMessage('property_proof');
        app(ReviewProviderKycAction::class)->approve($hotel->id);
    }

    public function test_super_admin_edits_the_lists_and_others_cannot(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $row = KycDocumentRequirement::where('applicable_type', 'hotel')->where('document_type', 'trade_licence')->sole();

        Livewire::test(PartnerDocuments::class)
            ->assertSet('type', 'hotel')
            ->assertSee('Hotels and stays')
            ->set("rows.{$row->id}.is_required", true)
            ->set("rows.{$row->id}.label", 'Hotel licence')
            ->call('save')->assertSet('notice', 'Saved.')
            ->set('newLabel', 'Fire safety certificate')->set('newRequired', false)->call('add')
            ->assertSet('notice', 'Added.');

        $this->assertTrue((bool) $row->fresh()->is_required);
        $this->assertSame('Hotel licence', $row->fresh()->label);
        $this->assertTrue(KycDocumentRequirement::where('applicable_type', 'hotel')->where('document_type', 'fire_safety_certificate')->where('is_required', false)->exists());

        $this->actingAs($this->hotelOwner()->user);
        Livewire::test(PartnerDocuments::class)->assertForbidden();
    }

    // ───────────────────────── Go live: progress, help, messages ─────────────────────────

    public function test_go_live_shows_progress_and_messages_when_the_documents_are_all_in(): void
    {
        Notification::fake();
        $hotel = $this->hotelOwner();

        $c = Livewire::actingAs($hotel->user)->test(Join::class)
            ->assertSee('Property proof')->assertDontSee('Skill certificate')
            ->assertSee('1 of 4 done');
        $c->set('files.provider.id_proof', UploadedFile::fake()->image('id.jpg'))
            ->set('files.provider.property_proof', UploadedFile::fake()->image('deed.jpg'));
        Notification::assertNotSentTo($hotel->user, KycNotification::class);

        $c->set('files.provider.bank_details', UploadedFile::fake()->create('bank.pdf', 50, 'application/pdf'))
            ->assertSet('notice', 'All your documents are in. We are checking them now.')
            ->assertSee('of 4 done');
        Notification::assertSentTo($hotel->user, KycNotification::class, fn ($n) => $n->eventKey() === 'kyc.documents_in');
    }

    public function test_help_me_go_live_asks_the_team_to_call_once_a_day(): void
    {
        $hotel = $this->hotelOwner();

        Livewire::actingAs($hotel->user)->test(Join::class)
            ->call('helpMe')->assertSee('Our team will call you')
            ->call('helpMe');

        $lead = PartnerLead::sole();
        $this->assertSame('help_me_join', $lead->source);
        $this->assertSame(PartnerLead::STATUS_NEW, $lead->status);
        $this->assertSame('hotel', $lead->role);
        $this->assertSame(1, (int) $lead->submit_count, 'A second tap the same day is not a second request.');
    }

    public function test_the_joining_messages_say_what_to_do(): void
    {
        $rejected = new KycNotification('document_rejected', ['mail'], ['document' => 'Property proof', 'reason' => 'the photo is blurred']);
        $mail = $rejected->toMail(null);
        $this->assertSame('Please upload a document again', $mail->subject);
        $this->assertStringContainsString('Property proof: the photo is blurred', implode(' ', $mail->introLines));
        $this->assertSame('Go live', $mail->actionText);

        $this->assertSame('You are approved', (new KycNotification('kyc_approved', ['mail']))->toMail(null)->subject);
    }

    // ───────────────────────── terms on /partners ─────────────────────────

    public function test_each_role_card_can_state_its_terms(): void
    {
        $this->assertSame([], array_intersect_key(P::validate(['role_cards' => json_encode(['hotel' => ['terms' => 'Commission 10% · Paid every week · No joining fee']])]), ['role_cards' => 1]));
        $this->assertArrayHasKey('role_cards', P::validate(['role_cards' => json_encode(['hotel' => ['terms' => str_repeat('x', 141)]])]));

        Setting::set(P::key('role_cards'), json_encode(['hotel' => ['terms' => 'Commission 10% · Paid every week · No joining fee']]));
        cache()->forget(P::CACHE_KEY);
        $this->enableHotelModuleForTests();

        $this->assertSame('Commission 10% · Paid every week · No joining fee', P::roleWording()['hotel']['terms']);
        $this->assertSame('', P::roleWording()['service']['terms'], 'No terms are made up when Super Admin has written none.');
    }
}
