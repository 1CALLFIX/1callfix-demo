<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * REF 1CF-PROMPT-20260925-EARN4 (1.1) — no test reaches the network by
     * accident. Every outbound HTTP call must be faked (Http::fake()) or
     * the test fails with a StrayRequestException. The ONLY exception is a
     * test tagged #[Group('external')] (today: the two PrimeSilverPlanSeederTest
     * cases that create a real Razorpay order), which CI / local runs skip
     * with --exclude-group=external.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('external', $this->groups(), true)) {
            Http::preventStrayRequests();
        }
    }
}
