<?php

namespace Tests\Feature\Mobile;

use App\Contracts\PushAdapter;
use App\Models\User;
use App\Notifications\Adapters\FirebaseFcmPushAdapter;
use App\Notifications\Channels\PushChannel;
use App\Notifications\ProviderJobOfferNotification;
use App\Notifications\RiderParcelOfferNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Android apps 2026-11-05 (mobile/: customer, rider and partner shells over this site). The server side they need:
 * push token handover between accounts on one phone, offer pushes marked kind=offer, high-priority Android
 * delivery, the signed-in marker the app script reads, and the partner app's start page.
 */
class AndroidAppSupportTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RefreshDatabase;

    public function test_a_phone_token_moves_to_whoever_signs_in_on_that_phone(): void
    {
        $first = $this->makeCustomer();
        $second = $this->makeCustomer();
        $other = $this->makeCustomer();
        $first->update(['fcm_token' => 'phone-1']);
        $other->update(['fcm_token' => 'phone-2']);

        $this->actingAs($second)->postJson('/push/token', ['token' => 'phone-1'])->assertOk();

        $this->assertSame('phone-1', $second->fresh()->fcm_token);
        $this->assertNull($first->fresh()->fcm_token, 'the previous account on this phone stops getting its pushes');
        $this->assertSame('phone-2', $other->fresh()->fcm_token, 'other phones are untouched');
    }

    public function test_job_and_delivery_offers_are_marked_as_offers_and_other_pushes_are_not(): void
    {
        $this->assertSame('offer', (new \ReflectionClass(ProviderJobOfferNotification::class))->newInstanceWithoutConstructor()->pushKind());
        $this->assertSame('offer', (new \ReflectionClass(RiderParcelOfferNotification::class))->newInstanceWithoutConstructor()->pushKind());

        $sent = [];
        $adapter = new class($sent) implements PushAdapter
        {
            public function __construct(public array &$sent)
            {
            }

            public function send(string $token, string $title, string $body, array $data = []): bool
            {
                $this->sent[] = $data;

                return true;
            }
        };
        $user = $this->makeCustomer();
        $user->update(['fcm_token' => 'tok']);

        $offer = new class extends Notification
        {
            public function toPush($n): array
            {
                return ['title' => 'New job', 'body' => 'AC service'];
            }

            public function pushKind(): string
            {
                return 'offer';
            }
        };
        $plain = new class extends Notification
        {
            public function toPush($n): array
            {
                return ['title' => 'Booked', 'body' => 'See you soon'];
            }
        };

        (new PushChannel($adapter))->send($user, $offer);
        (new PushChannel($adapter))->send($user, $plain);

        $this->assertSame('offer', $sent[0]['kind'] ?? null);
        $this->assertArrayNotHasKey('kind', $sent[1]);
    }

    public function test_fcm_messages_ask_android_for_high_priority(): void
    {
        config([
            'services.push.fcm.project_id' => 'onecallfix-test',
            'services.push.fcm.credentials_json' => null,
            'services.push.fcm.credentials_path' => null,
        ]);
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'ok'], 200)]);
        // Skip the OAuth exchange: a cached access token is used as is.
        cache()->put('fcm_access_token', 'cached-token', 3600);

        (new FirebaseFcmPushAdapter)->send('device', 'New job', 'AC service', ['kind' => 'offer']);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com')
            && $r['message']['android']['priority'] === 'high'
            && $r['message']['data']['kind'] === 'offer'
            && ! isset($r['message']['notification']));
    }

    public function test_pages_tell_the_app_script_who_is_signed_in(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('name="cf-signed-in"', false);

        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $this->actingAs($provider->user)->get(route('provider.dashboard'))->assertOk()
            ->assertSee('<meta name="cf-signed-in" content="'.$provider->user->id.'">', false);
    }

    public function test_the_partner_app_start_page_offers_both_sign_ins_and_joining(): void
    {
        $this->get('/app/partner')->assertOk()
            ->assertSee(route('provider.login'), false)
            ->assertSee(route('seller.login'), false)
            ->assertSee(route('customer.partners'), false);
    }

    public function test_the_partner_app_sends_each_signed_in_partner_to_their_own_app(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();

        $provider = $this->makeProviderIn($franchise, $zone);
        $this->actingAs($provider->user)->get('/app/partner')->assertRedirect(route('provider.dashboard'));

        $owner = $this->makeStoreOwner($franchise, $zone);
        $this->makeStore($franchise, $zone, $owner);
        $this->actingAs($owner->user)->get('/app/partner')->assertRedirect(route('seller.dashboard'));

        $rider = $this->makeParcelRiderIn($franchise, $zone);
        $this->actingAs($rider->user)->get('/app/partner')->assertRedirect(route('rider.dashboard'));

        $customer = $this->makeCustomer();
        $this->actingAs($customer)->get('/app/partner')->assertRedirect(route('customer.partners'));
    }
}
