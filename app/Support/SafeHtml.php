<?php

namespace App\Support;

/**
 * Descriptions brought over from the old app are HTML ("<h3 style=...>INSTRUCTIONS</h3><ul><li ...>") and were shown
 * as raw code on the service page (owner's screenshot 2026-11-07). This keeps only plain formatting tags, with no
 * attributes at all (so no style, link or script), and leaves plain text alone.
 */
class SafeHtml
{
    private const TAGS = ['p', 'br', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'u', 'h2', 'h3', 'h4', 'h5', 'h6'];

    public static function isHtml(?string $text): bool
    {
        return $text !== null && preg_match('/<\s*\/?\s*(p|br|ul|ol|li|strong|b|em|i|u|h[1-6]|div|span)\b[^>]*>/i', $text) === 1;
    }

    public static function clean(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? '';
        $html = preg_replace('#<\s*(/?)\s*h1\b#i', '<$1h2', $html) ?? '';
        // Keep the allowed tags and drop every attribute (<li style="..."> becomes <li>). Repeated until nothing
        // changes, so a ">" hidden inside an attribute can never smuggle a tag through.
        do {
            $before = $html;
            $html = strip_tags($html, self::TAGS);
            $html = preg_replace('#<(/?)([a-z0-9]+)\b[^>]*?(/?)>#i', '<$1$2$3>', $html) ?? '';
        } while ($html !== $before);

        return trim($html);
    }
}
