<?php

namespace Tests\Feature\SecondHand;

use App\Contracts\PaymentGateway;
use App\Livewire\Customer\Account\RentItems;
use App\Livewire\Customer\Account\SecondHandItems;
use App\Livewire\RentalItems\Manage;
use App\Models\Franchise;
use App\Models\ListingFee;
use App\Models\RentalItem;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Services\Homes\ListingFeeService;
use App\Services\ModuleActivationService;
use App\Services\RentalItems\PostPackService;
use App\Services\RentalItems\RentalItemService;
use App\Services\WalletService;
use App\Support\Modules;
use App\Support\PostTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Paid packs for people's posts (owner 2026-11-19): Featured, Boost and two bulk Featured packs, every number a Super
 * Admin setting (price 0 = off). Paid online through the HQ gateway (activated once by the webhook, exact amount only) or
 * from the wallet; the franchise share is fixed on each fee; Featured / Boost end when their days run out.
 */
class PostPacksTest extends TestCase
{
    use LiveCity;
    use RbacTestHelpers;
    use RefreshDatabase;

    private $city;
    private Franchise $franchise;
    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->city = $this->liveCity('Nellore');
        $this->franchise = Franchise::where('city_id', $this->city->id)->firstOrFail();
        $this->zone = Zone::where('franchise_id', $this->franchise->id)->firstOrFail();
        Setting::set('secondhand.enabled', '1', 'zone', $this->zone->id);
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $this->franchise->id, true);
        Setting::set('rental.kind.items', '1');
    }

    private function prices(array $values = []): void
    {
        foreach ($values + [
            'packs.featured_fee' => 49, 'packs.featured_days' => 7, 'packs.boost_fee' => 199, 'packs.boost_days' => 7, 'packs.boost_spots' => 2,
            'packs.bulk_small_count' => 5, 'packs.bulk_small_fee' => 199, 'packs.bulk_large_fee' => 349, 'packs.bulk_valid_days' => 60,
            'packs.franchise_share_percent' => 30,
        ] as $k => $v) {
            Setting::set($k, (string) $v);
        }
    }

    private function person(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Ravi', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'customer', 'status' => 'active', 'phone_verified_at' => now(),
        ]);
    }

    private function listing(?User $owner = null, string $name = 'Godrej almirah', string $deal = PostTypes::SELL, array $extra = []): RentalItem
    {
        $title = RentalItem::makeTitle($name, $this->zone->name, $deal);

        return RentalItem::create($extra + [
            'user_id' => ($owner ?? $this->person())->id, 'franchise_id' => $this->franchise->id, 'zone_id' => $this->zone->id, 'deal' => $deal,
            'name' => $name, 'title' => $title, 'slug' => RentalItem::uniqueSlug($title), 'category' => 'Furniture', 'condition' => 'Good',
            'price' => 6500, 'price_unit' => $deal === PostTypes::SELL ? null : 'Day', 'negotiable' => true, 'about' => 'Steel.',
            'cover_image' => 'https://example.test/cover.jpg', 'photos' => ['https://example.test/a.jpg'],
            'status' => 'approved', 'is_active' => true, 'approved_at' => now()->subDays(2), 'submitted_at' => now()->subDays(3), 'expires_at' => now()->addDays(30),
        ]);
    }

    private function fund(User $u, int $rupees): void
    {
        app(WalletService::class)->credit($u, (float) $rupees, 'test top-up', 'test:pp:'.Str::random(6));
    }

    private function fakeGateway(): void
    {
        $mock = Mockery::mock(PaymentGateway::class);
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('identifier')->andReturn('razorpay');
        $mock->shouldReceive('createRawOrder')->andReturnUsing(fn ($amount) => [
            'razorpay_order_id' => 'order_'.Str::random(6), 'amount' => (int) round($amount * 100), 'currency' => 'INR', 'razorpay_key_id' => 'rzp_test',
        ]);
        $this->app->instance(PaymentGateway::class, $mock);
    }

    public function test_packs_are_off_until_the_super_admin_sets_a_price(): void
    {
        $owner = $this->person();
        $item = $this->listing($owner);

        $this->assertSame([], PostPackService::offer($item));
        Livewire::actingAs($owner)->test(SecondHandItems::class)->assertDontSee('Get more calls');

        $this->expectException(\RuntimeException::class);
        app(PostPackService::class)->payFromWallet($owner, $item, 'featured');
    }

    public function test_featured_from_the_wallet_puts_the_post_first_with_a_tag_and_records_the_franchise_share(): void
    {
        $this->prices();
        $owner = $this->person();
        $older = $this->listing($owner, 'Wooden cot');
        $this->listing(null, 'Steel table', PostTypes::SELL, ['approved_at' => now()]);
        $this->fund($owner, 100);

        Livewire::actingAs($owner)->test(SecondHandItems::class)
            ->assertSee('Get more calls')
            ->call('openPacks', $older->id)->assertSee('Featured')->assertSee('Boost')->assertSee('5 Featured posts')
            ->call('pickPack', 'featured')->call('payPack', 'wallet')->assertHasNoErrors()
            ->assertSee('Featured! Your post shows first');

        $older->refresh();
        $this->assertTrue($older->isFeaturedNow());
        $this->assertEqualsWithDelta(7, now()->diffInDays($older->featured_until), 1);
        $this->assertSame(51.0, (float) app(WalletService::class)->balance($owner));
        $fee = ListingFee::where('listing_type', 'item')->sole();
        $this->assertSame(['featured', 'wallet', 'paid'], [$fee->kind, $fee->method, $fee->status]);
        $this->assertSame(14.7, (float) $fee->franchise_share, '30% of ₹49');

        $html = $this->get(route('customer.secondhand.home', $this->city->slug))->assertOk()->assertSee('⭐ Featured')->getContent();
        $this->assertLessThan(strpos($html, 'Steel table'), strpos($html, 'Wooden cot'), 'the Featured post is listed first');
    }

    public function test_boost_online_starts_only_for_the_exact_amount_once_and_fills_the_boosted_row(): void
    {
        $this->prices();
        $this->fakeGateway();
        $owner = $this->person();
        $item = $this->listing($owner);

        $order = app(PostPackService::class)->startOnline($owner, $item, 'boost');
        $fee = ListingFee::where('gateway_order_id', $order['razorpay_order_id'])->sole();
        $service = app(ListingFeeService::class);

        $this->assertSame('amount_mismatch', $service->handleCaptured($order['razorpay_order_id'], 'pay_1', 100)['outcome']);
        $this->assertFalse($item->refresh()->isBoostedNow());

        $this->assertSame('captured', $service->handleCaptured($order['razorpay_order_id'], 'pay_1', 19900)['outcome']);
        $until = $item->refresh()->boosted_until;
        $this->assertTrue($item->isBoostedNow());
        $this->assertTrue($item->isFeaturedNow(), 'Boost includes Featured');
        $this->assertSame('already_processed', $service->handleCaptured($order['razorpay_order_id'], 'pay_1', 19900)['outcome']);
        $this->assertTrue($item->refresh()->boosted_until->equalTo($until), 'a repeated webhook adds no days');
        $this->assertSame(59.7, (float) $fee->refresh()->franchise_share);

        $this->get(route('customer.secondhand.home', $this->city->slug))->assertOk()
            ->assertSee('data-testid="boosted-row"', false)->assertSee('🚀 Boosted near you');
        $this->get(route('customer.rent.home', $this->city->slug))->assertOk()->assertDontSee('data-testid="boosted-row"', false);
    }

    public function test_boost_spots_per_city_are_limited_and_extending_a_running_boost_needs_no_new_spot(): void
    {
        $this->prices(['packs.boost_spots' => 1]);
        $first = $this->person();
        $boosted = $this->listing($first, 'Fridge', PostTypes::SELL, ['boosted_until' => now()->addDays(3), 'is_featured' => true, 'featured_until' => now()->addDays(3)]);
        $second = $this->person();
        $other = $this->listing($second, 'Sofa');
        $this->fund($first, 500);
        $this->fund($second, 500);

        $boost = collect(PostPackService::offer($other))->firstWhere('key', 'boost');
        $this->assertStringContainsString('All Boost spots in your city are taken', $boost['blocked']);
        try {
            app(PostPackService::class)->payFromWallet($second, $other, 'boost');
            $this->fail('No Boost spot is free.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('taken', $e->getMessage());
        }
        $this->assertSame(500.0, (float) app(WalletService::class)->balance($second), 'nothing taken');

        $this->assertNull(collect(PostPackService::offer($other))->firstWhere('key', 'featured')['blocked'], 'Featured still works');
        app(PostPackService::class)->payFromWallet($first, $boosted, 'boost');
        $this->assertEqualsWithDelta(10, now()->diffInDays($boosted->refresh()->boosted_until), 1, 'days added to the running Boost');

        $rentPost = $this->listing($second, 'Speaker', PostTypes::RENT);
        $this->assertSame(1, PostPackService::boostSpotsLeft($rentPost), 'rent and second-hand have their own spots');
    }

    public function test_a_bulk_pack_features_this_post_now_and_the_rest_later_until_they_run_out(): void
    {
        $this->prices();
        $owner = $this->person();
        $a = $this->listing($owner, 'Cot');
        $b = $this->listing($owner, 'Chair');
        $this->fund($owner, 500);

        $offer = collect(PostPackService::offer($a))->firstWhere('key', 'bulk_small');
        $this->assertSame(18, $offer['saving'], '₹199 for 5 against 5 × ₹49 = ₹245');

        app(PostPackService::class)->payFromWallet($owner, $a, 'bulk_small');
        $this->assertTrue($a->refresh()->isFeaturedNow());
        $this->assertSame(4, PostPackService::creditsLeft($owner));

        Livewire::actingAs($owner)->test(SecondHandItems::class)
            ->assertSee('You have 4 Featured posts left')
            ->call('openPacks', $b->id)->call('useCredit', $b->id)->assertHasNoErrors();
        $this->assertTrue($b->refresh()->isFeaturedNow());
        $this->assertSame(3, PostPackService::creditsLeft($owner));
        $this->assertSame(301.0, (float) app(WalletService::class)->balance($owner), 'using a Featured from the pack costs nothing');

        ListingFee::where('kind', 'bulk_small')->update(['credits_until' => now()->subMinute()]);
        $this->assertSame(0, PostPackService::creditsLeft($owner));
        $this->expectException(\RuntimeException::class);
        app(PostPackService::class)->useCredit($owner, $b->refresh());
    }

    public function test_only_the_owner_and_only_a_live_post_can_get_a_pack(): void
    {
        $this->prices();
        $owner = $this->person();
        $item = $this->listing($owner);
        $stranger = $this->person();
        $this->fund($stranger, 500);
        $this->fund($owner, 500);

        foreach ([[$stranger, $item], [$owner, tap($this->listing($owner, 'Old fan'))->update(['sold_at' => now(), 'is_active' => false])]] as [$who, $post]) {
            try {
                app(PostPackService::class)->payFromWallet($who, $post->refresh(), 'featured');
                $this->fail('Refused.');
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame(0, ListingFee::count());
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::actingAs($stranger)->test(SecondHandItems::class)->call('openPacks', $item->id);
    }

    public function test_featured_and_boost_end_when_their_days_run_out_and_a_sold_post_loses_them(): void
    {
        $this->prices();
        $owner = $this->person();
        $item = $this->listing($owner, 'Cot', PostTypes::SELL, ['is_featured' => true, 'featured_until' => now()->subMinute(), 'boosted_until' => now()->subMinute()]);
        $teamPick = $this->listing($owner, 'Table', PostTypes::SELL, ['is_featured' => true, 'featured_until' => null]);

        $this->assertFalse($item->isFeaturedNow());
        $this->assertSame(2, PostPackService::endLapsed());
        $this->assertFalse($item->refresh()->is_featured);
        $this->assertNull($item->boosted_until);
        $this->assertTrue($teamPick->refresh()->isFeaturedNow(), 'a post the team featured with no end date stays');

        $sold = $this->listing($owner, 'Lamp', PostTypes::SELL, ['is_featured' => true, 'featured_until' => now()->addDays(3), 'boosted_until' => now()->addDays(3)]);
        app(RentalItemService::class)->markSold($owner, $sold);
        $this->assertFalse($sold->refresh()->isFeaturedNow());
        $this->assertFalse($sold->isBoostedNow());
    }

    public function test_rent_posts_get_the_same_packs(): void
    {
        $this->prices();
        $owner = $this->person();
        $item = $this->listing($owner, 'Sony camera', PostTypes::RENT);
        $this->fund($owner, 100);

        Livewire::actingAs($owner)->test(RentItems::class)->call('openPacks', $item->id)->call('pickPack', 'featured')->call('payPack', 'wallet')->assertHasNoErrors();

        $this->assertTrue($item->refresh()->isFeaturedNow());
        $this->get(route('customer.rent.home', $this->city->slug))->assertOk()->assertSee('⭐ Featured');
    }

    public function test_the_franchise_sees_its_pack_money_and_share_on_peoples_posts(): void
    {
        $this->prices();
        $owner = $this->person();
        $this->fund($owner, 500);
        app(PostPackService::class)->payFromWallet($owner, $this->listing($owner), 'boost');

        $team = $this->makeUserWithPermission('rental_items.manage', 'franchise', $this->franchise->id);
        Livewire::actingAs($team)->test(Manage::class)
            ->assertSee('data-testid="pack-money"', false)->assertSee('₹199')->assertSee('₹59.70');

        $other = $this->liveCity('Guntur');
        $otherTeam = $this->makeUserWithPermission('rental_items.manage', 'franchise', Franchise::where('city_id', $other->id)->value('id'));
        Livewire::actingAs($otherTeam)->test(Manage::class)->assertDontSee('data-testid="pack-money"', false);
    }
}
