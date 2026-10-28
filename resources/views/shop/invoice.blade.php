<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Tax invoice {{ 'INV-'.$order->code }}</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #0f172a; margin: 0; padding: 24px; background: #fff; }
        .wrap { max-width: 760px; margin: 0 auto; }
        h1 { font-size: 20px; margin: 0; }
        .muted { color: #64748b; font-size: 13px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 14px; }
        th, td { border-bottom: 1px solid #e2e8f0; padding: 8px 4px; text-align: left; }
        th.r, td.r { text-align: right; }
        .total td { font-weight: 700; border-bottom: 0; }
        .box { border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-size: 13px; }
        @media print { .noprint { display: none; } body { padding: 0; } }
        @media (max-width: 600px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <p class="noprint"><button type="button" onclick="window.print()">Print / save as PDF</button></p>
    <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start">
        <div>
            <h1>Tax invoice</h1>
            <p class="muted">Invoice {{ 'INV-'.$order->code }} · {{ optional($order->completed_at)->format('d M Y') }} · Order {{ $order->code }}</p>
        </div>
        <p class="muted" style="text-align:right">Sold through {{ $platformName }}<br>(e-commerce operator)</p>
    </div>

    <div class="grid">
        <div class="box">
            <strong>Sold by</strong><br>
            {{ $store->name }}<br>
            {{ $store->address_line }}<br>
            @if ($store->gstin) GSTIN: {{ $store->gstin }}<br>@endif
            @if ($store->pan) PAN: {{ $store->pan }}<br>@endif
            @if ($store->phone) Phone: {{ $store->phone }}@endif
        </div>
        <div class="box">
            <strong>Bill to</strong><br>
            {{ $order->customer?->name }}<br>
            @if ($order->order_type === 'delivery' && $order->deliveryAddress){{ $order->deliveryAddress->address_line }}<br>@endif
            {{ $order->order_type === 'pickup' ? 'Collected at the shop' : 'Delivered' }}
        </div>
    </div>

    <table>
        <thead><tr><th>Item</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Amount</th></tr></thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td>{{ $item->product_name_snapshot }}@if ($item->variant_name_snapshot) ({{ $item->variant_name_snapshot }})@endif</td>
                    <td class="r">{{ $item->quantity }}</td>
                    <td class="r">{{ $currencySymbol }}{{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="r">{{ $currencySymbol }}{{ number_format((float) $item->line_total, 2) }}</td>
                </tr>
            @endforeach
            <tr><td colspan="3" class="r">Taxable value</td><td class="r">{{ $currencySymbol }}{{ number_format((float) $order->subtotal, 2) }}</td></tr>
            @if ((float) $order->tax_amount > 0)
                <tr><td colspan="3" class="r">GST @ {{ rtrim(rtrim(number_format((float) $store->tax_percent, 2), '0'), '.') }}%</td><td class="r">{{ $currencySymbol }}{{ number_format((float) $order->tax_amount, 2) }}</td></tr>
            @endif
            @if ((float) $order->delivery_fee > 0)
                <tr><td colspan="3" class="r">Delivery</td><td class="r">{{ $currencySymbol }}{{ number_format((float) $order->delivery_fee, 2) }}</td></tr>
            @endif
            <tr class="total"><td colspan="3" class="r">Total</td><td class="r">{{ $currencySymbol }}{{ number_format((float) $order->total_amount, 2) }}</td></tr>
        </tbody>
    </table>
    <p class="muted">Paid by {{ ['online' => 'online payment', 'wallet' => 'wallet', 'cash' => 'cash'][$order->payment_method] ?? $order->payment_method }}.
        @if (in_array($order->payment_status, ['refunded', 'partially_refunded'], true)) A refund was issued on this order (see the order page). @endif</p>

    @if ($grievance)
        <div class="box" style="margin-top:16px">
            <strong>Grievance officer</strong><br>
            @foreach ($grievance as $line){{ $line }}<br>@endforeach
        </div>
    @endif
</div>
</body>
</html>
