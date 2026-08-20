<?php

namespace App\Services\Ai;

use App\Contracts\NarrativeAiAdapter;
use Illuminate\Support\Facades\Log;

/**
 * Default binding (see AppServiceProvider::register()) — no real AI
 * provider is configured anywhere in this codebase (no API key in
 * config/services.php, same "confirmed by audit" standard LogSmsAdapter's
 * own docblock holds itself to). Logs that a summary was requested and
 * returns null, the documented "fall back to the plain fact list" signal
 * every NarrativeAiAdapter caller must already handle — so the Daily
 * Insights panel and the natural-language filter both work correctly,
 * every environment, with zero dependency on AI availability, exactly as
 * the mission requires ("Do not let AI availability become a dependency
 * for any core operational function").
 */
class LogNarrativeAiAdapter implements NarrativeAiAdapter
{
    public function summarize(array $facts): ?string
    {
        Log::debug('[AI narrative] disabled — falling back to plain facts', ['fact_count' => count($facts)]);

        return null;
    }
}
