<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Owner 2026-11-03: reads a shop's photo — a printed or handwritten price list, a bill, a shelf, product labels or a
 * menu board — into a list of products (name, price, unit, quantity, best-fitting category) for the seller to check
 * before anything is added. Claude reads the photo; nothing is saved from here.
 *
 * Calls the Claude Messages API over Laravel's Http client, the way AnthropicNarrativeAiAdapter already does in this
 * codebase (fakeable in tests, no SDK installed). Structured outputs (output_config.format json_schema) make the reply
 * schema-valid JSON. Off unless ANTHROPIC_API_KEY is set on the server.
 */
class ProductPhotoReader
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2026-10-21';

    public static function configured(): bool
    {
        return (string) config('services.ai.anthropic.api_key') !== '';
    }

    /**
     * @param  array<int, array{data: string, media_type: string}>  $photos  base64 photos (already downsized)
     * @param  array<int, string>  $categories  id => "Parent > Name" the seller's shop can use
     * @param  array<int, string>  $units  allowed units ("piece", "kg", ...)
     * @return array{items: array<int, array>, notes: string, input_tokens: ?int, output_tokens: ?int}
     */
    public function read(array $photos, array $categories, array $units): array
    {
        $key = (string) config('services.ai.anthropic.api_key');
        if ($key === '') {
            throw new RuntimeException('Reading photos is not switched on yet.');
        }

        $content = [];
        foreach ($photos as $photo) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $photo['media_type'], 'data' => $photo['data']]];
        }
        $categoryList = collect($categories)->map(fn ($name, $id) => "{$id}: {$name}")->implode("\n");
        $content[] = ['type' => 'text', 'text' => "Categories this shop can use (id: name):\n".($categoryList ?: '(none)')
            ."\n\nUnits allowed: ".implode(', ', $units)
            ."\n\nList every product you can read in the photo(s)."];

        $response = Http::withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => self::API_VERSION,
            'anthropic-beta' => 'server-side-fallback-2026-07-01',
            'content-type' => 'application/json',
        ])->timeout((int) config('services.ai.anthropic.photo_timeout', 120))->post(self::ENDPOINT, [
            'model' => config('services.ai.anthropic.photo_model', 'claude-opus-5-5'),
            'max_tokens' => 16000,
            'fallbacks' => 'default',
            'system' => $this->system(),
            'output_config' => [
                'effort' => 'medium',
                'format' => ['type' => 'json_schema', 'schema' => $this->schema()],
            ],
            'messages' => [['role' => 'user', 'content' => $content]],
        ]);

        if ($response->failed()) {
            Log::warning('[photo list] Claude API request failed', ['status' => $response->status(), 'type' => $response->json('error.type')]);
            throw new RuntimeException($response->status() === 429 ? 'Too many photos are being read right now. Try again in a minute.' : 'The photo could not be read. Try again.');
        }
        $body = $response->json();
        $stop = $body['stop_reason'] ?? null;
        if ($stop === 'refusal') {
            throw new RuntimeException('This photo cannot be read. Try a clear photo of a price list or products.');
        }
        if ($stop === 'max_tokens') {
            throw new RuntimeException('The photo has too many items. Take photos of smaller parts of the list.');
        }
        $text = collect($body['content'] ?? [])->firstWhere('type', 'text')['text'] ?? null;
        $data = is_string($text) ? json_decode($text, true) : null;
        if (! is_array($data) || ! is_array($data['items'] ?? null)) {
            Log::warning('[photo list] Unexpected reply shape', ['stop_reason' => $stop]);
            throw new RuntimeException('The photo could not be read. Try again.');
        }

        $allowedUnits = collect($units)->keyBy(fn ($u) => mb_strtolower($u));
        $items = collect($data['items'])->map(function ($i) use ($categories, $allowedUnits) {
            $name = trim(mb_substr((string) ($i['name'] ?? ''), 0, 150));
            if ($name === '') {
                return null;
            }
            $price = is_numeric($i['price'] ?? null) && (float) $i['price'] > 0 ? round((float) $i['price'], 2) : null;
            $unit = $allowedUnits->get(mb_strtolower((string) ($i['unit'] ?? '')));
            $unitValue = $unit && is_numeric($i['unit_value'] ?? null) && (float) $i['unit_value'] > 0 ? round((float) $i['unit_value'], 3) : null;
            $category = is_numeric($i['category_id'] ?? null) && isset($categories[(int) $i['category_id']]) ? (int) $i['category_id'] : null;
            $qty = is_numeric($i['quantity'] ?? null) && (int) $i['quantity'] >= 0 ? (int) $i['quantity'] : null;

            return ['name' => $name, 'price' => $price, 'unit_value' => $unitValue, 'unit' => $unitValue ? $unit : null, 'quantity' => $qty, 'category_id' => $category];
        })->filter()->unique(fn ($i) => mb_strtolower($i['name']).'|'.$i['unit_value'].'|'.$i['unit'])->take(200)->values()->all();

        return [
            'items' => $items,
            'notes' => mb_substr((string) ($data['notes'] ?? ''), 0, 300),
            'input_tokens' => $body['usage']['input_tokens'] ?? null,
            'output_tokens' => $body['usage']['output_tokens'] ?? null,
        ];
    }

    private function system(): string
    {
        return 'You help small shops in India put their products online. The seller sends photos: a printed or handwritten '
            .'price list, a bill or invoice, shelves, product packets or labels, or a menu board, in English, Telugu, Hindi '
            .'or a mix. Read every product you can see and return each one once. For each: the product name as a customer '
            .'would search it, in English, with the brand when visible (for example "Aashirvaad Atta"); the selling price '
            .'in rupees only when the photo shows it for that item, otherwise null; what one item is (unit_value and unit, '
            .'for example 500 and "g", or 1 and "piece") only when shown, using only the allowed units; a quantity only when '
            .'the photo shows how many are in stock, otherwise null; and the id of the best-fitting category from the list, '
            .'or null. Never invent a price, brand or size that is not in the photo. Skip totals, taxes, phone numbers and '
            .'shop names. If you cannot read the photo, return no items and say why in notes, in one short sentence.';
    }

    private function schema(): array
    {
        $num = ['anyOf' => [['type' => 'number'], ['type' => 'null']]];

        return [
            'type' => 'object',
            'properties' => [
                'items' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'price' => $num,
                        'unit_value' => $num,
                        'unit' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                        'quantity' => ['anyOf' => [['type' => 'integer'], ['type' => 'null']]],
                        'category_id' => ['anyOf' => [['type' => 'integer'], ['type' => 'null']]],
                    ],
                    'required' => ['name', 'price', 'unit_value', 'unit', 'quantity', 'category_id'],
                    'additionalProperties' => false,
                ]],
                'notes' => ['type' => 'string'],
            ],
            'required' => ['items', 'notes'],
            'additionalProperties' => false,
        ];
    }
}
