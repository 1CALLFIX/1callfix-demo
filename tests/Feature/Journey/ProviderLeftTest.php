<?php

namespace Tests\Feature\Journey;

use App\Actions\AdminCancelBookingAction;
use App\Actions\FlagProviderLeftAction;
use App\Actions\ReassignInProgressJobAction;
use App\Actions\StartBookingAction;
use App\Notifications\BookingOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/** REF 1CF-JOURNEY-001 — the professional leaves mid-work: flag, hand over or cancel with no fee. */
class ProviderLeftTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function inProgress(): array
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'in_progress']);
        $s['booking'] = $s['booking']->fresh();

        return $s;
    }

    public function test_flag_puts_the_job_on_a_provider_side_hold(): void
    {
        Notification::fake();
        $s = $this->inProgress();

        $b = app(FlagProviderLeftAction::class)->execute($s['booking']->id, 'customer', $s['customer']->id);

        $this->assertSame('on_hold', $b->status);
        $this->assertSame('provider_side', $b->hold_category);
        $this->assertStringContainsString('left the site', $b->hold_note);
    }

    public function test_flag_needs_an_in_progress_job_and_a_known_source(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $this->expectException(\RuntimeException::class);
        app(FlagProviderLeftAction::class)->execute($s['booking']->id, 'customer');
    }

    public function test_unknown_source_is_refused(): void
    {
        $s = $this->inProgress();
        $this->expectException(\InvalidArgumentException::class);
        app(FlagProviderLeftAction::class)->execute($s['booking']->id, 'stranger');
    }

    public function test_reassign_hands_the_job_to_a_new_professional_with_a_fresh_start_code(): void
    {
        Notification::fake();
        $s = $this->inProgress();
        $new = $this->makeProviderIn($s['franchise'], $s['zone']);
        app(FlagProviderLeftAction::class)->execute($s['booking']->id, 'operator');

        $b = app(ReassignInProgressJobAction::class)->execute($s['booking']->id, $new->id);

        $this->assertSame('assigned', $b->status);
        $this->assertSame($new->id, $b->provider_id);
        $this->assertNull($b->hold_category);
        $this->assertNotNull($b->start_otp);
        $this->assertNull($b->start_otp_verified_at);
        Notification::assertSentTo($s['customer'], BookingOtpNotification::class);

        // The new professional can go through start again.
        $started = app(StartBookingAction::class)->execute($b->id, $b->start_otp, $new->user_id);
        $this->assertSame('in_progress', $started->status);
    }

    public function test_reassign_refuses_a_job_that_is_not_flagged_or_the_same_provider(): void
    {
        $s = $this->inProgress();
        $new = $this->makeProviderIn($s['franchise'], $s['zone']);
        try {
            app(ReassignInProgressJobAction::class)->execute($s['booking']->id, $new->id);
            $this->fail('expected refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('professional left', $e->getMessage());
        }

        app(FlagProviderLeftAction::class)->execute($s['booking']->id, 'operator');
        $this->expectException(\RuntimeException::class);
        app(ReassignInProgressJobAction::class)->execute($s['booking']->id, $s['provider']->id);
    }

    public function test_cancelling_after_the_provider_left_charges_no_fee(): void
    {
        Notification::fake();
        $s = $this->inProgress();
        $s['booking']->update(['price_quoted' => 1000]);
        app(FlagProviderLeftAction::class)->execute($s['booking']->id, 'customer');

        $b = app(AdminCancelBookingAction::class)->execute($s['booking']->id, 'Professional left');

        $this->assertSame('cancelled', $b->status);
        $this->assertEquals(0, $b->cancellation_fee);
    }
}
