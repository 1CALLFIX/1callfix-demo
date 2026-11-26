{{--
    Owner 2026-11-26: the "Verified 1CallFix Partner" certificate (App\Services\Partners\CertificateService), A4 landscape
    shape. ONE design for the phone screen and the printable PDF. Inline styles and tables on purpose: the PDF maker
    (dompdf) has no flexbox and no Tailwind, and the screen must match the print. Every size is in em, so the caller
    sets the scale with `$fontSize` (screen: px, print: mm). 69 × 46 em. No emoji (the PDF fonts have none); the tick is
    DejaVu Sans. Brand wordmark: Arial Bold, "1" and "Fix" #11C3F2, "Call" white, on the blue pill #133DA9.
    $c = CertificateService::data(); $fontSize = CSS size of 1 em.
--}}
@php
    $col = $c['colour'];
    $gold = '#b8892b';
    $font = 'font-family: Arial, Helvetica, sans-serif;';
    $tick = '<span style="font-family: \'DejaVu Sans\', sans-serif;">&#10003;</span>';
@endphp
<table data-certificate="{{ $c['kind'] }}" style="width: 69em; height: 46em; border-collapse: separate; border-spacing: 0; background: {{ $col }}; {{ $font }} color: #0f172a; font-size: {{ $fontSize }};" cellpadding="0" cellspacing="0">
<tr><td style="padding: 0.7em; vertical-align: top;">
<table style="width: 100%; height: 44.6em; border-collapse: separate; border-spacing: 0; background: #ffffff; border: 0.18em solid {{ $gold }};" cellpadding="0" cellspacing="0">
    {{-- Top: wordmark and the Verified Partner ribbon --}}
    <tr>
        <td style="height: 6.4em; padding: 0.7em 2.4em 0 2.4em; vertical-align: top;">
            <table style="width: 100%; border-collapse: collapse;" cellpadding="0" cellspacing="0"><tr>
                <td style="vertical-align: middle;">
                    @include('partner.brand-pill', ['logo' => $c['logo'] ?? null, 'size' => '1.95em'])
                </td>
                <td style="vertical-align: middle; text-align: right;">
                    <span style="display: inline-block; background: {{ $col }}; color: #ffffff; border-radius: 1em; padding: 0.45em 1.1em; font-weight: bold; font-size: 1.15em; letter-spacing: 0.08em;">{!! $tick !!} VERIFIED PARTNER</span>
                </td>
            </tr></table>
        </td>
    </tr>
    {{-- Title --}}
    <tr>
        <td style="height: 5.6em; padding: 0 2.4em; text-align: center; vertical-align: top;">
            <div style="font-size: 2.7em; font-weight: bold; letter-spacing: 0.12em; color: #0f172a;">CERTIFICATE OF VERIFICATION</div>
            <div style="margin: 0.7em auto 0 auto; width: 16em; height: 0.18em; background: {{ $gold }}; font-size: 1em;"></div>
        </td>
    </tr>
    {{-- Who --}}
    <tr>
        <td style="height: 15.4em; padding: 0 3em; text-align: center; vertical-align: top;">
            <div style="font-size: 1.35em; color: #475569;">This is to certify that</div>
            <div style="margin-top: 0.25em; font-size: 3.5em; font-weight: bold; line-height: 1.15; color: {{ $col }};">{{ $c['name'] }}</div>
            <div style="margin-top: 0.4em; font-size: 1.5em; font-weight: bold; color: #0f172a;">{{ $c['label'] }}@if ($c['city']) · {{ $c['city'] }}@endif</div>
            @if ($c['owner'])
                <div style="margin-top: 0.3em; font-size: 1.25em; color: #334155;">Owner: <span style="font-weight: bold;">{{ $c['owner'] }}</span></div>
            @endif
            <div style="margin-top: 0.9em; font-size: 1.25em; line-height: 1.45; color: #334155;">is a verified partner of 1CallFix. Its documents and details were checked and approved by 1CallFix.</div>
        </td>
    </tr>
    {{-- Details · seal · QR --}}
    <tr>
        <td style="height: 12.6em; padding: 0 2.4em; vertical-align: middle;">
            <table style="width: 100%; border-collapse: collapse;" cellpadding="0" cellspacing="0"><tr>
                <td style="width: 34%; vertical-align: middle;">
                    <table style="border-collapse: collapse; font-size: 1.2em;" cellpadding="0" cellspacing="0">
                        <tr><td style="color: #64748b; padding: 0.22em 0.9em 0.22em 0;">Partner ID</td><td style="font-weight: bold;">{{ $c['code'] }}</td></tr>
                        <tr><td style="color: #64748b; padding: 0.22em 0.9em 0.22em 0;">Verified since</td><td style="font-weight: bold;">{{ $c['since'] }}</td></tr>
                        <tr><td style="color: #64748b; padding: 0.22em 0.9em 0.22em 0;">Valid till</td><td style="font-weight: bold;">{{ $c['valid_until'] }}</td></tr>
                        @if ($c['fssai'])
                            <tr><td style="color: #64748b; padding: 0.22em 0.9em 0.22em 0;">FSSAI</td><td style="font-weight: bold;">{{ $c['fssai'] }}</td></tr>
                        @endif
                    </table>
                </td>
                <td style="width: 32%; text-align: center; vertical-align: middle;">
                    <div style="margin: 0 auto; width: 9.6em; height: 9.6em; border-radius: 4.8em; background: {{ $gold }}; font-size: 1em;">
                        <div style="width: 8.4em; height: 8.4em; border-radius: 4.2em; margin: 0 auto; position: relative; top: 0.6em; background: {{ $col }}; color: #ffffff; text-align: center; font-size: 1em;">
                            {{-- Padding on a 1 em box: an em padding on a bigger-font element would be bigger too. --}}
                            <div style="padding-top: 1.3em; font-size: 1em; line-height: 1.2;">
                                <div style="font-size: 1.8em; line-height: 1;">{!! $tick !!}</div>
                                <div style="font-size: 1.1em; font-weight: bold; letter-spacing: 0.1em;">VERIFIED</div>
                                <div style="font-size: 1.1em; font-weight: bold; letter-spacing: 0.1em;">PARTNER</div>
                                <div style="margin-top: 0.2em; font-size: 0.95em;">{{ $c['year'] }}</div>
                            </div>
                        </div>
                    </div>
                </td>
                <td style="width: 34%; text-align: right; vertical-align: middle;">
                    <table style="border-collapse: collapse; margin-left: auto;" cellpadding="0" cellspacing="0"><tr>
                        <td style="vertical-align: middle; text-align: right; padding-right: 0.8em; font-size: 1.05em; color: #334155; line-height: 1.35;">Scan to check<br>this certificate<br>is genuine</td>
                        <td style="vertical-align: middle;"><img src="{{ $c['qr'] }}" alt="QR code to check this certificate" style="width: 9em; height: 9em; display: block;"></td>
                    </tr></table>
                </td>
            </tr></table>
        </td>
    </tr>
    {{-- Issued by --}}
    <tr>
        <td style="height: 4.2em; background: {{ $col }}; color: #ffffff; text-align: center; vertical-align: middle; padding: 0 2em;">
            <div style="font-size: 1.15em; font-weight: bold;">Issued by {{ $c['company'] }} · 1callfix.com{{ $c['office_phone'] ? ' · Call '.$c['office_phone'] : '' }}</div>
            <div style="margin-top: 0.2em; font-size: 0.95em;">Valid only while the QR shows Active.</div>
        </td>
    </tr>
</table>
</td></tr>
</table>
