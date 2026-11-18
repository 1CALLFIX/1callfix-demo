<?php

namespace Tests\Feature\RentalItems;

use App\Models\Franchise;
use App\Models\RentalItem;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Services\ModuleActivationService;
use App\Services\RentalItems\RentalItemService;
use App\Support\Modules;
use App\Support\RentalKinds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Rent anything (owner decision 2026-11-18, OLX-style), Stage 1: the rules. Anyone with a verified mobile posts; at most
 * 3 items up at a time; banned items refused; the team approves; reports hide an item until the team checks it; each
 * franchise's zone decides whether its items show; the owner's number has a daily limit.
 */
class RentalItemRulesTest extends TestCase
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
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $this->franchise->id, true);
        Setting::set('rental.kind.items', '1');
        $this->service = app(RentalItemService::class);
        $this->team = $this->person();
    }

    private function person(bool $verified = true): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Asha', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'customer', 'status' => 'active', 'phone_verified_at' => $verified ? now() : null,
        ]);
    }

    private function draft(User $owner, array $extra = []): RentalItem
    {
        $title = RentalItem::makeTitle($extra['name'] ?? 'Sony A7 camera', 'Nellore Zone');

        return RentalItem::create($extra + [
            'user_id' => $owner->id, 'franchise_id' => $this->franchise->id, 'zone_id' => $this->zone->id,
            'name' => 'Sony A7 camera', 'title' => $title, 'slug' => RentalItem::uniqueSlug($title),
            'category' => 'Electronics and cameras', 'price' => 800, 'price_unit' => 'Day', 'deposit' => 5000,
            'about' => 'With two lenses and a bag.', 'landmark' => 'Near RTC bus stand',
            'cover_image' => 'items/cover.jpg', 'photos' => ['items/a.jpg'],
        ]);
    }

    private function live(User $owner, array $extra = []): RentalItem
    {
        $item = $this->service->submit($owner, $this->draft($owner, $extra));

        return $this->service->approve($this->team, $item);
    }

    public function test_title_and_slug_are_made_for_the_owner_and_clean(): void
    {
        $this->assertSame('Sony A7 camera for rent in Magunta Layout', RentalItem::makeTitle('  sony   A7 camera ', 'Magunta Layout'));
        $owner = $this->person();
        $a = $this->draft($owner);
        $b = $this->draft($owner);
        $this->assertSame('sony-a7-camera-for-rent-in-nellore-zone', $a->slug);
        $this->assertSame('sony-a7-camera-for-rent-in-nellore-zone-2', $b->slug);
    }

    public function test_only_a_verified_mobile_can_post(): void
    {
        $unverified = $this->person(false);
        $this->assertStringContainsString('verify your mobile', RentalItemService::cannotPostReason($unverified));
        $this->expectExceptionMessage('verify your mobile');
        $this->service->submit($unverified, $this->draft($unverified));
    }

    public function test_a_person_can_have_three_items_up_then_must_take_one_down(): void
    {
        $owner = $this->person();
        $this->live($owner, ['name' => 'Camera']);
        $this->live($owner, ['name' => 'Speaker']);
        $this->service->submit($owner, $this->draft($owner, ['name' => 'Tent'])); // waiting counts too
        $this->assertSame(3, RentalItemService::liveCount($owner));

        try {
            $this->service->submit($owner, $this->draft($owner, ['name' => 'Drill']));
            $this->fail('A fourth item was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('3 items up at a time', $e->getMessage());
        }

        // An expired item no longer counts; the limit is a setting per city / zone.
        RentalItem::where('name', 'Camera')->update(['expires_at' => now()->subDay()]);
        $this->assertSame(2, RentalItemService::liveCount($owner));
        Setting::set('items.max_live_per_person', '5', 'zone', $this->zone->id);
        $this->assertNull(RentalItemService::cannotPostReason($owner, ['zone_id' => $this->zone->id, 'franchise_id' => $this->franchise->id]));
    }

    public function test_banned_items_price_range_and_photos_are_checked_before_review(): void
    {
        $owner = $this->person();
        $cases = [
            [['name' => 'Air pistol'], '"Pistol" cannot be rented out'],
            [['about' => 'Comes with liquor glasses'], '"Liquor" cannot be rented out'],
            [['price' => 5], 'price between ₹20 and ₹50000'],
            [['photos' => []], 'add a cover photo'],
        ];
        foreach ($cases as [$extra, $message]) {
            try {
                $this->service->submit($owner, $this->draft($owner, $extra));
                $this->fail('Accepted: '.json_encode($extra));
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        // Whole words only: "Pistachio grinder" and "Carpet cleaner" are fine.
        $this->assertNull(RentalItemService::bannedWordIn('Pistachio grinder and carpet cleaner'));
        $this->assertSame('in_review', $this->service->submit($owner, $this->draft($owner, ['name' => 'Pistachio grinder']))->status);
    }

    public function test_the_team_approves_or_sends_back_with_a_reason(): void
    {
        $owner = $this->person();
        $item = $this->service->submit($owner, $this->draft($owner));
        $this->assertFalse(RentalItemService::visible($item));

        $back = $this->service->sendBack($this->team, $item, 'Please add a photo of the lens.');
        $this->assertSame('rejected', $back->status);
        $this->assertTrue($back->isEditable());

        $live = $this->service->approve($this->team, $this->service->submit($owner, $back));
        $this->assertTrue(RentalItemService::visible($live));
        $this->assertTrue($live->expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertSame(1, RentalItemService::publicIn($this->city)->count());
    }

    public function test_reports_hide_an_item_until_the_team_dismisses_or_upholds_them(): void
    {
        $owner = $this->person();
        $item = $this->live($owner);
        [$a, $b, $c] = [$this->person(), $this->person(), $this->person()];

        $this->service->report($a, $item, 'scam');
        $this->service->report($b, $item->fresh(), 'wrong_details', 'Photos are from the internet');
        $this->assertTrue(RentalItemService::visible($item->fresh()));
        try {
            $this->service->report($a, $item->fresh(), 'other');
            $this->fail('Reported twice.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already reported', $e->getMessage());
        }
        try {
            $this->service->report($owner, $item->fresh(), 'other');
            $this->fail('Owner reported own item.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('your own item', $e->getMessage());
        }

        $this->service->report($c, $item->fresh(), 'not_allowed');
        $hidden = $item->fresh();
        $this->assertSame(3, $hidden->open_reports);
        $this->assertNotNull($hidden->hidden_for_reports_at);
        $this->assertFalse(RentalItemService::visible($hidden));
        $this->assertSame(0, RentalItemService::publicIn($this->city)->count());

        $back = $this->service->dismissReports($this->team, $hidden);
        $this->assertTrue(RentalItemService::visible($back));
        $this->assertDatabaseMissing('rental_item_reports', ['rental_item_id' => $item->id, 'status' => 'open']);

        $other = $this->live($this->person(), ['name' => 'Speaker']);
        $this->service->report($a, $other, 'not_allowed');
        $sent = $this->service->upholdReports($this->team, $other, 'Not allowed here.');
        $this->assertSame('rejected', $sent->status);
        $this->assertSame('Not allowed here.', $sent->rejection_reason);
        $this->assertDatabaseHas('rental_item_reports', ['rental_item_id' => $other->id, 'status' => 'upheld', 'handled_by' => $this->team->id]);
    }

    /** The owner's model: one zone per franchise. Alif Traders (Nellore West) shows items only once its zone is on. */
    public function test_each_franchise_zone_decides_whether_its_items_show(): void
    {
        $alif = $this->extraFranchise($this->city, 'Alif Traders');
        $west = $this->extraZone($alif, 'Nellore West');
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $alif->id, true);
        $mine = $this->live($this->person(), ['name' => 'Camera']);
        $theirs = $this->live($this->person(), ['name' => 'Tent', 'franchise_id' => $alif->id, 'zone_id' => $west->id]);

        Setting::set('rental.kind.items', '0');
        Setting::set('rental.kind.items', '1', 'franchise', $this->franchise->id);
        $this->assertSame([$mine->id], RentalItemService::publicIn($this->city)->pluck('id')->all());
        $this->assertFalse(RentalItemService::visible($theirs));
        $this->assertTrue(\App\Support\Seo\CityModules::rentalKindLiveIn($this->city, RentalKinds::ITEMS));

        Setting::set('rental.kind.items', '1', 'zone', $west->id);
        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id], RentalItemService::publicIn($this->city)->pluck('id')->all());
    }

    public function test_owner_number_follows_settings_and_the_daily_limit(): void
    {
        $owner = $this->person();
        $first = $this->live($owner, ['name' => 'Camera']);
        $second = $this->live($owner, ['name' => 'Speaker']);
        $customer = $this->person();

        $this->assertSame($owner->phone, $this->service->revealPhone($customer, $first));
        $this->assertDatabaseHas('rental_item_contact_views', ['rental_item_id' => $first->id, 'customer_id' => $customer->id]);

        Setting::set('items.phone_views_per_day', '1');
        $this->assertSame('ok', RentalItemService::phoneAccess($customer, $first)); // seen once: shown again
        $this->assertSame('limit', RentalItemService::phoneAccess($customer, $second));

        Setting::set('items.show_owner_phone', '0');
        $this->expectExceptionMessage('not shown here yet');
        $this->service->revealPhone($customer, $first);
    }

    public function test_rent_anything_is_off_by_default_and_a_rental_settings_switch(): void
    {
        Setting::where('key', 'rental.kind.items')->delete();
        cache()->flush();
        $this->assertFalse(RentalKinds::on(RentalKinds::ITEMS));
        $this->assertArrayHasKey('rental.kind.items', \App\Support\RentalSettings::REGISTRY);
        $this->assertArrayHasKey('items.banned', \App\Support\RentalSettings::REGISTRY);
    }
}
