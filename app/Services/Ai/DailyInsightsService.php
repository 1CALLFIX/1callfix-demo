<?php

namespace App\Services\Ai;

use App\Contracts\NarrativeAiAdapter;
use App\Models\User;
use App\Services\Operations\OperationalInsightsService;

/**
 * Admin Polish + AI session, Part 2 item 3 — "a short, generated summary of
 * what changed and what needs attention today... built from the same
 * anomaly detection in #1, optionally phrased naturally via LLM call, but
 * the underlying facts must come from real queries, never from the LLM
 * inferring them."
 *
 * The single entry point Dashboard.php calls for the "Daily Insights"
 * panel. Always returns the real fact list (OperationalInsightsService is
 * the only source of truth for what those facts are); 'summary' is either
 * the AI-phrased paragraph or null — the view falls back to a plain
 * bulleted rendering of 'facts' when it's null, so the panel is fully
 * correct and useful with AI disabled, exactly as the mission requires.
 */
class DailyInsightsService
{
    public function __construct(
        private OperationalInsightsService $insights,
        private NarrativeAiAdapter $ai,
    ) {
    }

    /**
     * @return array{facts: array<int, string>, summary: ?string}
     */
    public function digest(User $user): array
    {
        $facts = $this->insights->facts($user);

        return [
            'facts' => $facts,
            'summary' => empty($facts) ? null : $this->ai->summarize($facts),
        ];
    }
}
