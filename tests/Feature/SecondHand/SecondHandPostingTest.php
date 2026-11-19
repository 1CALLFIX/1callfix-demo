<?php

namespace Tests\Feature\SecondHand;

use App\Livewire\Customer\Account\RentItemEditor;
use App\Livewire\Customer\Account\SecondHandEditor;
use App\Livewire\Customer\Account\SecondHandItems;
use App\Models\Franchise;
use App\Models\RentalItem;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Services\RentalItems\RentalItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Second-hand (owner 2026-11-19), Stage 2: Account → Second-hand: post your used things. The same four tap-first
 * steps as Rent anything, with one price and "Price can be discussed", "I have the bill" (optional; needed only for
 * kinds Super Admin lists), no per-day price and no deposit; "Mark as sold" on the list.
 */
class SecondHandPostingTest extends TestCase
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
        Setting::set('secondhand.enabled', '1', 'zone', $this->zone->id);
        $this->me = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Ravi', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'customer', 'status' => 'active', 'phone_verified_at' => now(),
        ]);
    }

    private function filled($page, string $name = 'Godrej almirah', string $kind = 'Furniture')
    {
        return $page->set('name', $name)->set('category', $kind)->set('condition', 'Good')
            ->set('price', '6500')->set('negotiable', true)->set('about', 'Steel, two doors.')
            ->call('pickZone', $this->zone->id)->set('landmark', 'Near RTC bus stand')
            ->set('cover', UploadedFile::fake()->image('cover.jpg', 1200, 800))
            ->set('newPhotos', [UploadedFile::fake()->image('a.jpg', 1200, 800)]);
    }

    public function test_account_shows_second_hand_only_where_it_is_on(): void
    {
        $this->actingAs($this->me)->get(route('customer.account'))->assertOk()->assertSee('Second-hand: post your used things')->assertDontSee('Rent out your things');
        Setting::set('secondhand.enabled', '0', 'zone', $this->zone->id);
        $this->actingAs($this->me)->get(route('customer.account'))->assertOk()->assertDontSee('Second-hand: post your used things');
        $this->actingAs($this->me)->get(route('customer.secondhand-items'))->assertOk()->assertSee('Posting is not open in your area yet');
    }

    public function test_posting_a_used_thing_in_four_steps_sends_it_for_review(): void
    {
        $this->actingAs($this->me)->get(route('customer.secondhand-items.create'))->assertOk()
            ->assertSee('Post a used thing')->assertSee('Furniture')->assertSee('Price can be discussed')->assertSee('I have the bill')
            ->assertDontSee('per day')->assertDontSee('Deposit');

        $this->filled(Livewire::actingAs($this->me)->test(SecondHandEditor::class))->call('submit')
            ->assertHasNoErrors()->assertRedirect(route('customer.secondhand-items'));

        $item = RentalItem::firstOrFail();
        $this->assertSame('sell', $item->deal);
        $this->assertSame('in_review', $item->status);
        $this->assertSame('Second-hand Godrej almirah in '.$this->zone->name, $item->title);
        $this->assertTrue($item->negotiable);
        $this->assertNull($item->price_unit);
        $this->assertNull($item->deposit);
        $this->assertStringStartsWith('second-hand/', (string) $item->cover_image);

        Livewire::actingAs($this->me)->test(SecondHandItems::class)
            ->assertSee('Your second-hand posts')->assertSee('Godrej almirah')->assertSee('Waiting for review')
            ->assertSee('can be discussed')->assertSee('1 of 5 used things');
    }

    public function test_the_bill_is_optional_by_default(): void
    {
        $this->filled(Livewire::actingAs($this->me)->test(SecondHandEditor::class), 'Redmi Note 12', 'Mobiles and tablets')
            ->assertSee('I have the bill')->assertSee('Optional. Buyers trust things with a bill more.')->assertDontSee('Needed for')
            ->call('submit')->assertHasNoErrors()->assertRedirect(route('customer.secondhand-items'));
        $this->assertFalse(RentalItem::firstOrFail()->has_bill);
    }

    public function test_a_phone_needs_the_bill_ticked_when_super_admin_lists_the_kind(): void
    {
        Setting::set('secondhand.bill_kinds', "Mobiles and tablets\nLaptops and computers");
        $page = $this->filled(Livewire::actingAs($this->me)->test(SecondHandEditor::class), 'Redmi Note 12', 'Mobiles and tablets')
            ->assertSee('Needed for Mobiles and tablets')->call('submit')->assertHasErrors('item');
        $this->assertStringContainsString('I have the bill', (string) $page->errors()->first('item'));
        $page->set('hasBill', true)->call('submit')->assertHasNoErrors()->assertRedirect(route('customer.secondhand-items'));
        $this->assertTrue(RentalItem::firstOrFail()->has_bill);
    }

    public function test_mark_as_sold_from_the_list(): void
    {
        $this->filled(Livewire::actingAs($this->me)->test(SecondHandEditor::class))->call('submit');
        $item = app(RentalItemService::class)->approve($this->me, RentalItem::firstOrFail());

        Livewire::actingAs($this->me)->test(SecondHandItems::class)->assertSee('Mark as sold')->assertDontSee('Not available now')
            ->call('markSold', $item->id)->assertSee('Marked sold')->assertSee('Sold')->assertDontSee('Mark as sold');
        $this->assertTrue($item->fresh()->isSold());
    }

    public function test_each_editor_opens_only_its_own_kind_of_post(): void
    {
        $this->filled(Livewire::actingAs($this->me)->test(SecondHandEditor::class))->call('save');
        $sale = RentalItem::firstOrFail();

        $this->actingAs($this->me)->get(route('customer.secondhand-items.edit', $sale->id))->assertOk()->assertSee('Godrej almirah');
        $this->actingAs($this->me)->get(route('customer.rent-items.edit', $sale->id))->assertNotFound();
        Livewire::actingAs($this->me)->test(\App\Livewire\Customer\Account\RentItems::class)->assertDontSee('Godrej almirah');
        $this->assertSame(RentItemEditor::class, get_parent_class(SecondHandEditor::class));
    }
}
