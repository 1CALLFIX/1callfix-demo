<?php

namespace App\Services\Reviews;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Business reviews for the home page, fetched from the Google Places API and shown only as Google returns
 * them. Nothing is invented, edited or filtered for sentiment beyond the owner's own "minimum stars" setting, and the
 * section simply does not render until real data exists.
 *
 * Every knob is an admin setting (Admin → System → Google reviews). The only secret, the API key, lives in the
 * server .env (GOOGLE_PLACES_API_KEY), never in the database or the repository.
 *
 * The fetched copy is stored in the settings table, not the cache: the deploy runs `cache:clear`, which would
 * otherwise blank the section until the next refresh. It is shown only while it is younger than MAX_AGE_DAYS, which
 * keeps within Google's rule that Places content is not stored for long.
 */
class GoogleReviews
{
    public const ENABLED = 'reviews.google.enabled';
    public const PLACE_ID = 'reviews.google.place_id';
    public const MAX = 'reviews.google.max';
    public const MIN_RATING = 'reviews.google.min_rating';
    public const REFRESH_HOURS = 'reviews.google.refresh_hours';
    public const REVIEW_URL = 'reviews.google.write_review_url';
    public const PAYLOAD = 'reviews.google.payload';
    public const FETCHED_AT = 'reviews.google.fetched_at';
    public const LAST_ERROR = 'reviews.google.last_error';

    public const MAX_AGE_DAYS = 30;
    private const PLACE_ID_PATTERN = '/^[A-Za-z0-9_-]{10,200}$/';

    public static function enabled(): bool
    {
        return Setting::get(self::ENABLED) === '1';
    }

    public static function placeId(): string
    {
        $id = trim((string) Setting::get(self::PLACE_ID, ''));

        return preg_match(self::PLACE_ID_PATTERN, $id) === 1 ? $id : '';
    }

    public static function isValidPlaceId(string $id): bool
    {
        return preg_match(self::PLACE_ID_PATTERN, $id) === 1;
    }

    /** The owner's "write a review" link (e.g. a g.page short link); only a Google address is ever used. */
    public static function reviewUrl(): ?string
    {
        return self::googleUrl(trim((string) Setting::get(self::REVIEW_URL, '')));
    }

    public static function apiKey(): string
    {
        return trim((string) config('services.google_places.key'));
    }

    public static function max(): int
    {
        return self::clamp((int) Setting::get(self::MAX, 5), 1, 5);
    }

    public static function minRating(): int
    {
        return self::clamp((int) Setting::get(self::MIN_RATING, 4), 1, 5);
    }

    public static function refreshHours(): int
    {
        return self::clamp((int) Setting::get(self::REFRESH_HOURS, 24), 1, 168);
    }

    private static function clamp(int $v, int $lo, int $hi): int
    {
        return max($lo, min($hi, $v));
    }

    public static function fetchedAt(): ?\Illuminate\Support\Carbon
    {
        $raw = Setting::get(self::FETCHED_AT);

        return $raw ? \Illuminate\Support\Carbon::createFromTimestamp((int) $raw) : null;
    }

    /**
     * Pull fresh data from Google. Never throws: on any failure the previous copy is kept and the reason is stored
     * for the admin screen.
     *
     * @return string a short human-readable outcome
     */
    public function refresh(bool $force = false): string
    {
        if (! self::enabled()) {
            return 'Skipped: Google reviews are switched off.';
        }
        if (self::placeId() === '') {
            return 'Skipped: no valid Place ID is saved.';
        }
        if (self::apiKey() === '') {
            return 'Skipped: GOOGLE_PLACES_API_KEY is not set in the server .env.';
        }

        $last = self::fetchedAt();
        if (! $force && $last && $last->gt(now()->subHours(self::refreshHours()))) {
            return 'Skipped: the saved copy is still fresh.';
        }

        try {
            $response = Http::timeout(10)->acceptJson()->withHeaders([
                'X-Goog-Api-Key' => self::apiKey(),
                'X-Goog-FieldMask' => 'displayName,rating,userRatingCount,googleMapsUri,reviews',
            ])->get('https://places.googleapis.com/v1/places/'.rawurlencode(self::placeId()));

            if (! $response->successful()) {
                $reason = (string) ($response->json('error.message') ?: 'HTTP '.$response->status());

                return $this->fail($reason);
            }

            $payload = $this->normalise((array) $response->json());
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        Setting::set(self::PAYLOAD, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Setting::set(self::FETCHED_AT, (string) now()->timestamp);
        Setting::set(self::LAST_ERROR, '');

        return 'Refreshed: '.count($payload['reviews']).' review(s), rated '.($payload['rating'] ?? 'n/a').'.';
    }

    private function fail(string $reason): string
    {
        $reason = mb_substr(preg_replace('/\s+/', ' ', $reason) ?? '', 0, 300);
        Setting::set(self::LAST_ERROR, now()->toDateTimeString().' — '.$reason);

        return 'Failed: '.$reason;
    }

    /** Keep only the fields the section shows, and only values that are safe to put on a page. */
    private function normalise(array $data): array
    {
        $reviews = [];
        foreach ((array) ($data['reviews'] ?? []) as $r) {
            $text = trim((string) ($r['text']['text'] ?? $r['originalText']['text'] ?? ''));
            $reviews[] = [
                'author' => mb_substr(trim((string) ($r['authorAttribution']['displayName'] ?? '')), 0, 80) ?: 'Google user',
                'author_url' => self::googleUrl($r['authorAttribution']['uri'] ?? null),
                'photo' => self::httpsUrl($r['authorAttribution']['photoUri'] ?? null),
                'rating' => self::clamp((int) ($r['rating'] ?? 0), 0, 5),
                'text' => mb_substr($text, 0, 600),
                'when' => mb_substr(trim((string) ($r['relativePublishTimeDescription'] ?? '')), 0, 40),
            ];
        }

        return [
            'name' => mb_substr(trim((string) ($data['displayName']['text'] ?? '')), 0, 120),
            'rating' => isset($data['rating']) ? round((float) $data['rating'], 1) : null,
            'count' => (int) ($data['userRatingCount'] ?? 0),
            'url' => self::googleUrl($data['googleMapsUri'] ?? null),
            'reviews' => $reviews,
        ];
    }

    private static function httpsUrl(mixed $url): ?string
    {
        $url = is_string($url) ? trim($url) : '';

        return $url !== '' && filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'https://') ? $url : null;
    }

    /** Links back to Google only: https and a google.com / goo.gl / g.page host. */
    private static function googleUrl(mixed $url): ?string
    {
        $url = self::httpsUrl($url);
        if ($url === null) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return preg_match('/(^|\.)(google\.com|goo\.gl|g\.page)$/', $host) === 1 ? $url : null;
    }

    /**
     * What the home page shows, or null (render nothing) when: switched off, no data yet, the copy is older than
     * MAX_AGE_DAYS, or no review passes the owner's minimum-stars setting.
     *
     * @return array{rating: ?float, count: int, url: ?string, write_url: ?string, reviews: array<int, array<string, mixed>>}|null
     */
    public function display(): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        $fetched = self::fetchedAt();
        if (! $fetched || $fetched->lt(now()->subDays(self::MAX_AGE_DAYS))) {
            return null;
        }

        $payload = json_decode((string) Setting::get(self::PAYLOAD, ''), true);
        if (! is_array($payload)) {
            return null;
        }

        $min = self::minRating();
        $reviews = collect($payload['reviews'] ?? [])
            ->filter(fn ($r) => ($r['rating'] ?? 0) >= $min && trim((string) ($r['text'] ?? '')) !== '')
            ->take(self::max())
            ->values()
            ->all();

        if ($reviews === []) {
            return null;
        }

        return [
            'rating' => $payload['rating'] ?? null,
            'count' => (int) ($payload['count'] ?? 0),
            'url' => $payload['url'] ?? null,
            'write_url' => self::reviewUrl(),
            'reviews' => $reviews,
        ];
    }
}
