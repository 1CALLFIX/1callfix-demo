<?php

namespace Tests\Feature\Support;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — wallet top-up is null = OFF since Stage 2
 * (own switch + every limit required). Tests written against the old
 * in-code limits (100 / 10,000 / 50,000 / 20,000 / 100,000) opt back in
 * explicitly via Laravel's setUp{TraitName} hook.
 */
trait WithLegacyWalletTopUp
{
    protected function setUpWithLegacyWalletTopUp(): void
    {
        EarningsSettingsFixtureValues::walletTopUp();
    }
}
