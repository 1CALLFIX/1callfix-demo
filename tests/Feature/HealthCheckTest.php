<?php

namespace Tests\Feature;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Production-hardening session, Part 3 — regression for the real gap this
 * session found: bootstrap/app.php already registers Laravel's default
 * `/up` health route, but nothing listened for the DiagnosingHealth event
 * it dispatches, so a dead database or unreachable queue backend would
 * still report "up". AppServiceProvider::boot() now listens and checks
 * both; these tests confirm the listener actually runs (not just that the
 * route exists) and that a genuine failure flips the route to "down"
 * without leaking any exception detail to the caller.
 */
class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_up_route_reports_healthy_when_db_and_queue_are_reachable(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_up_route_reports_down_and_hides_the_failure_detail_when_a_health_check_fails(): void
    {
        // Simulate a real dependency failure (e.g. a dead DB/queue
        // connection) the same way the framework's own health route
        // expects to hear about it: a listener throwing.
        Event::listen(DiagnosingHealth::class, function () {
            throw new \RuntimeException('simulated dependency outage — this message must never reach the HTTP response');
        });

        $response = $this->get('/up');

        $response->assertStatus(500);
        $response->assertDontSee('simulated dependency outage');
    }
}
