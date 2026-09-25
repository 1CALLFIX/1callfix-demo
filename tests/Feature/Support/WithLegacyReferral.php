<?php

namespace Tests\Feature\Support;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — referral rewards are null = OFF since
 * Stage 2, and need a per-customer cap. Tests written against the old
 * in-code defaults (wallet reward 50, points reward 100) opt back in
 * explicitly, with a cap high enough never to bind.
 */
trait WithLegacyReferral
{
    protected function setUpWithLegacyReferral(): void
    {
        EarningsSettingsFixtureValues::referral();
        EarningsSettingsFixtureValues::loyalty();
    }
}
