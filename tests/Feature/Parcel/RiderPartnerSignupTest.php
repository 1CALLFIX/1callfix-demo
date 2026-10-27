<?php

namespace Tests\Feature\Parcel;

use App\Livewire\Partner\ApplyForm;
use App\Livewire\Provider\Auth\Register;
use App\Livewire\Rider\Auth\Login as RiderLogin;
use App\Livewire\Rider\Dashboard as RiderDashboard;
use App\Livewire\Workers\Show as WorkerShow;
use App\Models\FieldWorker;
use App\Models\FieldWorkerDocument;
use App\Models\PartnerLead;
use App\Models\Provider;
use App\Models\User;
use App\Models\Zone;
use App\Services\Parcel\ParcelReadiness;
use App\Support\PartnerPage\PartnerPageData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * Owner rule 2026-10-25: every partner joins through /partners, then the same sign-up — OTP, documents, admin
 * approval. Delivery riders now go that way: the rider card hands off once parcel prices are set (before parcel
 * opens to customers), the one sign-up page makes a pending rider with the field-worker document list, and the
 * admin approves the documents and the rider on Partners → People → Review.
 */
class RiderPartnerSignupTest extends TestCase
{
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->bindFakeFirebase();
        \App\Models\City::create(['country_id' => \App\Models\Country::create(['name' => 'Testland', 'code' => 'TL', 'currency_code' => 'INR', 'default_timezone' => 'Asia/Kolkata', 'is_active' => true])->id, 'name' => 'Nellore', 'slug' => 'nellore', 'is_active' => true]);
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => 14.4400000, 'center_lng' => 79.9800000]);
        $this->zone = $zone->fresh();
    }

    /** Parcel built and priced, but not switched on for customers anywhere. */
    private function recruiting(): void
    {
        $this->enableParcelModuleForTests();
        $this->configureParcelPricing();
    }

    private function riderCard(): array
    {
        return collect(PartnerPageData::roles())->firstWhere('code', 'parcel');
    }

    private function applyAsRider(string $phone): Testable
    {
        return Livewire::test(ApplyForm::class)
            ->set('role', 'parcel')->set('name', 'Suresh Rider')->set('phone', $phone)
            ->set('cityChoice', 'nellore')->set('consent', true)->call('submit');
    }

    /** The /partners hand-off, then the sign-up filled in with the field-worker documents. */
    private function signUpRider(string $phone): Testable
    {
        $this->applyAsRider($phone)->assertSet('outcome', 'handoff');

        $c = Livewire::test(Register::class)->assertSet('partnerRole', 'parcel')->assertSet('phone', $phone);
        $token = $this->firebase->issuePhoneToken($this->e164($phone));
        $c->call('requestPhoneCode')->call('phoneTokenReceived', $token)->assertSet('step', 'details');

        return $c->call('useCurrentLocationForNewAddress', 14.44, 79.98)
            ->set('password', 'longenough1')->set('password_confirmation', 'longenough1')
            ->set('address', 'Magunta Layout, Nellore')->set('terms', true)
            ->set('documents.id_proof', UploadedFile::fake()->image('id.jpg'))
            ->set('documents.address_proof', UploadedFile::fake()->image('addr.png'))
            ->set('documents.bank_details', UploadedFile::fake()->create('bank.pdf', 120, 'application/pdf'))
            ->set('documents.driving_licence', UploadedFile::fake()->image('dl.jpg'));
    }

    private function reviewer(): User
    {
        $u = $this->makeCustomer();
        $u->forceFill(['role' => 'super_admin'])->save();

        return $u;
    }

    public function test_rider_card_stays_a_waitlist_until_parcel_prices_are_set(): void
    {
        $this->enableParcelModuleForTests();
        $this->assertFalse($this->riderCard()['hands_off']);

        $this->applyAsRider($this->randomPhone())->assertSet('outcome', 'waitlist')->assertDontSee('Continue to sign-up');
        $this->assertSame(PartnerLead::STATUS_WAITLIST, PartnerLead::sole()->status);
    }

    public function test_rider_card_hands_off_once_prices_are_set_even_before_parcel_is_switched_on(): void
    {
        $this->recruiting();

        $card = $this->riderCard();
        $this->assertTrue($card['hands_off']);
        $this->assertFalse($card['live'], 'customers still cannot book parcel');

        $this->get(route('customer.partners'))->assertOk()->assertSeeText('Now hiring');

        $phone = $this->randomPhone();
        $this->applyAsRider($phone)->assertSet('outcome', 'handoff')->assertSee('Continue to sign-up');
        $lead = PartnerLead::sole();
        $this->assertSame(PartnerLead::STATUS_HANDED_OFF, $lead->status);
        $this->assertSame(['id' => $lead->id, 'role' => 'parcel', 'city' => 'nellore'], session('partner_lead'));
    }

    public function test_the_sign_up_page_asks_a_rider_for_the_field_worker_documents_and_no_services(): void
    {
        $this->recruiting();
        $this->applyAsRider($this->randomPhone());

        Livewire::test(Register::class)
            ->assertSet('partnerRole', 'parcel')
            ->assertSee('delivery rider')
            ->assertSee('Driving licence')
            ->assertSeeHtml('wire:model="documents.driving_licence"')
            ->assertDontSeeHtml('id="pr-services"');
    }

    public function test_a_rider_signs_up_as_a_pending_rider_with_documents_and_the_lead_is_converted(): void
    {
        $this->recruiting();
        $phone = $this->randomPhone();

        $this->signUpRider($phone)->call('submitApplication')->assertHasNoErrors()->assertSet('submitted', true)
            ->assertSee('Go to rider sign in');

        $user = User::where('phone', $phone)->sole();
        $this->assertNotNull($user->phone_verified_at);
        $this->assertSame(0, Provider::count(), 'a rider is a field worker, never a provider');

        $rider = FieldWorker::where('user_id', $user->id)->sole();
        $this->assertSame('pending', $rider->kyc_status);
        $this->assertTrue($rider->is_active);
        $this->assertSame($this->zone->id, $rider->zone_id);
        $this->assertSame('two_wheeler', $rider->fresh()->vehicle_type);
        $this->assertTrue($rider->hasCapability('parcel_rider'));
        $this->assertEqualsCanonicalizing(
            ['id_proof', 'address_proof', 'bank_details', 'driving_licence'],
            $rider->currentDocuments()->where('status', 'pending')->pluck('type')->all(),
        );

        $lead = PartnerLead::sole();
        $this->assertSame(PartnerLead::STATUS_CONVERTED, $lead->status);
        $this->assertNull($lead->provider_id);

        // Not approved yet: does not count towards switching parcel on.
        $this->assertSame(0, app(ParcelReadiness::class)->riderCount('franchise', $rider->franchise_id));
    }

    public function test_required_rider_documents_are_enforced(): void
    {
        $this->recruiting();

        $this->signUpRider($this->randomPhone())->set('documents.bank_details', null)->call('submitApplication')
            ->assertHasErrors(['documents.bank_details'])->assertSet('submitted', false);
        $this->assertSame(0, FieldWorker::count());
    }

    public function test_a_number_that_already_belongs_to_a_partner_is_told_to_sign_in(): void
    {
        $this->recruiting();
        $existing = $this->makeProviderIn($this->zone->franchise, $this->zone)->user;

        $this->applyAsRider($existing->phone);
        Livewire::test(Register::class)->call('requestPhoneCode')
            ->assertHasErrors(['phone']);
        $this->assertSame(0, FieldWorker::count());
    }

    public function test_an_existing_customer_applies_as_a_rider_on_the_same_account(): void
    {
        $this->recruiting();
        $customer = $this->makeCustomer();
        $customer->forceFill(['password' => \Illuminate\Support\Facades\Hash::make('my-old-pass-1')])->save();

        $this->signUpRider($customer->phone)->assertSet('existingUserId', $customer->id)
            ->set('password', '')->set('password_confirmation', '')
            ->call('submitApplication')->assertHasNoErrors()->assertSet('submitted', true);

        $this->assertSame(1, User::where('phone', $customer->phone)->count(), 'no second account');
        $rider = FieldWorker::where('user_id', $customer->id)->sole();
        $this->assertSame('pending', $rider->kyc_status);
        $this->assertTrue($rider->hasCapability('parcel_rider'));
        $fresh = $customer->fresh();
        $this->assertSame('customer', $fresh->role);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('my-old-pass-1', $fresh->password), 'password unchanged');
    }

    public function test_without_a_rider_hand_off_the_page_is_the_provider_sign_up(): void
    {
        $this->recruiting();

        Livewire::test(Register::class)->assertSet('partnerRole', 'service')->assertDontSee('delivery rider');
    }

    public function test_a_rider_hand_off_left_over_after_recruiting_closes_is_ignored(): void
    {
        $this->recruiting();
        $this->applyAsRider($this->randomPhone());
        \App\Models\Setting::set('parcel.base_fare', null);

        Livewire::test(Register::class)->assertSet('partnerRole', 'service')->assertSet('leadId', null)->assertSet('phone', '');
    }

    public function test_a_pending_rider_can_sign_in_but_cannot_go_online(): void
    {
        $this->recruiting();
        $phone = $this->randomPhone();
        $this->signUpRider($phone)->call('submitApplication');

        Livewire::test(RiderLogin::class)->set('identifier', $phone)->set('password', 'longenough1')->call('login')
            ->assertRedirect(route('rider.dashboard'));

        $rider = FieldWorker::sole();
        Livewire::actingAs($rider->user)->test(RiderDashboard::class)
            ->assertSee('Your documents are under review')
            ->assertDontSee('Go online')
            ->call('goOnline', 14.44, 79.98)
            ->assertSet('error', fn ($e) => str_contains($e, 'under review'));
        $this->assertFalse($rider->fresh()->is_online);
    }

    public function test_admin_approves_each_document_then_the_rider_who_then_counts_for_parcel(): void
    {
        $this->recruiting();
        $this->signUpRider($this->randomPhone())->call('submitApplication');
        $rider = FieldWorker::sole();
        $admin = $this->reviewer();

        $page = Livewire::actingAs($admin)->test(WorkerShow::class, ['workerId' => $rider->id]);

        // Required documents still pending: the existing approval gate refuses.
        $page->call('approve')->assertSet('flashType', 'error');
        $this->assertSame('pending', $rider->fresh()->kyc_status);

        foreach ($rider->currentDocuments()->get() as $doc) {
            $page->call('approveDocument', $doc->id)->assertSet('flashType', 'success');
        }
        $this->assertSame($admin->id, $rider->currentDocuments()->first()->reviewed_by);

        $page->call('approve')->assertSet('flashType', 'success');
        $this->assertSame('approved', $rider->fresh()->kyc_status);
        $this->assertSame(1, app(ParcelReadiness::class)->riderCount('franchise', $rider->franchise_id));

        Livewire::actingAs($rider->user)->test(RiderDashboard::class)->assertDontSee('under review')->assertSee('Go online');
    }

    public function test_rejecting_a_document_needs_a_reason_and_only_touches_this_riders_documents(): void
    {
        $this->recruiting();
        $this->signUpRider($this->randomPhone())->call('submitApplication');
        $rider = FieldWorker::sole();
        $doc = $rider->currentDocuments()->where('type', 'id_proof')->sole();

        $other = $this->makeParcelRiderIn($rider->franchise, $rider->zone);
        $foreign = FieldWorkerDocument::create(['field_worker_id' => $other->id, 'type' => 'id_proof', 'status' => 'pending', 'is_current' => true]);

        $page = Livewire::actingAs($this->reviewer())->test(WorkerShow::class, ['workerId' => $rider->id]);

        $page->call('rejectDocument', $doc->id)->assertSet('flashType', 'error');
        $this->assertSame('pending', $doc->fresh()->status);

        $page->set("documentRejectionReason.{$doc->id}", 'Photo is blurred')->call('rejectDocument', $doc->id)->assertSet('flashType', 'success');
        $this->assertSame('rejected', $doc->fresh()->status);
        $this->assertSame('Photo is blurred', $doc->fresh()->rejection_reason);

        $page->call('approveDocument', $foreign->id)->assertSet('flashType', 'error');
        $this->assertSame('pending', $foreign->fresh()->status);
    }
}
