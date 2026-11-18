<?php

namespace Tests\Feature\RentalItems;

use App\Livewire\Customer\Account\RentItemEditor;
use App\Livewire\Customer\Account\RentItems;
use App\Models\Franchise;
use App\Models\RentalItem;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Services\ModuleActivationService;
use App\Services\RentalItems\RentalItemService;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Rent anything (owner decision 2026-11-18), Stage 2: posting from Account → Rent out your things. Four tap-first steps;
 * the person types the name and price; the item waits for the team; an approved item changes its price at once while a
 * new name or photo goes back for a quick check; one tap hides, shows or renews it.
 */
class RentItemPostingTest extends TestCase
{
    use RefreshDatabase;
    use LiveCity;

    private $city;
    private Franchise $franchise;
    private Zone $zone;
    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->city = $this->liveCity('Nellore');
        $this->franchise = Franchise::where('city_id', $this->city->id)->firstOrFail();
        $this->zone = Zone::where('franchise_id', $this->franchise->id)->firstOrFail();
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $this->franchise->id, true);
        Setting::set('rental.kind.items', '1');
        $this->me = $this->person();
    }

    private function person(bool $verified = true): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Ravi', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'customer', 'status' => 'active', 'phone_verified_at' => $verified ? now() : null,
        ]);
    }

    private function filled($page, string $name = 'JBL party speaker')
    {
        return $page->set('name', $name)->set('category', 'Sound and lights')->set('condition', 'Good')
            ->set('price', '600')->set('priceUnit', 'Day')->set('deposit', '2000')->set('canDeliver', true)
            ->set('about', 'Comes with a mic.')->call('pickZone', $this->zone->id)->set('landmark', 'Near RTC bus stand')
            ->set('cover', UploadedFile::fake()->image('cover.jpg', 1200, 800))
            ->set('newPhotos', [UploadedFile::fake()->image('a.jpg', 1200, 800)]);
    }

    private function approved(string $name = 'JBL party speaker'): RentalItem
    {
        $this->filled(Livewire::actingAs($this->me)->test(RentItemEditor::class), $name)->call('submit')->assertHasNoErrors();
        $item = RentalItem::where('name', $name)->firstOrFail();

        return app(RentalItemService::class)->approve($this->person(), $item);
    }

    public function test_account_shows_rent_out_only_where_posting_is_open(): void
    {
        $this->actingAs($this->me)->get(route('customer.account'))->assertOk()->assertSee('Rent out your things');
        Setting::set('rental.kind.items', '0');
        $this->actingAs($this->me)->get(route('customer.account'))->assertOk()->assertDontSee('Rent out your things');
        $this->actingAs($this->me)->get(route('customer.rent-items'))->assertOk()->assertSee('Posting is not open in your area yet');
    }

    public function test_posting_in_four_taps_sends_the_item_for_review(): void
    {
        $this->actingAs($this->me)->get(route('customer.rent-items.create'))->assertOk()
            ->assertSee('Rent out something')->assertSee('Sound and lights')->assertSee('per day')->assertSee('Nellore Zone');

        $this->filled(Livewire::actingAs($this->me)->test(RentItemEditor::class))->call('submit')
            ->assertHasNoErrors()->assertRedirect(route('customer.rent-items'));

        $item = RentalItem::firstOrFail();
        $this->assertSame('in_review', $item->status);
        $this->assertSame('JBL party speaker for rent in Nellore Zone', $item->title);
        $this->assertSame($this->franchise->id, $item->franchise_id);
        $this->assertTrue($item->can_deliver);
        $this->assertNotNull($item->cover_image);
        $this->assertCount(1, $item->photos);

        Livewire::actingAs($this->me)->test(RentItems::class)->assertSee('JBL party speaker')->assertSee('Waiting for review')->assertSee('1 of 3');
    }

    public function test_missing_taps_say_what_to_do_and_banned_items_are_refused(): void
    {
        Livewire::actingAs($this->me)->test(RentItemEditor::class)->set('category', '')->call('submit')
            ->assertHasErrors(['name', 'category', 'price']);
        $this->assertSame(0, RentalItem::count());

        $this->filled(Livewire::actingAs($this->me)->test(RentItemEditor::class), 'Air rifle')->call('submit')
            ->assertSee('"Rifle" cannot be rented out here.');
        $this->assertSame('draft', RentalItem::firstOrFail()->status);
    }

    public function test_unverified_mobile_and_the_fourth_item_are_stopped_before_typing(): void
    {
        $unverified = $this->person(false);
        Livewire::actingAs($unverified)->test(RentItemEditor::class)->assertSee('verify your mobile number')->assertDontSee('data-testid="item-send"', false);

        $this->approved('Camera');
        $this->approved('Tent');
        $this->approved('Drill');
        Livewire::actingAs($this->me)->test(RentItems::class)->assertSee('3 items up at a time')->assertDontSee('data-testid="items-new"', false);
        Livewire::actingAs($this->me)->test(RentItemEditor::class)->assertSee('3 items up at a time');
    }

    public function test_approved_item_price_changes_at_once_but_a_new_name_goes_back_for_review(): void
    {
        $item = $this->approved();
        $page = Livewire::actingAs($this->me)->test(RentItemEditor::class, ['item' => $item->id]);
        $page->set('price', '750')->call('save')->assertHasNoErrors()->assertSee('The new price shows at once');
        $this->assertSame('approved', $item->fresh()->status);
        $this->assertEquals(750, (float) $item->fresh()->price);

        $page->set('name', 'JBL party speaker with 2 mics')->call('save')->assertSee('We will quickly check your change');
        $fresh = $item->fresh();
        $this->assertSame('in_review', $fresh->status);
        $this->assertSame($item->slug, $fresh->slug); // the public link never changes on a live item
    }

    public function test_one_tap_hides_shows_and_renews_an_approved_item(): void
    {
        $item = $this->approved();
        $list = Livewire::actingAs($this->me)->test(RentItems::class);
        $list->call('notAvailable', $item->id)->assertSee('Hidden by you');
        $this->assertFalse(RentalItemService::visible($item->fresh()));
        $list->call('availableAgain', $item->id)->assertSee('Up');
        $this->assertTrue(RentalItemService::visible($item->fresh()));

        $item->update(['expires_at' => now()->subDay()]);
        $list->call('renew', $item->id)->assertSee('Renewed');
        $this->assertTrue($item->fresh()->expires_at->isFuture());
    }

    public function test_nobody_can_open_or_change_someone_elses_item(): void
    {
        $item = $this->approved();
        $other = $this->person();
        $this->actingAs($other)->get(route('customer.rent-items.edit', $item->id))->assertNotFound();
        Livewire::actingAs($other)->test(RentItems::class)->call('notAvailable', $item->id);
        Livewire::actingAs($other)->test(RentItems::class)->call('renew', $item->id);
        $this->assertTrue($item->fresh()->is_active);
        $this->assertSame($item->expires_at->toDateTimeString(), $item->fresh()->expires_at->toDateTimeString());
    }
}
