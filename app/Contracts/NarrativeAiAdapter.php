<?php

namespace App\Contracts;

/**
 * Admin Polish + AI session, Part 2 item 4 — "keep it isolated behind a
 * clearly named service class so it can be swapped, disabled, or mocked in
 * tests without touching business logic." Same swap-the-binding pattern as
 * SmsAdapter/PushAdapter (see AppServiceProvider::register()): nothing
 * above this interface (DailyInsightsService, BookingNaturalLanguageFilter)
 * needs to change when/if a real provider is wired up.
 *
 * summarize() takes an already-computed, already-true list of fact strings
 * (see OperationalInsightsService::facts()) and returns EITHER a short
 * natural-language paragraph phrasing them, OR null. Returning null is not
 * an error — it is the explicit "AI unavailable or disabled, caller must
 * fall back to displaying the facts directly" signal every caller of this
 * interface is required to handle (mission's own "if the AI call fails or
 * is disabled, the underlying data/detection must still work and display
 * correctly, just without the natural-language layer").
 */
interface NarrativeAiAdapter
{
    /**
     * @param  array<int, string>  $facts  Plain fact sentences, already true, never invented by this method.
     * @return string|null A short natural-language summary, or null if unavailable (caller falls back to $facts as-is).
     */
    public function summarize(array $facts): ?string;
}
