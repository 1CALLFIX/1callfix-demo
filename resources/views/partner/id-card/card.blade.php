{{--
    Owner 2026-11-26: the 1CallFix ID card, front and back, at the standard card shape (85.6 × 54 mm). ONE design for
    the phone screen, the printable PDF and the verify page. Inline styles and tables on purpose: the PDF maker
    (dompdf) has no flexbox and no Tailwind, and the screen must match the print exactly. Every size is in em, so
    the caller sets the scale with `$fontSize` (screen: px, print: mm). Brand wordmark: Arial Bold, "1" and "Fix"
    light blue #11C3F2, "Call" white, on the blue pill #133DA9.
    $c = IdCardService::data(); $side = front | back; $fontSize = CSS size of 1 em.
--}}
@php
    $colour = $c['app'] === 'rider' ? '#f97316' : '#2563eb';
    $kind = $c['app'] === 'rider' ? 'RIDER ID CARD' : 'PARTNER ID CARD';
    $font = 'font-family: Arial, Helvetica, sans-serif;';
    $box = "width: 31.5em; height: 19.86em; border-collapse: separate; border-spacing: 0; overflow: hidden; border: 0.08em solid #cbd5e1; border-radius: 0.9em; background: #ffffff; {$font} color: #0f172a; font-size: {$fontSize};";
@endphp
@if ($side === 'front')
<table style="{{ $box }}" data-id-card="front" cellpadding="0" cellspacing="0">
    <tr>
        <td colspan="2" style="background: {{ $colour }}; height: 4.2em; padding: 0 1.1em; border-radius: 0.85em 0.85em 0 0;">
            <table style="width: 100%; border-collapse: collapse;" cellpadding="0" cellspacing="0"><tr>
                <td style="vertical-align: middle;">
                    <span style="display: inline-block; background: #133DA9; border-radius: 1em; padding: 0.3em 0.75em; font-weight: bold; font-size: 1.35em; letter-spacing: 0.01em;"><span style="color: #11C3F2;">1</span><span style="color: #ffffff;">Call</span><span style="color: #11C3F2;">Fix</span></span>
                </td>
                <td style="vertical-align: middle; text-align: right; color: #ffffff; font-weight: bold; font-size: 0.85em; letter-spacing: 0.08em;">{{ $kind }}</td>
            </tr></table>
        </td>
    </tr>
    <tr>
        <td style="width: 9.6em; height: 12.26em; padding: 1em 0 0.6em 1.1em; vertical-align: top;">
            @if ($c['photo'])
                <img src="{{ $c['photo'] }}" alt="Photo of {{ $c['name'] }}" style="width: 8.4em; height: 10.5em; border-radius: 0.5em; border: 0.15em solid {{ $colour }}; display: block;">
            @else
                <div style="width: 8.4em; height: 10.5em; border-radius: 0.5em; border: 0.15em dashed #94a3b8; background: #f1f5f9; color: #64748b; font-size: 0.8em; text-align: center; line-height: 13em;">Photo</div>
            @endif
        </td>
        <td style="padding: 1em 1.1em 0.6em 0.9em; vertical-align: top;">
            <div style="font-size: 1.55em; font-weight: bold; line-height: 1.15;">{{ $c['name'] }}</div>
            <div style="margin-top: 0.3em; font-size: 1em; font-weight: bold; color: {{ $colour }};">{{ $c['work'] }}</div>
            <table style="margin-top: 0.7em; border-collapse: collapse; font-size: 0.92em;" cellpadding="0" cellspacing="0">
                <tr><td style="color: #64748b; padding: 0.15em 0.8em 0.15em 0;">ID</td><td style="font-weight: bold;">{{ $c['code'] }}</td></tr>
                <tr><td style="color: #64748b; padding: 0.15em 0.8em 0.15em 0;">City</td><td style="font-weight: bold;">{{ $c['city'] }}</td></tr>
                <tr><td style="color: #64748b; padding: 0.15em 0.8em 0.15em 0;">Valid till</td><td style="font-weight: bold;">{{ $c['valid_until'] }}</td></tr>
            </table>
        </td>
    </tr>
    <tr>
        <td colspan="2" style="height: 1.8em; background: #f1f5f9; border-radius: 0 0 0.85em 0.85em; text-align: center; font-size: 0.8em; color: #334155;">
            Verified 1CallFix partner · scan the back to check
        </td>
    </tr>
</table>
@else
<table style="{{ $box }}" data-id-card="back" cellpadding="0" cellspacing="0">
    <tr>
        <td style="width: 13em; height: 17.46em; padding: 1.2em 0 1.2em 1.2em; vertical-align: middle;">
            <img src="{{ $c['qr'] }}" alt="QR code to check this card" style="width: 11.6em; height: 11.6em; display: block;">
        </td>
        <td style="padding: 1.2em 1.2em 1.2em 0.9em; vertical-align: middle;">
            <div style="font-size: 1.25em; font-weight: bold; color: {{ $colour }}; line-height: 1.2;">Scan to check this partner is genuine</div>
            <div style="margin-top: 0.6em; font-size: 0.88em; color: #334155; line-height: 1.4;">The page shows the photo and says Active or Not active right now.</div>
            <div style="margin-top: 0.6em; font-size: 0.88em; color: #334155; line-height: 1.4;">This card belongs to 1CallFix. If found, please return it to us.</div>
            <div style="margin-top: 0.7em; font-size: 1em; font-weight: bold;">{{ $c['code'] }} · 1callfix.com</div>
        </td>
    </tr>
</table>
@endif
