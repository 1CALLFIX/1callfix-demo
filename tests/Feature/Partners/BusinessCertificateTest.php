<?php

namespace Tests\Feature\Partners;

use App\Models\BusinessCertificate;
use App\Models\Store;
use App\Models\User;
use App\Services\Partners\CertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-26: a "Verified 1CallFix Partner" certificate for shops, restaurants, hotels, function halls and event
 * organisers: on the phone and as a PDF (certificate + "Scan to order" stickers), only once 1CallFix approved the
 * business, with a QR that opens the same live check as the ID card (/verify/{token}).
 */
class BusinessCertificateTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    /** test login => [app, kind, letter in the partner ID] */
    private const LOGINS = [
        'test.seller' => ['seller', 'shop', 'S'],
        'test.restaurant' => ['seller', 'restaurant', 'F'],
        'test.hotel' => ['hotel', 'hotel', 'H'],
        'test.hall' => ['venue', 'hall', 'V'],
        'test.organiser' => ['organiser', 'organiser', 'E'],
    ];

    private function testLogins(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'test-pass-123', '--franchise' => $franchise->id])->assertSuccessful();
    }

    private function login(string $name): User
    {
        return User::where('email', $name.'@1callfix.test')->firstOrFail();
    }

    private function business(string $name)
    {
        return app(CertificateService::class)->businesses($this->login($name), self::LOGINS[$name][0])->first();
    }

    public function test_every_business_test_login_gets_its_certificate_on_home_the_screen_and_the_pdf(): void
    {
        $this->testLogins();

        foreach (self::LOGINS as $name => [$app, $kind, $letter]) {
            $user = $this->login($name);
            $business = $this->business($name);

            $this->actingAs($user)->get(route($app.'.dashboard'))->assertOk()
                ->assertSee('data-testid="certificate-card"', false)
                ->assertSee(route($app.'.certificate'), false);

            $this->actingAs($user)->get(route($app.'.certificate'))->assertOk()
                ->assertSee('data-certificate="'.$kind.'"', false)
                ->assertSee('data-certificate-sticker="'.$kind.'"', false)
                ->assertSee('CERTIFICATE OF VERIFICATION')
                ->assertSee($business->name)
                ->assertSee('-'.$letter.str_pad((string) $business->id, 4, '0', STR_PAD_LEFT))
                ->assertSee(route($app.'.certificate.print', $business->id), false);

            $pdf = $this->actingAs($user)->get(route($app.'.certificate.print', $business->id));
            $pdf->assertOk();
            $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'), $name);
        }

        $this->assertSame(BusinessCertificate::count(), BusinessCertificate::distinct()->count('token'));
    }

    public function test_the_certificate_qr_opens_a_live_check_and_the_sticker_opens_the_business_page(): void
    {
        $this->testLogins();
        $user = $this->login('test.restaurant');
        $store = $this->business('test.restaurant');
        $service = app(CertificateService::class);
        $certificate = $service->certificateFor($user, $store);
        $data = $service->data($certificate, $store);

        $this->assertSame(route('customer.food.restaurant', [$store->franchise->city->slug, $store->slug]), $data['public_url']);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $data['public_qr']);

        $this->get(route('partner.verify', $certificate->token))->assertOk()
            ->assertSee('data-status="active"', false)
            ->assertSee('Verified 1CallFix partner')
            ->assertSee($store->name)
            ->assertSee($data['public_url'], false)
            ->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', (string) $this->get(route('partner.verify', $certificate->token))->headers->get('Cache-Control'));

        // Switched off by 1CallFix: the same printed certificate now fails the scan.
        $store->update(['is_active' => false]);
        $this->get(route('partner.verify', $certificate->token))->assertOk()
            ->assertSee('data-status="not_active"', false)
            ->assertDontSee($data['public_url'], false);

        // The owner's account suspended: also not active.
        $store->update(['is_active' => true]);
        $user->forceFill(['status' => 'suspended'])->save();
        $this->get(route('partner.verify', $certificate->token))->assertSee('data-status="not_active"', false);
    }

    public function test_an_expired_certificate_says_so_and_this_years_one_replaces_it(): void
    {
        $this->testLogins();
        $user = $this->login('test.hotel');
        $hotel = $this->business('test.hotel');
        $certificate = app(CertificateService::class)->certificateFor($user, $hotel);
        $certificate->update(['valid_until' => now()->subDay()]);
        $old = $certificate->token;

        $this->get(route('partner.verify', $old))->assertSee('data-status="expired"', false);
        $this->actingAs($user)->get(route('hotel.certificate.print', $hotel->id))->assertNotFound();
        $this->actingAs($user)->get(route('hotel.certificate'))->assertOk()->assertSee('Get this year');

        \Livewire\Livewire::actingAs($user)->test(\App\Livewire\Hotel\Certificate::class)
            ->call('renew', $hotel->id)
            ->assertSee('Your new certificate is ready');

        $fresh = $certificate->fresh();
        $this->assertNotSame($old, $fresh->token);
        $this->assertTrue($fresh->valid_until->isFuture());
        $this->get(route('partner.verify', $old))->assertNotFound()->assertSee('Not a valid card');
        $this->get(route('partner.verify', $fresh->token))->assertSee('data-status="active"', false);
    }

    public function test_a_certificate_that_is_still_valid_cannot_be_replaced(): void
    {
        $this->testLogins();
        $user = $this->login('test.organiser');
        $organiser = $this->business('test.organiser');
        $token = app(CertificateService::class)->certificateFor($user, $organiser)->token;

        \Livewire\Livewire::actingAs($user)->test(\App\Livewire\Organiser\Certificate::class)
            ->call('renew', $organiser->id)
            ->assertForbidden();

        $this->assertSame($token, BusinessCertificate::firstOrFail()->token);
    }

    public function test_a_business_waiting_for_approval_has_no_certificate_yet(): void
    {
        $this->testLogins();
        $user = $this->login('test.seller');
        $store = $this->business('test.seller');
        $store->update(['approval_status' => 'pending']);

        $this->actingAs($user)->get(route('seller.dashboard'))->assertOk()->assertDontSee('data-testid="certificate-card"', false);
        $this->actingAs($user)->get(route('seller.certificate'))->assertOk()
            ->assertSee('data-state="pending"', false)
            ->assertSee('Being checked')
            ->assertDontSee('data-certificate="shop"', false);
        $this->actingAs($user)->get(route('seller.certificate.print', $store->id))->assertNotFound();
        $this->assertSame(0, BusinessCertificate::count());
    }

    public function test_nobody_can_print_another_partners_certificate(): void
    {
        $this->testLogins();
        $theirs = $this->business('test.restaurant');

        $this->actingAs($this->login('test.seller'))->get(route('seller.certificate.print', $theirs->id))->assertNotFound();
        $this->actingAs($this->login('test.provider'))->get(route('seller.certificate'))->assertForbidden();
        $this->assertSame(0, BusinessCertificate::count());
    }

    public function test_the_seller_more_menu_has_my_certificate(): void
    {
        $this->testLogins();

        $this->actingAs($this->login('test.seller'))->get(route('seller.more'))->assertOk()
            ->assertSee('My certificate')
            ->assertSee(route('seller.certificate'), false);
    }

    public function test_the_certificate_never_shows_the_owners_phone(): void
    {
        $this->testLogins();
        $user = $this->login('test.seller');
        $user->forceFill(['phone' => '9123456780'])->save();
        $store = $this->business('test.seller');

        $this->actingAs($user)->get(route('seller.certificate'))->assertOk()->assertDontSee('9123456780');
        \Illuminate\Support\Facades\Auth::logout(); // a customer who scans it
        $this->get(route('partner.verify', BusinessCertificate::firstOrFail()->token))->assertOk()->assertDontSee('9123456780');
        $this->assertInstanceOf(Store::class, $store);
    }
}
