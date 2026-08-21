<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Production-hardening session, Part 2 — "confirm no code path leaks stack
 * traces or query details to the end user when debug is off... test this
 * by checking the actual exception handler config, not by assumption."
 *
 * KNOWN_RISKS_AND_DECISIONS.md item 25 already confirmed this live, once,
 * via direct SSH against the real production server — real, but a one-time
 * manual check, not a standing regression test. This locks the same
 * behavior in permanently: with APP_DEBUG explicitly false (the required
 * production value — see .env.example's own loud comment on this, added
 * this session after the exact APP_DEBUG=true incident item 25 describes),
 * an unhandled 404 on a JSON request must never include exception/file/
 * line/trace fields — bootstrap/app.php's shouldRenderJsonWhen() routes
 * every /api/* request through Laravel's JSON exception renderer, which is
 * where a debug-mode leak would actually surface.
 */
class DebugModeExposureTest extends TestCase
{
    public function test_a_json_404_never_leaks_exception_detail_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/this-route-does-not-exist-debug-check-xyz123');

        $response->assertStatus(404);
        $response->assertJsonMissingPath('exception');
        $response->assertJsonMissingPath('file');
        $response->assertJsonMissingPath('line');
        $response->assertJsonMissingPath('trace');
    }
}
