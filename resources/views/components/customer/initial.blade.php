@props(['name'])

{{--
    The single letter used as a fallback tile behind category and service
    artwork (Phase C).

    Leading punctuation is stripped before the letter is taken. Without that,
    a row whose name begins with a bracket or a quote — the `[QA]` prefix
    every demo row carries, an imported row titled "…", a name starting with
    a dash — produces a tile showing "[" instead of a letter, which reads as a
    rendering bug rather than a placeholder. A name with no letters or digits
    at all falls back to a neutral dot rather than rendering empty.

    Expressed as a single echo rather than an @php block followed by an echo:
    Blade extracts @php...@endphp as a raw block first, and an echo sitting on
    the same line as @endphp gets swallowed into that extraction and printed
    literally. (Found in browser testing, where every card rendered the text
    "{{ $initial }}" instead of a letter.)
--}}
{{ ($stripped = preg_replace('/^[^\p{L}\p{N}]+/u', '', trim((string) $name))) === '' ? '·' : mb_strtoupper(mb_substr($stripped, 0, 1)) }}
