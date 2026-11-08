<?php

namespace Tests\Feature\CustomerWeb;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 2026-11-08: /_lsprobe tells whether the server's own LiteSpeed page cache works; it must carry no cookies. */
class LiteSpeedProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_probe_is_cacheable_and_sets_no_cookies(): void
    {
        $r = $this->get('/_lsprobe')->assertOk()->assertHeader('X-LiteSpeed-Cache-Control', 'public, max-age=60');

        $this->assertSame([], $r->headers->getCookies());
        $this->assertStringStartsWith('lsprobe made at ', $r->getContent());
    }
}
