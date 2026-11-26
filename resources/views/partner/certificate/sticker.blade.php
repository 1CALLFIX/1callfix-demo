{{--
    Owner 2026-11-26: the "Scan to order / book on 1CallFix" sticker that comes with the certificate, for the door and
    the counter. Its QR opens the business's own page on 1CallFix (CertificateService::publicUrl), where customers order
    or book; without one it opens the certificate check. 33 × 45 em, inline styles (dompdf), same scale rule as the
    certificate. $c = CertificateService::data(); $fontSize = CSS size of 1 em.
--}}
@php
    $col = $c['colour'];
    $font = 'font-family: Arial, Helvetica, sans-serif;';
    $tick = '<span style="font-family: \'DejaVu Sans\', sans-serif;">&#10003;</span>';
@endphp
<table data-certificate-sticker="{{ $c['kind'] }}" style="width: 33em; height: 45em; border-collapse: separate; border-spacing: 0; background: #ffffff; border: 0.5em solid {{ $col }}; {{ $font }} color: #0f172a; font-size: {{ $fontSize }};" cellpadding="0" cellspacing="0">
    <tr>
        <td style="height: 6.5em; background: {{ $col }}; text-align: center; vertical-align: middle;">
            @include('partner.brand-pill', ['logo' => $c['logo'] ?? null, 'size' => '2.1em'])
        </td>
    </tr>
    <tr>
        <td style="height: 9em; padding: 1.4em 1.6em 0 1.6em; text-align: center; vertical-align: top;">
            <div style="font-size: 2.3em; font-weight: bold;">We are on 1CallFix</div>
            <div style="margin-top: 0.35em; font-size: 1.7em; font-weight: bold; color: {{ $col }}; line-height: 1.2;">{{ $c['name'] }}</div>
        </td>
    </tr>
    <tr>
        <td style="height: 18em; text-align: center; vertical-align: middle;">
            <img src="{{ $c['public_qr'] ?? $c['qr'] }}" alt="QR code for {{ $c['name'] }} on 1CallFix" style="width: 16.5em; height: 16.5em; display: block; margin: 0 auto;">
        </td>
    </tr>
    <tr>
        <td style="height: 6em; padding: 0 1.6em; text-align: center; vertical-align: top;">
            <div style="font-size: 2em; font-weight: bold; color: {{ $col }};">{{ $c['cta'] }}</div>
            <div style="margin-top: 0.4em; font-size: 1.1em; color: #475569;">or open 1callfix.com and search “{{ $c['name'] }}”</div>
        </td>
    </tr>
    <tr>
        <td style="height: 4.5em; background: #f1f5f9; text-align: center; vertical-align: middle; font-size: 1.15em;">
            <span style="color: {{ $col }}; font-weight: bold;">{!! $tick !!} Verified 1CallFix Partner</span> · {{ $c['code'] }}
            @if ($c['office_phone'])<div style="margin-top: 0.2em; color: #334155;">Help: Call {{ $c['office_phone'] }}</div>@endif
        </td>
    </tr>
</table>
