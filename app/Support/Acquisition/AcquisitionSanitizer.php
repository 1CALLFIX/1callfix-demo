<?php

namespace App\Support\Acquisition;

/**
 * The ONE place that decides what marketing-attribution data may be stored. Used by the web middleware and the
 * API (and so any Flutter client). Display and reporting only: nothing here may feed pricing, permissions or
 * eligibility.
 */
class AcquisitionSanitizer
{
    /** Keys a client may send. Anything else is dropped. */
    public const KEYS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'gclid', 'fbclid', 'msclkid',
        'landing_path', 'referrer_host', 'captured_at',
        // F3: the city slug of the first city-scoped page seen with the campaign. Not a tracking key on its own.
        'city',
    ];

    /** Keys that carry a real campaign signal (landing/referrer/time alone are not worth storing). */
    public const TRACKING_KEYS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid',
    ];

    public const MAX_LENGTH = 255;

    /**
     * @return array<string,string>|null whitelisted, cleaned values; null when nothing usable remains
     */
    public static function clean(mixed $input): ?array
    {
        if (! is_array($input)) {
            return null;
        }

        $out = [];
        foreach (self::KEYS as $key) {
            if (! isset($input[$key]) || ! is_scalar($input[$key])) {
                continue;
            }
            $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $input[$key]) ?? '');
            // Overlong values are dropped, not truncated: a cut-off click id is worse than none.
            if ($value === '' || mb_strlen($value) > self::MAX_LENGTH) {
                continue;
            }
            $out[$key] = $value;
        }

        return array_intersect_key($out, array_flip(self::TRACKING_KEYS)) === [] ? null : $out;
    }
}
