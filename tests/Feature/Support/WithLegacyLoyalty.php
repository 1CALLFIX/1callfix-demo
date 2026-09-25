<?php

namespace Tests\Feature\Support;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — the loyalty program is null = OFF since
 * Stage 2. Tests written against the old in-code defaults (0.01 pts/₹,
 * 10 pts = ₹1, min 100, 365-day expiry, redemption on) opt back in
 * explicitly via Laravel's setUp{TraitName} hook.
 */
trait WithLegacyLoyalty
{
    protected function setUpWithLegacyLoyalty(): void
    {
        EarningsSettingsFixtureValues::loyalty();
    }
}
