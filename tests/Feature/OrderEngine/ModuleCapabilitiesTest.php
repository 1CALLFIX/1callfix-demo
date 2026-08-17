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

    /**
     * HOTEL / STAY BOOKING MODULE (this phase): the `'bookings'` placeholder
     * slug this test originally covered was renamed to `hotel` and actually
     * built -- see `Modules::HOTEL`'s own docblock and `ModuleCapabilities::
     * MAP['hotel']`. Its capabilities are no longer honestly-undetermined
     * placeholders; they're real, verified-implemented values, same
     * standard `test_service_module_reflects_what_is_actually_implemented()`
     * above already applies to the one other fully-built module. Genuinely
     * undetermined values (scheduling/wallet/settlement/coupons/loyalty/
     * referrals) stay `null`, not guessed.
     */
    public function test_hotel_module_reflects_what_is_actually_implemented(): void
    {
        $capabilities = ModuleCapabilities::for(Modules::HOTEL);

        // Real, verified-implemented capabilities.
        $this->assertTrue($capabilities['catalog']);
        $this->assertTrue($capabilities['order']);
        $this->assertTrue($capabilities['pricing']);
        $this->assertTrue($capabilities['payment']);
        $this->assertTrue($capabilities['availability']);
        $this->assertTrue($capabilities['cancellation']);
        $this->assertTrue($capabilities['provider']);
        $this->assertTrue($capabilities['commission']);
        $this->assertTrue($capabilities['notifications']);

        // Real, verified-absent/deferred capabilities.
        $this->assertFalse($capabilities['reviews']);
        $this->assertFalse($capabilities['dispatch']);
        $this->assertFalse($capabilities['delivery']);

        // Genuinely undetermined -- never guessed.
        $this->assertNull($capabilities['scheduling']);
        $this->assertNull($capabilities['wallet']);
        $this->assertNull($capabilities['settlement']);
    }
}
