<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Owner 2026-11-07: the website's languages are the Super Admin's (Admin → Website & Pages → Languages), not code.
 * English is built in and always on. Telugu is built in (its default words ship with the site) and off until switched
 * on. Any other language (Tamil, Urdu, Arabic, ...) is added on that screen: code, its own name, right-to-left or not.
 * Its words are typed (or uploaded as a spreadsheet) in Website text; a word not typed yet shows in English.
 *
 * Stored as one setting, `i18n.languages`: JSON list of {code, name, english, rtl, on}. Before that is first saved,
 * Telugu's on/off is the older `i18n.telugu_enabled` switch, so nothing changes on the live site by deploying this.
 */
final class Languages
{
    public const SETTING = 'i18n.languages';

    /** Built-in languages: code => [own name, English name, right-to-left]. */
    public const BUILT_IN = [
        'en' => ['English', 'English', false],
        'te' => ['తెలుగు', 'Telugu', false],
    ];

    /** Codes that are never a language (they are words in our web addresses or would clash). */
    private const NOT_ALLOWED = ['api', 'www', 'xx'];

    /**
     * Every language the Super Admin has (on or off), English first.
     *
     * @return array<string, array{code: string, name: string, english: string, rtl: bool, on: bool, built_in: bool}>
     */
    public static function all(): array
    {
        $saved = self::saved();
        $list = [];
        foreach (self::BUILT_IN as $code => [$name, $english, $rtl]) {
            $row = $saved[$code] ?? null;
            $list[$code] = [
                'code' => $code,
                'name' => $row !== null && trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : $name,
                'english' => $english,
                'rtl' => $rtl,
                'on' => $code === 'en' ? true : ($row !== null ? (bool) ($row['on'] ?? false) : self::legacyTeluguOn()),
                'built_in' => true,
            ];
        }
        foreach ($saved as $code => $row) {
            if (isset($list[$code]) || ! self::validCode($code)) {
                continue;
            }
            $list[$code] = [
                'code' => $code,
                'name' => trim((string) ($row['name'] ?? '')) ?: $code,
                'english' => trim((string) ($row['english'] ?? '')) ?: $code,
                'rtl' => (bool) ($row['rtl'] ?? false),
                'on' => (bool) ($row['on'] ?? false),
                'built_in' => false,
            ];
        }

        return $list;
    }

    /** The languages customers can choose now (English always), in the Super Admin's order. */
    public static function active(): array
    {
        return array_filter(self::all(), fn ($l) => $l['on']);
    }

    /** @return list<string> */
    public static function activeCodes(): array
    {
        return array_keys(self::active());
    }

    /** @return list<string> every code, on or off (Website text keeps words for each) */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    public static function isActive(string $code): bool
    {
        return isset(self::active()[$code]);
    }

    /** More than English is on, so the top bar shows the language switch. */
    public static function choiceOffered(): bool
    {
        return count(self::active()) > 1;
    }

    public static function name(string $code): string
    {
        return self::all()[$code]['name'] ?? $code;
    }

    public static function englishName(string $code): string
    {
        return self::all()[$code]['english'] ?? $code;
    }

    public static function isRtl(string $code): bool
    {
        return (bool) (self::all()[$code]['rtl'] ?? false);
    }

    /** A language code: 2 or 3 small letters (the international short code: ta, ur, ar, hi, kn ...). */
    public static function validCode(string $code): bool
    {
        return (bool) preg_match('/^[a-z]{2,3}$/', $code) && ! in_array($code, self::NOT_ALLOWED, true);
    }

    /**
     * The JSON to store for this list (built-in rows keep only on/off and name).
     *
     * @param  array<string, array{code: string, name: string, english: string, rtl: bool, on: bool}>  $list
     */
    public static function encode(array $list): string
    {
        $rows = [];
        foreach ($list as $code => $l) {
            if ($code === 'en') {
                continue;
            }
            $rows[] = ['code' => $code, 'name' => trim($l['name']), 'english' => trim($l['english']), 'rtl' => (bool) $l['rtl'], 'on' => (bool) $l['on']];
        }

        return json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    public static function forget(): void
    {
        request()->attributes->remove('languages:saved');
        request()->attributes->remove('languages:telugu');
    }

    /** @return array<string, array<string, mixed>> */
    private static function saved(): array
    {
        $bag = request()->attributes;
        if (! $bag->has('languages:saved')) {
            $decoded = json_decode((string) Setting::get(self::SETTING, ''), true);
            $rows = [];
            foreach (is_array($decoded) ? $decoded : [] as $row) {
                if (is_array($row) && isset($row['code']) && is_string($row['code'])) {
                    $rows[strtolower($row['code'])] = $row;
                }
            }
            $bag->set('languages:saved', $rows);
        }

        return $bag->get('languages:saved');
    }

    private static function legacyTeluguOn(): bool
    {
        $bag = request()->attributes;
        if (! $bag->has('languages:telugu')) {
            $bag->set('languages:telugu', in_array(strtolower((string) Setting::get(SiteText::TELUGU_ENABLED, '')), ['1', 'true', 'on', 'yes'], true));
        }

        return $bag->get('languages:telugu');
    }
}
