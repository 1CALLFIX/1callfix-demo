<?php

namespace Tests\Feature\Parcel;

use App\Actions\CreateParcelOrderAction;
use App\Livewire\Customer\Parcel\Show;
use App\Livewire\Parcel\ChatBox;
use App\Livewire\Rider\Dashboard as RiderDashboard;
use App\Models\ParcelOrder;
use App\Models\ParcelOrderMessage;
use App\Models\ParcelRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Parcel Stage 2 — chat between the sender and the rider while the parcel is on its way, the "rider is X km away"
 * line with an Open map link, and the sender's one-time star rating after delivery.
 */
class ParcelChatAndRatingTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function world(string $status = 'picked_up'): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);
        $rider = $this->makeParcelRiderIn($franchise, $zone);
        $order = app(CreateParcelOrderAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id,
            'recipient_phone' => '9876543210', 'payment_method' => 'cash', 'restricted_items_confirmed' => true,
        ]);
        $order->update(['status' => $status, 'assigned_worker_id' => $rider->id, 'picked_up_at' => now(), 'delivery_otp' => '2222']);

        return compact('franchise', 'zone', 'customer', 'rider', 'order');
    }

    public function test_sender_and_rider_chat_while_the_parcel_is_on_its_way(): void
    {
        $w = $this->world();

        Livewire::actingAs($w['customer'])->test(ChatBox::class, ['orderId' => $w['order']->id])
            ->set('body', 'Please ring the bell')->call('send')->assertSet('error', '')->assertSee('Please ring the bell');
        Livewire::actingAs($w['rider']->user)->test(ChatBox::class, ['orderId' => $w['order']->id])
            ->assertSee('Please ring the bell')
            ->set('body', 'OK, 5 minutes')->call('send')->assertSee('OK, 5 minutes');

        $this->assertSame(2, ParcelOrderMessage::where('parcel_order_id', $w['order']->id)->count());
        // Reading the thread marks the other side's messages read.
        $this->assertNotNull(ParcelOrderMessage::where('body', 'Please ring the bell')->value('read_at'));
    }

    public function test_someone_else_cannot_open_or_post_in_the_chat(): void
    {
        $w = $this->world();
        $outsider = $this->makeCustomer();

        Livewire::actingAs($outsider)->test(ChatBox::class, ['orderId' => $w['order']->id])->assertStatus(404);
        $this->actingAs($outsider, 'sanctum')->getJson("/api/parcel-orders/{$w['order']->id}/messages")->assertNotFound();
        $this->actingAs($outsider, 'sanctum')->postJson("/api/parcel-orders/{$w['order']->id}/messages", ['body' => 'hi'])->assertNotFound();
        $this->assertSame(0, ParcelOrderMessage::count());
    }

    public function test_chat_closes_after_delivery_but_history_stays_readable(): void
    {
        $w = $this->world();
        ParcelOrderMessage::create(['parcel_order_id' => $w['order']->id, 'sender_id' => $w['customer']->id, 'body' => 'Leave at gate']);
        $w['order']->update(['status' => 'delivered', 'delivered_at' => now()]);

        Livewire::actingAs($w['customer'])->test(ChatBox::class, ['orderId' => $w['order']->id])
            ->assertSee('Leave at gate')->assertDontSee('Type a message');
        $this->actingAs($w['customer'], 'sanctum')->postJson("/api/parcel-orders/{$w['order']->id}/messages", ['body' => 'Thanks'])
            ->assertStatus(409);
        $this->actingAs($w['customer'], 'sanctum')->getJson("/api/parcel-orders/{$w['order']->id}/messages")
            ->assertOk()->assertJsonPath('data.open', false)->assertJsonPath('data.messages.0.body', 'Leave at gate');
    }

    public function test_chat_works_through_the_api_for_both_sides(): void
    {
        $w = $this->world();

        $this->actingAs($w['rider']->user, 'sanctum')->postJson("/api/parcel-orders/{$w['order']->id}/messages", ['body' => 'At the gate'])
            ->assertCreated();
        $this->actingAs($w['customer'], 'sanctum')->getJson("/api/parcel-orders/{$w['order']->id}/messages")
            ->assertOk()->assertJsonPath('data.open', true)
            ->assertJsonPath('data.messages.0.body', 'At the gate')
            ->assertJsonPath('data.messages.0.from', 'rider')
            ->assertJsonPath('data.messages.0.mine', false);
    }

    public function test_the_chat_shows_on_both_pages(): void
    {
        $w = $this->world();

        Livewire::actingAs($w['customer'])->test(Show::class, ['parcelOrder' => $w['order']])->assertSeeLivewire(ChatBox::class);
        Livewire::actingAs($w['rider']->user)->test(RiderDashboard::class)->assertSeeLivewire(ChatBox::class);
    }

    public function test_the_sender_sees_how_far_away_the_rider_is_only_while_the_location_is_fresh(): void
    {
        $w = $this->world();
        $w['rider']->update(['current_lat' => 1.0, 'current_lng' => 1.0, 'location_updated_at' => now()]);

        Livewire::actingAs($w['customer'])->test(Show::class, ['parcelOrder' => $w['order']])
            ->assertSee('km</span> from the drop', false)->assertSee('Open map');

        $w['rider']->update(['location_updated_at' => now()->subMinutes(30)]);
        Livewire::actingAs($w['customer'])->test(Show::class, ['parcelOrder' => $w['order']])->assertDontSee('Open map');
    }

    public function test_the_sender_rates_the_rider_once_after_delivery(): void
    {
        $w = $this->world();

        // Not yet delivered: no rating form, and the service refuses.
        Livewire::actingAs($w['customer'])->test(Show::class, ['parcelOrder' => $w['order']])->assertDontSee('How was your rider?');
        $this->actingAs($w['customer'], 'sanctum')->postJson("/api/parcel-orders/{$w['order']->id}/rating", ['rating' => 5])->assertStatus(409);

        $w['order']->update(['status' => 'delivered', 'delivered_at' => now()]);
        Livewire::actingAs($w['customer'])->test(Show::class, ['parcelOrder' => $w['order']])
            ->assertSee('How was your rider?')
            ->set('stars', 4)->set('ratingComment', 'Quick')->call('rate')
            ->assertSet('error', '')->assertSee('You rated your rider');

        $this->assertSame(4, ParcelRating::where('parcel_order_id', $w['order']->id)->value('rating'));
        $this->assertEquals(4.0, (float) $w['rider']->fresh()->rating_avg);

        // Only once.
        $this->actingAs($w['customer'], 'sanctum')->postJson("/api/parcel-orders/{$w['order']->id}/rating", ['rating' => 1])->assertStatus(409);
        $this->assertSame(1, ParcelRating::count());
    }

    public function test_rating_through_the_api_and_only_by_the_sender(): void
    {
        $w = $this->world('delivered');
        $this->actingAs($this->makeCustomer(), 'sanctum')->postJson("/api/parcel-orders/{$w['order']->id}/rating", ['rating' => 5])->assertNotFound();
        $this->actingAs($w['customer'], 'sanctum')->postJson("/api/parcel-orders/{$w['order']->id}/rating", ['rating' => 6])->assertStatus(422);
        $this->actingAs($w['customer'], 'sanctum')->postJson("/api/parcel-orders/{$w['order']->id}/rating", ['rating' => 5])->assertCreated();
        $this->assertEquals(5.0, (float) $w['rider']->fresh()->rating_avg);
    }
}
