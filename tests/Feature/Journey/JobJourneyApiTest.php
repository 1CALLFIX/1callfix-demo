<?php

namespace Tests\Feature\Journey;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-JOURNEY-001 — the native-app endpoints: journey read + en-route / start / hold-for-spares /
 * spares-available / resume, with ownership enforced.
 */
class JobJourneyApiTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\Setting::set('cancellation.interim_cap_percent', '50'); // fail-closed until configured (A2)
    }

    private function sparesDeclaration(): array
    {
        return ['work_amount' => 100, 'sourced_by' => 'provider', 'expected_at' => now()->addDays(3)->toDateString()];
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $id = $s['booking']->id;

        $this->getJson("/api/bookings/{$id}/journey")->assertUnauthorized();
        foreach (['en-route', 'start', 'hold-for-spares', 'spares-available', 'resume'] as $path) {
            $this->postJson("/api/bookings/{$id}/{$path}")->assertUnauthorized();
        }
    }

    public function test_the_full_spares_journey_over_http(): void
    {
        Notification::fake();
        Setting::set('notifications.channels', 'mail');
        $s = $this->makeAssignedBookingScenario();
        $id = $s['booking']->id;
        $api = $this->actingAs($s['provider']->user, 'sanctum');

        $api->postJson("/api/bookings/{$id}/en-route")->assertOk()
            ->assertJsonPath('booking.status', 'provider_en_route')
            ->assertJsonPath('journey.current_status', 'provider_en_route');

        $api->postJson("/api/bookings/{$id}/start", ['otp' => '1234'])->assertOk()
            ->assertJsonPath('booking.status', 'in_progress');

        $api->postJson("/api/bookings/{$id}/hold-for-spares", ['note' => 'Need a gas cylinder'] + $this->sparesDeclaration())->assertOk()
            ->assertJsonPath('booking.status', 'on_hold')
            ->assertJsonPath('journey.paused', true)
            ->assertJsonPath('journey.headline', 'Job on hold');

        $api->postJson("/api/bookings/{$id}/spares-available")->assertOk()
            ->assertJsonPath('journey.headline', 'Spares available');

        $resume = $api->postJson("/api/bookings/{$id}/resume")->assertOk()
            ->assertJsonPath('booking.status', 'in_progress')
            ->assertJsonPath('journey.paused', false);

        $episode = collect($resume->json('journey.steps'))->firstWhere('key', 'in_progress')['episodes'][0];
        $this->assertSame(['done', 'done', 'done'], array_column($episode['mini'], 'state'));
        $this->assertIsString($episode['started_at'], 'timestamps are ISO strings for the app');
    }

    public function test_someone_elses_job_cannot_be_driven(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $other = $this->makeBookingScenario('assigned');
        $id = $s['booking']->id;

        $this->actingAs($other['provider']->user, 'sanctum')->postJson("/api/bookings/{$id}/en-route")->assertForbidden();
        $this->actingAs($s['customer'], 'sanctum')->postJson("/api/bookings/{$id}/en-route")->assertForbidden();
        $this->assertSame('assigned', $s['booking']->fresh()->status);
    }

    public function test_the_assigned_field_worker_can_drive_the_job(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $s = $this->makeAssignedBookingScenario();
        $worker = $this->makeFieldWorkerIn($s['franchise'], $s['zone']);
        $s['booking']->update(['assigned_worker_id' => $worker->id]);

        $this->actingAs($worker->user, 'sanctum')
            ->postJson("/api/bookings/{$s['booking']->id}/en-route")
            ->assertOk()->assertJsonPath('booking.status', 'provider_en_route');
    }

    public function test_transitions_that_do_not_apply_are_refused_with_409(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $id = $s['booking']->id;
        $api = $this->actingAs($s['provider']->user, 'sanctum');

        // Cannot wait for spares before the job has started.
        $api->postJson("/api/bookings/{$id}/hold-for-spares", $this->sparesDeclaration())->assertStatus(409);
        // REF 1CF-CANCEL-POLICY-001 — the interim-work declaration is mandatory.
        $api->postJson("/api/bookings/{$id}/hold-for-spares")->assertStatus(422);
        // Nothing is on hold yet.
        $api->postJson("/api/bookings/{$id}/spares-available")->assertStatus(409);
        $api->postJson("/api/bookings/{$id}/resume")->assertStatus(409);
        // Wrong OTP.
        $api->postJson("/api/bookings/{$id}/start", ['otp' => '0000'])->assertStatus(409);
        $api->postJson("/api/bookings/{$id}/start", [])->assertStatus(422);
    }

    public function test_a_provider_cannot_resume_a_hold_that_is_not_for_spares(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'in_progress']);
        app(\App\Actions\PlaceBookingOnHoldAction::class)->execute($s['booking']->id, 'awaiting_customer_approval');

        $this->actingAs($s['provider']->user, 'sanctum')
            ->postJson("/api/bookings/{$s['booking']->id}/resume")
            ->assertStatus(409);
        $this->assertSame('on_hold', $s['booking']->fresh()->status);
    }

    public function test_the_journey_is_readable_by_the_customer_and_the_provider_but_not_a_stranger(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $id = $s['booking']->id;
        $stranger = $this->makeCustomer();

        $this->actingAs($s['customer'], 'sanctum')->getJson("/api/bookings/{$id}/journey")
            ->assertOk()
            ->assertJsonPath('booking.code', $s['booking']->code)
            ->assertJsonPath('journey.key', 'service')
            ->assertJsonStructure(['journey' => ['steps', 'terminal', 'progress', 'headline', 'hint', 'tone', 'chips']]);

        $this->actingAs($s['provider']->user, 'sanctum')->getJson("/api/bookings/{$id}/journey")->assertOk();
        $this->actingAs($stranger, 'sanctum')->getJson("/api/bookings/{$id}/journey")->assertNotFound();
        $this->actingAs($s['customer'], 'sanctum')->getJson('/api/bookings/999999/journey')->assertNotFound();
    }
}
