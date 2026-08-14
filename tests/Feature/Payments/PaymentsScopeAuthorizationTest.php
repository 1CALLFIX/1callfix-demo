<?php

namespace Tests\Feature\Payments;

use App\Livewire\Payments\Index as PaymentsIndex;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Row-level scope coverage for the new Payments admin screen, same
 * discipline as 977c240's RowLevelScopeAuthorizationTest: screen access
 * (payments.view) alone isn't enough -- a scoped admin must only see
 * payment rows within their own geography, reached via booking.* (booking
 * purpose) OR user.* (wallet_topup/plan_subscription purpose), scoped by
 * AuthorizationService::scopeQuery()'s array-of-candidate-columns form.
 */
class PaymentsScopeAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    public function test_view_denied_without_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(PaymentsIndex::class)->assertForbidden();
    }

    public function test_booking_purpose_payment_is_scoped_to_the_actors_own_zone(): void
    {
        $mine = $this->makeBookingScenario('searching_provider');
        $other = $this->makeBookingScenario('searching_provider');
        $myPayment = Payment::create(['booking_id' => $mine['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        $otherPayment = Payment::create(['booking_id' => $other['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        $actor = $this->makeUserWithPermission('payments.view', 'zone', $mine['zone']->id);

        $ids = Livewire::actingAs($actor)->test(PaymentsIndex::class)
            ->viewData('payments')->pluck('id')->all();

        $this->assertContains($myPayment->id, $ids);
        $this->assertNotContains($otherPayment->id, $ids);
    }

    public function test_wallet_topup_purpose_payment_is_scoped_to_the_payers_own_zone(): void
    {
        $mine = $this->makeBookingScenario('searching_provider');
        $other = $this->makeBookingScenario('searching_provider');
        $mine['provider']->user->update(['zone_id' => $mine['zone']->id]);
        $other['provider']->user->update(['zone_id' => $other['zone']->id]);
        $myPayment = Payment::create(['user_id' => $mine['provider']->user_id, 'purpose' => 'wallet_topup', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        $otherPayment = Payment::create(['user_id' => $other['provider']->user_id, 'purpose' => 'wallet_topup', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        $actor = $this->makeUserWithPermission('payments.view', 'zone', $mine['zone']->id);

        $ids = Livewire::actingAs($actor)->test(PaymentsIndex::class)
            ->viewData('payments')->pluck('id')->all();

        $this->assertContains($myPayment->id, $ids);
        $this->assertNotContains($otherPayment->id, $ids);
    }

    public function test_search_results_stay_scoped(): void
    {
        $mine = $this->makeBookingScenario('searching_provider');
        $other = $this->makeBookingScenario('searching_provider');
        Payment::create(['booking_id' => $mine['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        Payment::create(['booking_id' => $other['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        $actor = $this->makeUserWithPermission('payments.view', 'zone', $mine['zone']->id);

        $results = Livewire::actingAs($actor)->test(PaymentsIndex::class)
            ->set('search', $other['booking']->code)
            ->viewData('payments');

        $this->assertSame(0, $results->total());
    }

    public function test_super_admin_sees_every_zones_payments(): void
    {
        $mine = $this->makeBookingScenario('searching_provider');
        $other = $this->makeBookingScenario('searching_provider');
        Payment::create(['booking_id' => $mine['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        Payment::create(['booking_id' => $other['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        $admin = $this->makeSuperAdmin();

        $ids = Livewire::actingAs($admin)->test(PaymentsIndex::class)
            ->viewData('payments')->pluck('booking_id')->all();

        $this->assertContains($mine['booking']->id, $ids);
        $this->assertContains($other['booking']->id, $ids);
    }

    public function test_a_global_scoped_grant_sees_every_zones_payments(): void
    {
        $mine = $this->makeBookingScenario('searching_provider');
        $other = $this->makeBookingScenario('searching_provider');
        Payment::create(['booking_id' => $mine['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        Payment::create(['booking_id' => $other['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        $actor = $this->makeUserWithPermission('payments.view', 'global');

        $ids = Livewire::actingAs($actor)->test(PaymentsIndex::class)
            ->viewData('payments')->pluck('booking_id')->all();

        $this->assertContains($mine['booking']->id, $ids);
        $this->assertContains($other['booking']->id, $ids);
    }
}
