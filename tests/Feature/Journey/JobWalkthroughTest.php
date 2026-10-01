<?php

namespace Tests\Feature\Journey;

use App\Livewire\Bookings\Show as AdminBookingShow;
use App\Livewire\Customer\Orders\Show as CustomerOrderShow;
use App\Livewire\Provider\Jobs\Show as ProviderJobShow;
use App\Models\BookingExtraItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/** REF 1CF-JOURNEY-001 / 1CF-EXTRAWORK-001 — the extra-work and provider-left lanes, driven through the three real screens. */
class JobWalkthroughTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private function inProgress(): array
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'in_progress']);

        return $s;
    }

    public function test_extra_work_is_proposed_by_the_provider_and_approved_by_the_customer(): void
    {
        $s = $this->inProgress();

        $this->actingAs($s['provider']->user);
        Livewire::test(ProviderJobShow::class, ['booking' => $s['booking']])
            ->set('extraDescription', 'Gas refill')->set('extraAmount', '100')
            ->call('proposeExtraWork')->assertSet('error', '');
        $this->assertSame('on_hold', $s['booking']->fresh()->status);

        $item = BookingExtraItem::where('booking_id', $s['booking']->id)->firstOrFail();
        $this->assertSame('pending_approval', $item->status);

        $this->actingAs($s['customer']);
        Livewire::test(CustomerOrderShow::class, ['booking' => $s['booking']])
            ->assertSee('Approval needed: extra work')->assertSee('Gas refill')
            ->call('respondToExtraWork', $item->id, true)->assertSet('error', '')
            ->assertDontSee('Approval needed: extra work');

        $this->assertSame('approved', $item->fresh()->status);
        $this->assertSame('in_progress', $s['booking']->fresh()->status);
    }

    public function test_declining_extra_work_resumes_the_job_at_the_original_price(): void
    {
        $s = $this->inProgress();
        $this->actingAs($s['provider']->user);
        Livewire::test(ProviderJobShow::class, ['booking' => $s['booking']])
            ->set('extraDescription', 'Pipe')->set('extraAmount', '50')->call('proposeExtraWork');
        $item = BookingExtraItem::where('booking_id', $s['booking']->id)->firstOrFail();

        $this->actingAs($s['customer']);
        Livewire::test(CustomerOrderShow::class, ['booking' => $s['booking']])->call('respondToExtraWork', $item->id, false);

        $this->assertSame('rejected', $item->fresh()->status);
        $this->assertSame('in_progress', $s['booking']->fresh()->status);
    }

    public function test_another_customer_cannot_answer_the_request(): void
    {
        $s = $this->inProgress();
        $this->actingAs($s['provider']->user);
        Livewire::test(ProviderJobShow::class, ['booking' => $s['booking']])
            ->set('extraDescription', 'Pipe')->set('extraAmount', '50')->call('proposeExtraWork');
        $item = BookingExtraItem::where('booking_id', $s['booking']->id)->firstOrFail();

        $this->actingAs($this->makeCustomer());
        Livewire::test(CustomerOrderShow::class, ['booking' => $s['booking']])->assertNotFound();
        $this->assertSame('pending_approval', $item->fresh()->status);
    }

    public function test_customer_reports_the_professional_left_and_the_operator_hands_over_the_job(): void
    {
        $s = $this->inProgress();
        $new = $this->makeProviderIn($s['franchise'], $s['zone']);

        $this->actingAs($s['customer']);
        Livewire::test(CustomerOrderShow::class, ['booking' => $s['booking']])
            ->assertSee('My professional left')->call('reportProfessionalLeft')->assertSet('error', '');
        $this->assertSame('provider_side', $s['booking']->fresh()->hold_category);

        $admin = $this->makeSuperAdmin();
        Livewire::actingAs($admin)->test(AdminBookingShow::class, ['bookingId' => $s['booking']->id])
            ->assertSee('Hand the job to another professional')
            ->set('selectedProviderId', (string) $new->id)->call('reassign')->assertSet('flashType', 'success');

        $b = $s['booking']->fresh();
        $this->assertSame('assigned', $b->status);
        $this->assertSame($new->id, $b->provider_id);

        // The new professional starts the job with the fresh code.
        $this->actingAs($new->user);
        Livewire::test(ProviderJobShow::class, ['booking' => $b])->set('otp', $b->start_otp)->call('start')->assertSet('error', '');
        $this->assertSame('in_progress', $b->fresh()->status);
    }

    public function test_provider_cannot_continue_then_operator_cancels_with_no_fee(): void
    {
        $s = $this->inProgress();
        $this->actingAs($s['provider']->user);
        Livewire::test(ProviderJobShow::class, ['booking' => $s['booking']])->call('cannotContinue')->assertSet('error', '');
        $this->assertSame('on_hold', $s['booking']->fresh()->status);

        $admin = $this->makeSuperAdmin();
        Livewire::actingAs($admin)->test(AdminBookingShow::class, ['bookingId' => $s['booking']->id])
            ->set('cancelReason', 'Professional left')->call('cancel')->assertSet('flashType', 'success');

        $b = $s['booking']->fresh();
        $this->assertSame('cancelled', $b->status);
        $this->assertEquals(0, $b->cancellation_fee);
    }

    public function test_operator_can_flag_a_provider_left_from_the_admin_screen(): void
    {
        $s = $this->inProgress();
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(AdminBookingShow::class, ['bookingId' => $s['booking']->id])
            ->call('flagProviderLeft')->assertSet('flashType', 'success');

        $this->assertSame('provider_side', $s['booking']->fresh()->hold_category);
    }

    public function test_a_job_handed_to_a_provider_rings_once_but_their_own_accept_does_not(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'searching_provider', 'provider_id' => null]);
        $new = $this->makeProviderIn($s['franchise'], $s['zone']);
        $this->actingAs($new->user);

        // First render seeds; nothing rings.
        $c = Livewire::test(\App\Livewire\Provider\OfferWatcher::class)->assertNotDispatched('provider-alert-status');

        // An operator assigns the job.
        $s['booking']->update(['status' => 'assigned', 'provider_id' => $new->id]);
        $s['booking']->statusHistory()->create(['status' => 'assigned', 'note' => 'Reassigned by admin', 'changed_at' => now()]);
        $c->call('$refresh')->assertDispatched('provider-alert-status');
        $c->call('$refresh')->assertNotDispatched('provider-alert-status');

        // A job the provider accepted themselves does not ring them.
        $other = $this->makeAssignedBookingScenario();
        $other['booking']->update(['status' => 'searching_provider', 'provider_id' => null]);
        $c->call('$refresh');
        $other['booking']->update(['status' => 'assigned', 'provider_id' => $new->id]);
        $other['booking']->statusHistory()->create(['status' => 'assigned', 'note' => 'Accepted by provider #'.$new->id, 'changed_at' => now()]);
        $c->call('$refresh')->assertNotDispatched('provider-alert-status');
    }

    public function test_the_hand_over_list_leaves_out_the_professional_who_left(): void
    {
        $s = $this->inProgress();
        $new = $this->makeProviderIn($s['franchise'], $s['zone']);
        $admin = $this->makeSuperAdmin();

        $ids = Livewire::actingAs($admin)->test(AdminBookingShow::class, ['bookingId' => $s['booking']->id])
            ->instance()->availableProviders->pluck('id')->all();

        $this->assertContains($new->id, $ids);
        $this->assertNotContains($s['provider']->id, $ids);
    }

    public function test_a_cash_booking_is_never_shown_as_awaiting_payment(): void
    {
        $s = $this->makeBookingScenario('pending');
        $s['booking']->update(['payment_method' => 'cash', 'payment_status' => 'pending']);
        $this->actingAs($s['customer']);

        Livewire::test(CustomerOrderShow::class, ['booking' => $s['booking']])
            ->assertDontSee('Awaiting payment')->assertSee('Confirmed');
    }
}
