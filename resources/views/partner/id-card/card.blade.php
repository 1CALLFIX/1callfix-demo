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
{{-- Back (owner 2026-11-26): QR, blood group, emergency contact numbers, and the "property of" line. --}}
<table style="{{ $box }}" data-id-card="back" cellpadding="0" cellspacing="0">
    <tr>
        <td style="width: 10.6em; height: 13.9em; padding: 1em 0 0.4em 1em; vertical-align: top;">
            <img src="{{ $c['qr'] }}" alt="QR code to check this card" style="width: 9.6em; height: 9.6em; display: block;">
            <div style="margin-top: 0.35em; width: 9.6em; text-align: center; font-size: 0.72em; font-weight: bold; color: {{ $colour }}; line-height: 1.25;">Scan to check this partner is genuine</div>
        </td>
        <td style="padding: 1em 1em 0.4em 0.7em; vertical-align: top;">
            <table style="border-collapse: collapse; font-size: 0.92em;" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="color: #64748b; padding: 0 0.6em 0.45em 0; vertical-align: top; white-space: nowrap;">Blood Group</td>
                    <td style="padding-bottom: 0.45em; font-weight: bold; color: #dc2626; font-size: 1.25em; line-height: 1;" data-id-card-blood>{{ $c['blood_group'] ?? '—' }}</td>
                </tr>
                <tr>
                    <td style="color: #64748b; padding: 0 0.6em 0 0; vertical-align: top; white-space: nowrap;">Emergency<br>Contact No's</td>
                    <td style="font-weight: bold; line-height: 1.35;" data-id-card-emergency>
                        @forelse ($c['emergency'] ?? [] as $e)
                            <div>{{ $e['phone'] }} <span style="font-weight: normal; color: #475569;">({{ $e['relation'] }})</span></div>
                        @empty
                            —
                        @endforelse
                    </td>
                </tr>
            </table>
            <div style="margin-top: 0.6em; font-size: 0.85em; font-weight: bold;">{{ $c['code'] }}</div>
            <div style="font-size: 0.85em; color: #334155;">1callfix.com</div>
        </td>
    </tr>
    <tr>
        <td colspan="2" style="height: 4.3em; padding: 0.35em 1em; background: {{ $colour }}; color: #ffffff; border-radius: 0 0 0.85em 0.85em; font-size: 0.74em; line-height: 1.35; text-align: center;" data-id-card-property>
            This is the property of <strong>{{ $c['company'] ?? '1CallFix' }}</strong>. If found, please return it to the company.
            @if (! empty($c['office_phone']))<br>Call {{ $c['office_phone'] }}@endif
        </td>
    </tr>
</table>
@endif
