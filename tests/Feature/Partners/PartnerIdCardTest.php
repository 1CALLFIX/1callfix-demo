<?php

namespace Tests\Feature\Partners;

use App\Models\FieldWorker;
use App\Models\PartnerIdCard;
use App\Models\Provider;
use App\Models\User;
use App\Services\Partners\IdCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-26: the 1CallFix ID card for service partners and riders / drivers — only after KYC approval and with
 * a franchise-approved photo; the QR opens a live Active / Not active check; never the home address or phone; a
 * lost card or yearly renewal makes the old QR fail.
 */
class PartnerIdCardTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** test.provider, test.rider and test.truckdriver come ready (approved, with an approved photo). */
    private function testLogin(string $key): User
    {
        [, , $franchise] = $this->makeFranchiseTree();
        [$category] = $this->makeCategoryAndService(); // the test partner's skills = every active category
        $category->update(['name' => 'AC Repair']);
        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'test-pass-123', '--franchise' => $franchise->id])->assertSuccessful();

        return User::where('email', "test.{$key}@1callfix.test")->firstOrFail();
    }

    private function cardOf(User $user, string $type): PartnerIdCard
    {
        $cards = app(IdCardService::class);

        return $cards->cardFor($user, $cards->holder($user, $type));
    }

    public function test_a_ready_service_partner_sees_the_card_with_qr_and_a_print_button(): void
    {
        $user = $this->testLogin('provider');

        $response = $this->actingAs($user)->get(route('provider.id-card'));

        $response->assertOk();
        $response->assertSee('data-testid="id-card"', false);
        $response->assertSee('PARTNER ID CARD');
        $response->assertSee($user->name);
        $response->assertSee('1CF-');
        $response->assertSee('data:image/png;base64,', false); // the QR
        $response->assertSee('data:image/jpeg;base64,', false); // the approved photo
        $response->assertSee(route('provider.id-card.print'), false);
        $response->assertDontSee($user->phone);
    }

    public function test_the_print_button_gives_a_pdf_of_the_partners_own_card(): void
    {
        $user = $this->testLogin('provider');

        $response = $this->actingAs($user)->get(route('provider.id-card.print'));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_rider_and_the_truck_driver_get_a_rider_card_with_their_work(): void
    {
        $rider = $this->testLogin('rider');
        $this->actingAs($rider)->get(route('rider.id-card'))->assertOk()->assertSee('RIDER ID CARD')->assertSee('Delivery Rider');
        $this->assertStringContainsString('-R', app(IdCardService::class)->code(app(IdCardService::class)->holder($rider, 'rider')));

        $truck = User::where('email', 'test.truckdriver@1callfix.test')->firstOrFail();
        $this->actingAs($truck)->get(route('rider.id-card'))->assertOk()->assertSee('Mini truck driver');
        $this->actingAs($truck)->get(route('rider.id-card.print'))->assertOk();
    }

    public function test_no_card_before_approval_or_without_an_approved_photo(): void
    {
        $user = $this->testLogin('provider');
        $provider = $user->providerProfile;
        $photo = $provider->currentDocuments()->where('type', IdCardService::PHOTO)->first();

        $photo->update(['status' => 'pending']);
        $this->actingAs($user)->get(route('provider.id-card'))->assertOk()->assertSee('Being checked')->assertDontSee('data-testid="id-card"', false);
        $this->actingAs($user)->get(route('provider.id-card.print'))->assertNotFound();

        $photo->update(['status' => 'rejected', 'rejection_reason' => 'Face not clear']);
        $this->actingAs($user)->get(route('provider.id-card'))->assertOk()->assertSee('Face not clear')->assertSee('Add my photo');

        $photo->delete();
        $this->actingAs($user)->get(route('provider.id-card'))->assertOk()->assertSee('Add my photo');

        $provider->update(['kyc_status' => 'pending']);
        $this->actingAs($user)->get(route('provider.id-card'))->assertOk()->assertSee('See my documents')->assertDontSee('Add my photo');
    }

    public function test_a_hotel_or_shop_account_gets_no_person_card(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['skills' => null]);

        $this->actingAs($provider->user)->get(route('provider.id-card'))->assertOk()->assertSee('ID cards are for partners who go to customers');
    }

    public function test_the_qr_page_says_active_now_and_shows_no_phone_or_address(): void
    {
        $user = $this->testLogin('provider');
        $card = $this->cardOf($user, 'provider');

        $response = $this->get(route('partner.verify', $card->token));

        $response->assertOk();
        $response->assertSee('data-status="active"', false);
        $response->assertSee('Active 1CallFix partner');
        $response->assertSee($user->name);
        $response->assertDontSee($user->phone);
        $response->assertSee('noindex', false);
    }

    public function test_the_qr_page_says_not_active_when_the_partner_is_switched_off_or_kyc_withdrawn(): void
    {
        $user = $this->testLogin('provider');
        $card = $this->cardOf($user, 'provider');

        $user->providerProfile->update(['is_active' => false]);
        $this->get(route('partner.verify', $card->token))->assertOk()->assertSee('data-status="not_active"', false)->assertSee('Not active');

        $user->providerProfile->update(['is_active' => true, 'kyc_status' => 'rejected']);
        $this->get(route('partner.verify', $card->token))->assertSee('data-status="not_active"', false);

        $user->providerProfile->update(['kyc_status' => 'approved']);
        $user->update(['status' => 'suspended']);
        $this->get(route('partner.verify', $card->token))->assertSee('data-status="not_active"', false);
    }

    public function test_an_expired_card_says_expired_and_the_partner_gets_a_new_one(): void
    {
        $user = $this->testLogin('provider');
        $card = $this->cardOf($user, 'provider');
        $card->update(['valid_until' => now()->subDay()]);

        $this->get(route('partner.verify', $card->token))->assertSee('data-status="expired"', false);
        $this->actingAs($user)->get(route('provider.id-card'))->assertSee('data-testid="id-card-expired"', false);
        $this->actingAs($user)->get(route('provider.id-card.print'))->assertNotFound();

        Livewire::actingAs($user)->test(\App\Livewire\Provider\IdCard::class)->call('newCard')->assertSee('data-testid="id-card"', false);
        $this->assertTrue($card->fresh()->valid_until->isFuture());
    }

    public function test_i_lost_my_card_makes_the_old_qr_fail_and_the_new_one_work(): void
    {
        $user = $this->testLogin('rider');
        $old = $this->cardOf($user, 'rider')->token;

        Livewire::actingAs($user)->test(\App\Livewire\Rider\IdCard::class)->call('newCard')->assertSee('The old card does not work any more');
        $new = PartnerIdCard::where('user_id', $user->id)->value('token');

        $this->assertNotSame($old, $new);
        $this->get(route('partner.verify', $old))->assertNotFound()->assertSee('Not a valid card');
        $this->get(route('partner.verify', $new))->assertOk()->assertSee('data-status="active"', false);
    }

    public function test_an_unknown_or_odd_token_is_not_a_valid_card(): void
    {
        $this->get(route('partner.verify', str_repeat('a', 32)))->assertNotFound()->assertSee('Not a valid card');
        $this->get('/verify/'.str_repeat('Z', 40))->assertNotFound()->assertSee('Not a valid card');
    }

    public function test_one_partner_cannot_print_anothers_card(): void
    {
        $user = $this->testLogin('provider');
        $rider = User::where('email', 'test.rider@1callfix.test')->firstOrFail();

        // The rider print route only ever prints the signed-in account's own rider card; a service partner has none.
        $this->actingAs($user)->get(route('rider.id-card.print'))->assertStatus(403);
        $this->actingAs($rider)->get(route('rider.id-card.print'))->assertOk();
    }

    public function test_the_id_card_photo_must_be_a_picture_not_a_pdf(): void
    {
        $user = $this->testLogin('provider');
        $user->providerProfile->currentDocuments()->where('type', IdCardService::PHOTO)->delete();

        Livewire::actingAs($user)->test(\App\Livewire\Partner\Join::class)
            ->set('files.provider.'.IdCardService::PHOTO, UploadedFile::fake()->create('me.pdf', 100, 'application/pdf'))
            ->assertHasErrors('files.provider.'.IdCardService::PHOTO);

        Livewire::actingAs($user)->test(\App\Livewire\Partner\Join::class)
            ->set('files.provider.'.IdCardService::PHOTO, UploadedFile::fake()->image('me.jpg', 400, 500))
            ->assertHasNoErrors();
        $this->assertSame('pending', $user->providerProfile->currentDocuments()->where('type', IdCardService::PHOTO)->value('status'));
    }

    public function test_my_id_card_is_on_the_partner_and_rider_more_menus(): void
    {
        $user = $this->testLogin('provider');
        $rider = User::where('email', 'test.rider@1callfix.test')->firstOrFail();

        $this->actingAs($user)->get(route('provider.more'))->assertSee(route('provider.id-card'), false)->assertSee('My ID card');
        $this->actingAs($rider)->get(route('rider.more'))->assertSee(route('rider.id-card'), false)->assertSee('My ID card');
    }
}
