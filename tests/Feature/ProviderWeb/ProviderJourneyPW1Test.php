<?php

namespace Tests\Feature\ProviderWeb;

use App\Jobs\ServiceMatchingJob;
use App\Livewire\Provider\Activity;
use App\Livewire\Provider\Auth\Login;
use App\Livewire\Provider\Dashboard;
use App\Livewire\Provider\Earnings;
use App\Livewire\Provider\Jobs\Index as JobsIndex;
use App\Livewire\Provider\Jobs\Show as JobShow;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * PHASE PW1 §14.7 — the whole P1 loop end to end: sign in → go online (with
 * a fix) → a real ServiceMatchingJob round offers the job → accept → open
 * it → start-OTP → completion-OTP → the commission shows on earnings and
 * the events on the activity feed.
 */
class ProviderJourneyPW1Test extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    public function test_full_provider_web_loop(): void
    {
        Queue::fake();

        $s = $this->makeBookingScenario('pending');
        $password = 'partner-pass-77';
        $s['provider']->user->update(['password' => Hash::make($password)]);
        $s['provider']->update([
            'is_online' => false, 'current_lat' => null, 'current_lng' => null, 'location_updated_at' => null,
            'skills' => [$s['service']->category_id],
        ]);

        // 1. Sign in.
        Livewire::test(Login::class)
            ->set('identifier', $s['provider']->user->phone)
            ->set('password', $password)
            ->call('login')
            ->assertRedirect(route('provider.dashboard'));
        $this->assertTrue(Auth::guard('web')->check());

        $this->actingAs($s['provider']->user->fresh());

        // 2. Go online with a location fix near the customer address (1.0, 1.0).
        Livewire::test(Dashboard::class)
            ->call('goOnline', 1.001, 1.001)
            ->assertSee("You're eligible for dispatch.", false);
        $this->assertTrue($s['provider']->fresh()->is_online);

        // 3. A real dispatch round offers this provider the job.
        (new ServiceMatchingJob($s['booking']->id, 1))->handle(app(DispatchService::class));
        $this->assertDatabaseHas('dispatch_attempts', [
            'booking_id' => $s['booking']->id, 'provider_id' => $s['provider']->id, 'status' => 'notified',
        ]);

        // 4. See the offer, accept it.
        Livewire::test(JobsIndex::class)
            ->assertSee($s['booking']->code)
            ->call('accept', $s['booking']->id)
            ->assertRedirect(route('provider.jobs.show', $s['booking']->id));

        $booking = $s['booking']->fresh();
        $this->assertSame('assigned', $booking->status);
        $this->assertSame($s['provider']->id, $booking->provider_id);

        // OTPs the customer would read out (E5 generated them on accept).
        $startOtp = $booking->start_otp;
        $completionOtp = $booking->completion_otp;
        $this->assertNotEmpty($startOtp);
        $this->assertNotEmpty($completionOtp);

        // 5. Start with the start-OTP.
        Livewire::test(JobShow::class, ['booking' => $booking])
            ->set('otp', $startOtp)
            ->call('start')
            ->assertSet('notice', 'Job started.');
        $this->assertSame('in_progress', $booking->fresh()->status);

        // 6. Complete with the completion-OTP.
        Livewire::test(JobShow::class, ['booking' => $booking->fresh()])
            ->set('otp', $completionOtp)
            ->call('complete')
            ->assertSet('notice', 'Job completed.');
        $this->assertSame('completed', $booking->fresh()->status);

        // 7. Earnings + activity reflect it.
        $this->assertDatabaseHas('commissions', ['booking_id' => $booking->id]);

        Livewire::test(Earnings::class)->assertSee($booking->code);

        Livewire::test(Activity::class)
            ->assertSee($booking->code)
            ->assertSee('Went online (provider web)');
    }
}
