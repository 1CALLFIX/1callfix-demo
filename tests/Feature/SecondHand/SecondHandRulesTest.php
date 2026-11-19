<?php

namespace Tests\Feature\SecondHand;

use App\Models\Franchise;
use App\Models\RentalItem;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Services\ModuleActivationService;
use App\Services\RentalItems\RentalItemService;
use App\Support\Modules;
use App\Support\PostTypes;
use App\Support\Seo\CityModules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Second-hand (owner 2026-11-19), Stage 1: people sell used things on the People's posts engine. Its own switch per
 * zone (not the Rental module), its own kinds / banned list / price range / limit, "I have the bill" optional (needed only
 * for kinds Super Admin lists), "Mark as sold". Rent anything posts and Second-hand posts never mix.
 */
class SecondHandRulesTest extends TestCase
{
    use RefreshDatabase;
    use LiveCity;

    private $city;
    private Franchise $franchise;
    private Zone $zone;
    private RentalItemService $service;
    private User $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->city = $this->liveCity('Nellore');
        $this->franchise = Franchise::where('city_id', $this->city->id)->firstOrFail();
        $this->zone = Zone::where('franchise_id', $this->franchise->id)->firstOrFail();
        $this->service = app(RentalItemService::class);
        $this->team = $this->person();
    }

    private function person(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Asha', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'customer', 'status' => 'active', 'phone_verified_at' => now(),
        ]);
    }

    private function sellOn(): void
    {
        Setting::set('secondhand.enabled', '1', 'zone', $this->zone->id);
    }

    private function fields(array $extra = []): array
    {
        return $extra + [
            'deal' => PostTypes::SELL, 'name' => 'Godrej almirah', 'category' => 'Furniture', 'condition' => 'Good',
            'price' => 6500, 'price_unit' => null, 'deposit' => null, 'negotiable' => true, 'has_bill' => false,
            'can_deliver' => false, 'about' => 'Steel, two doors, locker inside.', 'zone_id' => $this->zone->id,
            'landmark' => 'Near RTC bus stand', 'cover_image' => 'items/cover.jpg', 'photos' => ['items/a.jpg'],
        ];
    }

    private function posted(User $owner, array $extra = []): RentalItem
    {
        [$item] = $this->service->save($owner, null, $this->fields($extra));

        return $item;
    }

    private function live(User $owner, array $extra = []): RentalItem
    {
        return $this->service->approve($this->team, $this->service->submit($owner, $this->posted($owner, $extra)));
    }

    public function test_second_hand_has_its_own_switch_per_zone_apart_from_rental(): void
    {
        $this->assertFalse(CityModules::secondHandLiveIn($this->city), 'Off by default everywhere.');

        // Rental + Rent anything on does not open Second-hand...
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $this->franchise->id, true);
        Setting::set('rental.kind.items', '1');
        $this->assertFalse(CityModules::secondHandLiveIn($this->city));
        $this->assertTrue(CityModules::rentalKindLiveIn($this->city, \App\Support\RentalKinds::ITEMS));

        // ...and Second-hand opens with its own zone switch even with Rental off.
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $this->franchise->id, false);
        $this->sellOn();
        $this->assertTrue(CityModules::secondHandLiveIn($this->city));
        $this->assertSame([$this->zone->id], CityModules::postTypeScopesIn($this->city, PostTypes::SELL)['zones']);
        $this->assertSame([$this->zone->id], RentalItemService::postingZones(PostTypes::SELL)->pluck('id')->all());
        $this->assertSame([], RentalItemService::postingZones(PostTypes::RENT)->pluck('id')->all());
    }

    public function test_title_and_link_say_second_hand_and_never_sale(): void
    {
        $this->sellOn();
        $item = $this->posted($this->person());
        $this->assertSame(PostTypes::SELL, $item->deal);
        $this->assertSame('Second-hand Godrej almirah in '.$this->zone->name, $item->title);
        $this->assertSame('second-hand-godrej-almirah-in-'.Str::slug($this->zone->name), $item->slug);
        $this->assertSame('Second-hand sofa set in X', RentalItem::makeTitle('Used sofa set', 'X', PostTypes::SELL), 'Never "Second-hand Used".');
        $this->assertSame('Second-hand Fridge in X', RentalItem::makeTitle('second hand Fridge', 'X', PostTypes::SELL));
        $this->assertStringNotContainsStringIgnoringCase('sale', $item->title.$item->slug);
    }

    public function test_banned_things_bill_rule_and_price_range_use_second_hand_settings(): void
    {
        $this->sellOn();
        $owner = $this->person();

        $scooter = $this->posted($owner, ['name' => 'Honda Activa scooter', 'category' => 'Other']);
        $this->expectRefusal(fn () => $this->service->submit($owner, $scooter), 'cannot be sold here');

        // Owner 2026-11-19: the bill is not mandatory by default ...
        $free = $this->posted($owner, ['name' => 'Samsung M31', 'category' => 'Mobiles and tablets', 'price' => 6000]);
        $this->assertSame('in_review', $this->service->submit($owner, $free)->status);
        $this->assertFalse($free->fresh()->has_bill);

        // ... Super Admin can make it needed for chosen kinds.
        Setting::set('secondhand.bill_kinds', 'Mobiles and tablets');
        $phone = $this->posted($owner, ['name' => 'Redmi Note 12', 'category' => 'Mobiles and tablets', 'price' => 7000]);
        $this->expectRefusal(fn () => $this->service->submit($owner, $phone), 'I have the bill');
        $phone->update(['has_bill' => true]);
        $this->assertSame('in_review', $this->service->submit($owner, $phone->fresh())->status);

        $cheap = $this->posted($owner, ['name' => 'Old chair', 'price' => 20]);
        $this->expectRefusal(fn () => $this->service->submit($owner, $cheap), 'between ₹50 and ₹200000');
    }

    public function test_rent_and_second_hand_limits_are_counted_apart_and_sold_things_free_a_slot(): void
    {
        $this->sellOn();
        Setting::set('secondhand.max_live_per_person', '2');
        $owner = $this->person();
        $first = $this->live($owner);
        $this->live($owner, ['name' => 'Wooden cot']);
        $this->assertSame(2, RentalItemService::liveCount($owner, null, PostTypes::SELL));
        $this->assertSame(0, RentalItemService::liveCount($owner, null, PostTypes::RENT), 'Rent anything has its own count.');
        $this->assertStringContainsString('Mark one as sold', (string) RentalItemService::cannotPostReason($owner, [], null, PostTypes::SELL));

        $this->service->markSold($owner, $first);
        $this->assertNull(RentalItemService::cannotPostReason($owner, [], null, PostTypes::SELL), 'A sold thing frees a slot.');
    }

    public function test_marked_sold_leaves_the_lists_but_its_page_stays_and_the_number_is_not_shown(): void
    {
        $this->sellOn();
        $owner = $this->person();
        $item = $this->live($owner);
        $this->assertTrue(RentalItemService::visible($item));
        $this->assertSame([$item->id], RentalItemService::publicIn($this->city, PostTypes::SELL)->pluck('id')->all());

        $this->expectRefusal(fn () => $this->service->markSold($this->person(), $item), 'Only your approved');
        $sold = $this->service->markSold($owner, $item);
        $this->assertTrue($sold->isSold());
        $this->assertSame('Sold', $sold->statusLabel());
        $this->assertFalse(RentalItemService::visible($sold));
        $this->assertSame([], RentalItemService::publicIn($this->city, PostTypes::SELL)->pluck('id')->all());
        $this->assertSame([$item->id], RentalItemService::publicIn($this->city, PostTypes::SELL, true)->pluck('id')->all(), 'Its page stays up saying Sold.');
        $this->expectRefusal(fn () => $this->service->revealPhone($this->person(), $sold), 'not available');
        $this->expectRefusal(fn () => $this->service->renew($owner, $sold), 'renewed');
        $this->expectRefusal(fn () => $this->service->save($owner, $sold, $this->fields()), 'sold');
    }

    public function test_rent_posts_and_second_hand_posts_never_mix(): void
    {
        $this->sellOn();
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $this->franchise->id, true);
        Setting::set('rental.kind.items', '1');
        $owner = $this->person();
        $sale = $this->live($owner);
        $rent = $this->live($owner, ['deal' => PostTypes::RENT, 'name' => 'Sony camera', 'category' => 'Electronics and cameras', 'price' => 800, 'price_unit' => 'Day', 'negotiable' => false]);

        $this->assertSame([$rent->id], RentalItemService::publicIn($this->city)->pluck('id')->all());
        $this->assertSame([$sale->id], RentalItemService::publicIn($this->city, PostTypes::SELL)->pluck('id')->all());
        $this->expectRefusal(fn () => $this->service->markSold($owner, $rent), 'second-hand');

        // The type is chosen once: changing an approved post never turns it into the other type.
        [$same] = $this->service->save($owner, $sale, $this->fields(['deal' => PostTypes::RENT, 'price' => 6000]));
        $this->assertSame(PostTypes::SELL, $same->deal);
        $this->assertSame('6000.00', (string) $same->price, 'Price changes at once.');
        $this->assertSame('approved', $same->status);
    }

    private function expectRefusal(callable $action, string $words): void
    {
        try {
            $action();
            $this->fail('Expected a refusal containing "'.$words.'".');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($words, $e->getMessage());
        }
    }
}
