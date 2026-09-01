<?php

namespace Tests\Feature\ProviderWeb;

use App\Livewire\Provider\Jobs\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * PHASE PW1 §6 — start-OTP → StartBookingAction, completion-OTP →
 * CompleteBookingAction, both verbatim; the ownership guard 404s another
 * partner. Nothing here re-implements a transition, OTP check, commission
 * or wallet credit.
 */
class ProviderJobLifecycleTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    public function test_wrong_start_otp_is_rejected_counts_an_attempt_and_does_not_advance_the_booking(): void
    {
        $s = $this->makeAssignedBookingScenario(); // start_otp 1234, completion_otp 5678
        $this->actingAs($s['provider']->user);

        Livewire::test(Show::class, ['booking' => $s['booking']])
            ->set('otp', '0000')
            ->call('start')
            ->assertSee('Incorrect start OTP');

        $s['booking']->refresh();
        $this->assertSame('assigned', $s['booking']->status);
        $this->assertSame(1, (int) $s['booking']->start_otp_attempts);
    }

    public function test_correct_start_otp_moves_the_job_to_in_progress(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $this->actingAs($s['provider']->user);

        Livewire::test(Show::class, ['booking' => $s['booking']])
            ->set('otp', '1234')
            ->call('start')
            ->assertSet('notice', 'Job started.');

        $this->assertSame('in_progress', $s['booking']->fresh()->status);
    }

    public function test_correct_completion_otp_completes_the_job_and_credits_the_provider(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'in_progress']);
        $this->actingAs($s['provider']->user);

        Livewire::test(Show::class, ['booking' => $s['booking']])
            ->set('otp', '5678')
            ->call('complete')
            ->assertSet('notice', 'Job completed.');

        $s['booking']->refresh();
        $this->assertSame('completed', $s['booking']->status);

        $this->assertDatabaseHas('commissions', ['booking_id' => $s['booking']->id]);
        $this->assertGreaterThan(0, (float) $s['booking']->commission->provider_commission);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $s['provider']->user->wallet->id, 'is_credit' => 1,
        ]);
    }

    public function test_completion_screen_shows_the_earnings_line(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'in_progress']);
        $this->actingAs($s['provider']->user);

        Livewire::test(Show::class, ['booking' => $s['booking']])
            ->set('otp', '5678')
            ->call('complete')
            ->assertSee('added to your wallet');
    }

    public function test_a_provider_cannot_open_a_job_assigned_to_someone_else(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $other = $this->makeProviderIn($s['franchise'], $s['zone']);

        $this->actingAs($other->user)
            ->get(route('provider.jobs.show', $s['booking']))
            ->assertNotFound();
    }
}
