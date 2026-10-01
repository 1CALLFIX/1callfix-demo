<?php

namespace Tests\Feature\Journey;

use App\Livewire\Bookings\Show as AdminBookingShow;
use App\Livewire\Customer\Orders\Show as CustomerOrderShow;
use App\Livewire\Provider\Jobs\Show as ProviderJobShow;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-JOURNEY-001 — the journey on the three screens that used to show a bare status list:
 * customer order page, provider job page, admin booking page (with operator controls).
 */
class JourneyScreensTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    // ---------------------------------------------------------------- customer

    public function test_customer_sees_a_cancelled_job_with_the_reason_fee_and_wallet_refund(): void
    {
        $s = $this->makeBookingScenario('searching_provider');
        $booking = $s['booking'];
        $booking->statusHistory()->create(['status' => 'searching_provider', 'note' => 'Dispatch started', 'changed_at' => now()->subMinutes(11)]);
        $booking->update(['status' => 'cancelled', 'payment_status' => 'refunded', 'cancellation_fee' => 0, 'cancellation_note' => 'Platform dispatch failure — no provider could be found within the allotted dispatch window.']);
        $booking->statusHistory()->create(['status' => 'cancelled', 'note' => 'Cancelled by admin: Platform dispatch failure', 'changed_at' => now()]);
        Payment::create(['booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'refunded', 'refunded_amount' => 500]);
        app(WalletService::class)->credit($s['customer'], 500, 'Booking refund', "booking:{$booking->id}:wallet-refund");

        $this->actingAs($s['customer']);

        Livewire::test(CustomerOrderShow::class, ['booking' => $booking])
            ->assertSee('Cancelled')
            ->assertSee("We couldn't find a professional within the search window.")
            ->assertSee('No cancellation fee.')
            ->assertSee('500.00 refunded to your 1CallFix wallet.');
    }

    public function test_a_cancelled_job_shows_the_refund_amount_even_when_the_payment_row_has_no_refunded_amount_and_no_progress_bar(): void
    {
        $s = $this->makeBookingScenario('cancelled');
        $booking = $s['booking'];
        $booking->update(['payment_status' => 'refunded', 'cancellation_fee' => 0, 'cancellation_note' => 'Platform dispatch failure — no provider could be found within the allotted dispatch window.']);
        $booking->statusHistory()->create(['status' => 'cancelled', 'note' => 'Cancelled by admin: Platform dispatch failure', 'changed_at' => now()]);
        Payment::create(['booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 1, 'gateway' => 'razorpay', 'status' => 'refunded']); // refunded_amount not stamped
        app(WalletService::class)->credit($s['customer'], 1, 'Booking refund', "booking:{$booking->id}:wallet-refund");
        $this->actingAs($s['customer']);

        Livewire::test(CustomerOrderShow::class, ['booking' => $booking])
            ->assertSee('1.00 refunded to your 1CallFix wallet.')
            ->assertDontSee('% complete')
            ->assertDontSeeHtml('role="progressbar"');
    }

    public function test_customer_sees_the_cash_refund_destination_and_fee_when_it_went_back_to_the_source(): void
    {
        $s = $this->makeBookingScenario('assigned');
        $booking = $s['booking'];
        $booking->update(['status' => 'cancelled', 'payment_status' => 'refunded', 'cancellation_fee' => 99, 'cancellation_note' => 'Cancelled by customer from the web app']);
        $booking->statusHistory()->create(['status' => 'cancelled', 'note' => 'Cancelled by admin: Cancelled by customer from the web app (cancellation fee: 99)', 'changed_at' => now()]);
        Payment::create(['booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'refunded', 'refunded_amount' => 401]);

        $this->actingAs($s['customer']);

        Livewire::test(CustomerOrderShow::class, ['booking' => $booking])
            ->assertSee('You cancelled this booking.')
            ->assertSee('Cancellation fee: ₹99.00.')
            ->assertSee('refunded to your original payment method');
    }

    public function test_customer_sees_a_scheduled_pending_booking_waiting_for_payment(): void
    {
        $s = $this->makeBookingScenario('pending');
        $s['booking']->update(['scheduled_at' => now()->addDays(2), 'payment_status' => 'pending', 'payment_method' => 'online']);
        $this->actingAs($s['customer']);

        Livewire::test(CustomerOrderShow::class, ['booking' => $s['booking']])
            ->assertSee('Booked')
            ->assertSee('as soon as your payment is confirmed')
            ->assertSee('Payment pending');
    }

    public function test_customer_sees_the_spares_hold_while_it_lasts(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'in_progress']);
        app(\App\Actions\PlaceBookingOnHoldAction::class)->execute($s['booking']->id, 'awaiting_spares');
        $this->actingAs($s['customer']);

        Livewire::test(CustomerOrderShow::class, ['booking' => $s['booking']])
            ->assertSee('Job on hold')
            ->assertSee('Waiting for spare parts')
            ->assertSee('Spares available')
            ->assertSee('Work resumed');
    }

    // ---------------------------------------------------------------- provider web

    public function test_provider_runs_the_spares_loop_from_the_job_screen(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $this->actingAs($s['provider']->user);

        // From "assigned" the hold is refused with a clear message.
        Livewire::test(ProviderJobShow::class, ['booking' => $s['booking']])
            ->call('holdForSpares')
            ->assertSet('error', 'You can wait for spare parts once the job is in progress.');
        $this->assertSame('assigned', $s['booking']->fresh()->status);

        $s['booking']->update(['status' => 'in_progress']);
        $c = Livewire::test(ProviderJobShow::class, ['booking' => $s['booking']]);

        $c->call('holdForSpares')->assertSet('error', '')->assertSee('Job on hold')->assertSee('Spares available');
        $this->assertSame('on_hold', $s['booking']->fresh()->status);

        $c->call('sparesAvailable')->assertSee('Resume work');
        $c->call('resumeJob')->assertSet('notice', 'Work resumed.');
        $this->assertSame('in_progress', $s['booking']->fresh()->status);
        $this->assertNull($s['booking']->fresh()->hold_reason);
    }

    public function test_provider_cannot_resume_a_hold_that_is_not_for_spares(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'in_progress']);
        app(\App\Actions\PlaceBookingOnHoldAction::class)->execute($s['booking']->id, 'awaiting_customer_approval');
        $this->actingAs($s['provider']->user);

        Livewire::test(ProviderJobShow::class, ['booking' => $s['booking']])
            ->call('resumeJob')
            ->assertSet('error', 'Only a job held for spare parts can be resumed from here — your dispatcher handles other holds.');
        $this->assertSame('on_hold', $s['booking']->fresh()->status);
    }

    public function test_another_provider_gets_a_404_on_the_job_screen_and_its_actions(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $other = $this->makeBookingScenario('assigned');
        $this->actingAs($other['provider']->user);

        Livewire::test(ProviderJobShow::class, ['booking' => $s['booking']])->assertNotFound();
    }

    // ---------------------------------------------------------------- admin

    public function test_operator_can_hold_mark_spares_and_resume_with_permission(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'in_progress']);
        $admin = $this->makeSuperAdmin();

        $c = Livewire::actingAs($admin)->test(AdminBookingShow::class, ['bookingId' => $s['booking']->id])
            ->set('holdReason', 'awaiting_spares')->set('holdNote', 'Part ordered')
            ->call('holdJob')->assertSet('flashType', 'success');
        $this->assertSame('on_hold', $s['booking']->fresh()->status);
        $this->assertSame('awaiting_spares', $s['booking']->fresh()->hold_reason);

        $c->call('markSparesAvailable')->assertSet('flashMessage', 'Marked: spare parts available.');
        $c->call('resumeJob')->assertSet('flashMessage', 'Job resumed.');
        $this->assertSame('in_progress', $s['booking']->fresh()->status);
    }

    public function test_a_job_that_has_not_started_cannot_be_put_on_hold_by_the_operator(): void
    {
        $s = $this->makeAssignedBookingScenario(); // status: assigned
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(AdminBookingShow::class, ['bookingId' => $s['booking']->id])
            ->call('holdJob')
            ->assertSet('flashType', 'error')
            ->assertSet('flashMessage', 'A job can be put on hold once it is in progress.');

        $this->assertSame('assigned', $s['booking']->fresh()->status);
    }

    public function test_admin_without_the_dispatcher_permission_cannot_control_the_job(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'in_progress']);
        $viewer = $this->makeUserWithPermission('bookings.view', 'global');

        Livewire::actingAs($viewer)->test(AdminBookingShow::class, ['bookingId' => $s['booking']->id])
            ->call('holdJob')
            ->assertSet('flashType', 'error')
            ->assertSet('flashMessage', 'You do not have permission to control this job.');

        $this->assertSame('in_progress', $s['booking']->fresh()->status);
    }

    public function test_admin_page_shows_the_journey(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->statusHistory()->create(['status' => 'assigned', 'note' => 'accepted', 'changed_at' => now()]);

        Livewire::actingAs($this->makeSuperAdmin())->test(AdminBookingShow::class, ['bookingId' => $s['booking']->id])
            ->assertSeeHtml('aria-label="Service job progress"')
            ->assertSee('Professional assigned');
    }
}
