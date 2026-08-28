<?php

namespace Tests\Feature\CustomerWeb;

use App\Actions\AcceptBookingAction;
use App\Actions\CompleteBookingAction;
use App\Actions\StartBookingAction;
use App\Livewire\Customer\Booking\Wizard;
use App\Livewire\Customer\Orders\Show as OrderShow;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\DispatchAttempt;
use App\Models\GeneratedDocument;
use App\Models\Review;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase E6 — the whole customer journey, end to end, exercised through the
 * REAL customer web components and the REAL existing Actions on the
 * provider side. Nothing about booking / dispatch / OTP / settlement /
 * invoice / review is mocked away.
 *
 *   catalog -> wizard -> wallet-paid booking
 *           -> dispatch offer -> provider accepts (E4)
 *           -> customer reads the start OTP off the order page -> provider starts (E5)
 *           -> customer reads the completion OTP -> provider completes (E5)
 *           -> settlement + receipt happen -> customer downloads the receipt
 *           -> customer leaves a review -> customer re-books
 */
class CustomerJourneyE6Test extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    public function test_a_customer_can_go_from_catalog_to_completed_reviewed_and_rebooked(): void
    {
        // ---- world -------------------------------------------------------
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $service = $this->makeService($this->makeCategory(['module' => 'service']), ['base_price' => 500]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $provider = $this->makeProviderIn($franchise, $zone);
        Wallet::create(['user_id' => $customer->id, 'balance' => 5000]);

        // ---- 1. book through the wizard, paid from wallet ---------------
        Livewire::actingAs($customer)
            ->test(Wizard::class, ['service' => $service])
            ->set('addressId', $address->id)
            ->call('next')->call('next')->call('next')
            ->assertSet('step', 'pay')
            ->set('paymentMethod', 'wallet')
            ->call('placeBooking')
            ->assertRedirect();

        $booking = Booking::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('paid', $booking->payment_status);
        $this->assertEquals(4500.0, (float) $customer->wallet->fresh()->balance);

        // ---- 2. dispatch offer + provider accepts (E4) -----------------
        $booking->update(['status' => 'searching_provider']);
        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);
        $accepted = app(AcceptBookingAction::class)->execute($booking->id, $provider);
        $this->assertSame('assigned', $accepted->status);

        // ---- 3. customer reads the start OTP off the order page --------
        Livewire::actingAs($customer)->test(OrderShow::class, ['booking' => $booking->fresh()])
            ->assertSee($accepted->start_otp)
            ->assertSee('Professional assigned');

        // provider starts with the code the customer read to them (E5)
        app(StartBookingAction::class)->execute($booking->id, $accepted->start_otp);
        $this->assertSame('in_progress', $booking->fresh()->status);

        // ---- 4. customer reads the completion OTP; provider completes --
        $completionOtp = $accepted->fresh()->completion_otp;
        Livewire::actingAs($customer)->test(OrderShow::class, ['booking' => $booking->fresh()])
            ->assertSee($completionOtp)
            ->assertDontSee('When they arrive'); // start code already consumed

        app(CompleteBookingAction::class)->execute($booking->id, $provider, $completionOtp);
        $booking->refresh();
        $this->assertSame('completed', $booking->status);

        // ---- 5. settlement + receipt materialised (E5) ----------------
        $this->assertSame(1, Commission::where('booking_id', $booking->id)->count());
        $this->assertSame(1, GeneratedDocument::where('type', 'receipt')->count());

        // customer downloads it from the web
        $this->actingAs($customer)->get(route('customer.orders.invoice', $booking))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // ---- 6. review, then re-book --------------------------------
        Livewire::actingAs($customer)->test(OrderShow::class, ['booking' => $booking->fresh()])
            ->set('rating', 5)
            ->set('comment', 'On time and tidy.')
            ->call('submitReview')
            ->assertSee('Thanks for the review');

        $this->assertDatabaseHas('reviews', ['booking_id' => $booking->id, 'rating' => 5]);

        // "Book this again" points at a fresh wizard for the same service,
        // which will re-price from the server (not the historical amount).
        Livewire::actingAs($customer)->test(OrderShow::class, ['booking' => $booking->fresh()])
            ->assertSee(route('customer.book', $service), false);
    }
}
