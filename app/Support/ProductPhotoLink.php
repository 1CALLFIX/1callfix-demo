<?php

namespace App\Support;

/**
 * Owner 2026-11-03: product photos can be links to a picture hosted elsewhere (for example postimg.cc), so import
 * files stay small and pages load fast. A link must be the picture itself (a "direct link" ending in .jpg / .png /
 * .webp / .gif), never the host's web page - a page link shows as a broken photo in the shop.
 */
final class ProductPhotoLink
{
    public const MAX = 5;

    /** Links from one cell: several separated by |, ; or new lines. */
    public static function split(?string $cell): array
    {
        return collect(preg_split('/[|;\n]+/', (string) $cell))->map(fn ($v) => trim($v))->filter()->unique()->values()->all();
    }

    /** Why this link cannot be a product photo, or null when it can. */
    public static function problem(string $link): ?string
    {
        if (! preg_match('#^https://#i', $link) || ! filter_var($link, FILTER_VALIDATE_URL)) {
            return "'{$link}' is not a photo link (it must start with https://)";
        }
        $host = mb_strtolower((string) parse_url($link, PHP_URL_HOST));
        if (in_array($host, ['postimg.cc', 'www.postimg.cc', 'postimages.org', 'www.postimages.org'], true)) {
            return "'{$link}' is the postimg page, not the photo: use its \"Direct link\" (starts with https://i.postimg.cc/)";
        }
        if (! preg_match('/\.(jpe?g|png|webp|gif)$/i', (string) parse_url($link, PHP_URL_PATH))) {
            return "'{$link}' is not a direct photo link (it must end in .jpg, .png, .webp or .gif)";
        }

        return null;
    }
}
