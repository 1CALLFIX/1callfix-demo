<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>1CallFix certificate {{ $c['code'] }}</title>
<style>
    @page { margin: 12mm; }
    body { font-family: Arial, Helvetica, sans-serif; color: #0f172a; font-size: 10pt; margin: 0; }
    .cut { border: 0.3mm dashed #94a3b8; padding: 2mm; width: 123mm; vertical-align: top; }
</style>
</head>
<body>
    {{-- Owner 2026-11-26: page 1 the certificate (A4 landscape, 1 em = 3.9 mm → 269 × 179 mm), page 2 two "Scan to
         order / book" stickers (about A5 each) to cut apart: one for the door, one for the counter. --}}
    @include('partner.certificate.certificate', ['c' => $c, 'fontSize' => '3.9mm'])

    <div style="page-break-before: always;">
        <table style="border-collapse: collapse;" cellpadding="0" cellspacing="0">
            <tr>
                <td class="cut">@include('partner.certificate.sticker', ['c' => $c, 'fontSize' => '3.6mm'])</td>
                <td style="width: 8mm;"></td>
                <td class="cut">@include('partner.certificate.sticker', ['c' => $c, 'fontSize' => '3.6mm'])</td>
            </tr>
        </table>
        <div style="margin-top: 3mm; color: #475569; font-size: 9pt;">
            Print both pages in colour at 100% (actual size). Frame the certificate at your counter or on the wall. Cut the
            stickers along the dashed lines: one for the door, one for the counter. Customers scan to see you on 1CallFix.
        </div>
    </div>
</body>
</html>
