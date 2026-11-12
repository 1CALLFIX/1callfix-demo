<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Kitchen slip {{ $order->code }}</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #000; margin: 0; padding: 12px; background: #fff; }
        .slip { max-width: 300px; margin: 0 auto; }
        h1 { font-size: 20px; margin: 0; }
        .muted { font-size: 13px; }
        .big { font-size: 22px; font-weight: 800; letter-spacing: .02em; }
        hr { border: 0; border-top: 2px dashed #000; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; font-size: 16px; }
        td { padding: 4px 0; vertical-align: top; }
        td.q { width: 44px; font-weight: 800; font-size: 18px; }
        .sub { font-size: 13px; }
        .note { font-size: 15px; font-weight: 700; border: 2px solid #000; padding: 6px; margin-top: 8px; }
        button { font-size: 16px; padding: 10px 16px; margin-bottom: 12px; }
        @media print { .noprint { display: none; } body { padding: 0; } @page { margin: 4mm; } }
    </style>
</head>
<body>
<div class="slip" data-testid="kitchen-slip">
    <p class="noprint"><button type="button" onclick="window.print()">Print</button></p>
    <h1>{{ $order->store?->name }}</h1>
    <p class="muted">Kitchen slip · {{ $time }}</p>
    <hr>
    <p class="big">{{ $order->code }}</p>
    <p class="muted"><strong>{{ $order->order_type === 'pickup' ? 'PICKUP — customer collects' : 'DELIVERY — rider collects' }}</strong></p>
    <hr>
    <table>
        @foreach ($order->items as $item)
            <tr>
                <td class="q">{{ $item->quantity }}×</td>
                <td>{{ $item->product_name_snapshot }}
                    @if ($item->variant_name_snapshot)<div class="sub">{{ $item->variant_name_snapshot }}</div>@endif
                    @if (! empty($item->add_ons_snapshot))<div class="sub">+ {{ collect($item->add_ons_snapshot)->pluck('name')->implode(', ') }}</div>@endif
                </td>
            </tr>
        @endforeach
    </table>
    @if ($order->special_instructions)
        <div class="note">Note: {{ $order->special_instructions }}</div>
    @endif
    <hr>
    <p class="muted">{{ $order->items->sum('quantity') }} {{ \Illuminate\Support\Str::plural('item', $order->items->sum('quantity')) }}</p>
</div>
<script>
    // Opened from "Print kitchen slip": print straight away (the button stays for a second copy).
    if (new URLSearchParams(location.search).get('print') === '1') { window.addEventListener('load', () => window.print()); }
</script>
</body>
</html>
