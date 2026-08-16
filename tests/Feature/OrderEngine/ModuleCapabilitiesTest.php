<?php

namespace Tests\Feature\OrderEngine;

use App\Support\ModuleCapabilities;
use App\Support\Modules;
use Tests\TestCase;

/**
 * Phase 22.3 (Module-Capability Contract). This is a documentation/data
 * map, not a behavioral system -- these tests confirm its own internal
 * consistency (every module resolvable, every key well-formed) rather than
 * any runtime effect, since it has none yet by design (see the class's own
 * docblock for why building enforcement now would be premature).
 */
class ModuleCapabilitiesTest extends TestCase
{
    public function test_every_registered_module_resolves_every_capability_key(): void
    {
        foreach (Modules::slugs() as $code) {
            $capabilities = ModuleCapabilities::for($code);

            foreach (ModuleCapabilities::keys() as $key) {
                $this->assertArrayHasKey($key, $capabilities, "Module '{$code}' is missing capability key '{$key}'.");
                $this->assertTrue(
                    is_bool($capabilities[$key]) || is_null($capabilities[$key]),
                    "Module '{$code}' capability '{$key}' must be bool or null, got ".gettype($capabilities[$key])
                );
            }
        }
    }

    public function test_service_module_reflects_what_is_actually_implemented(): void
    {
        $capabilities = ModuleCapabilities::for(Modules::SERVICE);

        // Real, verified-implemented capabilities for the one fully-built module.
        $this->assertTrue($capabilities['catalog']);
        $this->assertTrue($capabilities['order']);
        $this->assertTrue($capabilities['dispatch']);
        $this->assertTrue($capabilities['wallet']);
        $this->assertTrue($capabilities['commission']);

        // Real, verified-absent capabilities.
        $this->assertFalse($capabilities['delivery']);
        $this->assertFalse($capabilities['availability']);
    }

    public function test_unknown_module_code_defaults_every_capability_to_false(): void
    {
        $capabilities = ModuleCapabilities::for('not_a_real_module');

        $this->assertSame(array_fill_keys(ModuleCapabilities::keys(), false), $capabilities);
    }

    public function test_hotel_booking_is_honestly_undetermined_not_guessed(): void
    {
        $capabilities = ModuleCapabilities::for('bookings');

        // Per the audit's own §5 finding: the least-evidenced module. Its
        // uncertain capabilities must be null (undetermined), never a
        // guessed true/false.
        $this->assertNull($capabilities['catalog']);
        $this->assertNull($capabilities['pricing']);
        $this->assertNull($capabilities['availability']);
    }
}
