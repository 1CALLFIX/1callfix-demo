<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>1CallFix ID card {{ $c['code'] }}</title>
<style>
    @page { margin: 10mm 10mm 12mm 10mm; }
    body { font-family: Arial, Helvetica, sans-serif; color: #0f172a; font-size: 10pt; }
    .cut { border: 0.3mm dashed #94a3b8; padding: 1.5mm; width: 86mm; height: 54.4mm; vertical-align: top; }
</style>
</head>
<body>
    {{-- Owner 2026-11-26: both sides side by side at real size (85.6 × 54 mm each) on A4, to cut, stick back to back
         and laminate, or to take to a print shop for a plastic card. 1 em = 85.6 mm / 31.5. --}}
    <div style="font-size: 15pt; font-weight: bold;">Your 1CallFix ID card</div>
    <div style="margin-top: 2mm; color: #334155;">{{ $c['name'] }} · {{ $c['code'] }} · valid till {{ $c['valid_until'] }}</div>

    <table style="margin-top: 8mm; border-collapse: separate; border-spacing: 3mm 0;" cellpadding="0" cellspacing="0">
        <tr>
            <td class="cut">@include('partner.id-card.card', ['c' => $c, 'side' => 'front', 'fontSize' => '2.7175mm'])</td>
            <td class="cut">@include('partner.id-card.card', ['c' => $c, 'side' => 'back', 'fontSize' => '2.7175mm'])</td>
        </tr>
        <tr>
            <td style="text-align: center; color: #64748b; padding-top: 2mm;">Front</td>
            <td style="text-align: center; color: #64748b; padding-top: 2mm;">Back</td>
        </tr>
    </table>

    <div style="margin-top: 10mm; font-size: 11pt; font-weight: bold;">How to make your card</div>
    <ol style="margin-top: 2mm; padding-left: 6mm; line-height: 1.6; color: #334155;">
        <li>Print this page at 100% (actual size), not "fit to page".</li>
        <li>Cut along the dashed lines, stick the back behind the front, and laminate it.</li>
        <li>Or take this file to any print shop and ask for a plastic (PVC) ID card, front and back.</li>
        <li>Wear it on every job. Customers can scan the QR to check you are a genuine 1CallFix partner.</li>
    </ol>
    <div style="margin-top: 6mm; color: #64748b; font-size: 9pt;">
        If you lose this card, open My ID card in the app and tap "I lost my card": the old card stops working at once.
        The card works only while your account is active.
    </div>
</body>
</html>
