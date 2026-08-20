<?php

namespace App\Services\Ai;

use App\Contracts\NarrativeAiAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real implementation, NOT bound by default (see AppServiceProvider::register()
 * — same 'driver' pattern as SmsAdapter/PushAdapter: opt-in only once a real
 * ANTHROPIC_API_KEY exists in the deploying environment's own .env). Uses
 * Laravel's Http facade — same "fully fakeable in tests, no raw curl"
 * convention every sibling adapter in app/Notifications/Adapters already
 * follows (see ArkeselSmsAdapter's own docblock) — rather than a dedicated
 * Anthropic SDK, since none is composer-installed in this codebase and
 * this is the one place a Claude API call is made at all.
 *
 * Deliberately narrow: summarize() only ever turns an ALREADY-COMPUTED
 * fact list into prose (see NarrativeAiAdapter's own docblock) — the
 * prompt below explicitly forbids adding any fact not given to it, so the
 * model cannot become the source of truth for what's actually happening
 * operationally, only for how it's phrased.
 *
 * Fails closed: any HTTP error, timeout, malformed response, or refusal
 * stop_reason returns null (not an exception) — every caller of
 * NarrativeAiAdapter already treats null as "fall back to the plain fact
 * list", so an outage here never breaks the screen it's decorating.
 */
class AnthropicNarrativeAiAdapter implements NarrativeAiAdapter
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const DEFAULT_MODEL = 'claude-opus-5';

    public function summarize(array $facts): ?string
    {
        if (empty($facts)) {
            return null;
        }

        $apiKey = config('services.ai.anthropic.api_key');
        if (! $apiKey) {
            Log::warning('[AI narrative] anthropic driver selected but no API key configured — falling back');
            return null;
        }

        $prompt = "You write a single short admin dashboard summary paragraph (2-4 sentences, plain text, no markdown) ".
            "from the operational facts below. Only restate what is given — never invent a number, name, or fact ".
            "not present in this list. If the list is empty, say operations look normal.\n\nFacts:\n".
            collect($facts)->map(fn ($f) => "- {$f}")->implode("\n");

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => self::ANTHROPIC_VERSION,
                'content-type' => 'application/json',
            ])
                ->timeout((int) config('services.ai.anthropic.timeout', 10))
                ->post(self::ENDPOINT, [
                    'model' => config('services.ai.anthropic.model', self::DEFAULT_MODEL),
                    'max_tokens' => 400,
                    'output_config' => ['effort' => 'low'], // trivial rephrasing task, not a reasoning task — see mission's own "low for simple tasks" guidance
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if ($response->failed()) {
                Log::warning('[AI narrative] Anthropic API request failed', ['status' => $response->status()]);
                return null;
            }

            $body = $response->json();

            if (($body['stop_reason'] ?? null) === 'refusal') {
                Log::warning('[AI narrative] Anthropic refused the summarization request');
                return null;
            }

            $text = collect($body['content'] ?? [])
                ->firstWhere('type', 'text')['text'] ?? null;

            return $text ? trim($text) : null;
        } catch (\Throwable $e) {
            Log::warning('[AI narrative] Anthropic API call threw', ['message' => $e->getMessage()]);
            return null;
        }
    }
}
