<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ ucfirst($type) }} {{ $number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2937; }
        .header { display: flex; justify-content: space-between; margin-bottom: 24px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #e5e7eb; }
        th { color: #6b7280; font-weight: normal; font-size: 11px; text-transform: uppercase; }
        .total-row td { font-weight: bold; border-top: 2px solid #1f2937; border-bottom: none; }
        .status { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
        .status-captured, .status-paid { background: #d1fae5; color: #065f46; }
        .status-pending, .status-created { background: #fef3c7; color: #92400e; }
        .status-failed { background: #fee2e2; color: #991b1b; }
        .footer { margin-top: 40px; font-size: 10px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>{{ ucfirst($type) }}</h1>
            <div class="muted">{{ $number }}</div>
            <div class="muted">{{ $generated_at->format('d M Y, h:i A') }}</div>
        </div>
        <div style="text-align: right;">
            @if ($franchise_name)<div><strong>{{ $franchise_name }}</strong></div>@endif
            <div class="muted">1CallFix</div>
        </div>
    </div>

    <table style="margin-top: 0;">
        <tr>
            <td style="border: none; width: 50%;">
                <div class="muted">Billed to</div>
                <div><strong>{{ $payer_name }}</strong></div>
                @if ($payer_phone)<div class="muted">{{ $payer_phone }}</div>@endif
            </td>
            <td style="border: none; text-align: right;">
                <div class="muted">Status</div>
                <span class="status status-{{ $payment_status }}">{{ ucfirst($payment_status) }}</span>
                @if ($gateway_ref)<div class="muted" style="margin-top: 4px;">Ref: {{ $gateway_ref }}</div>@endif
            </td>
        </tr>
    </table>

    <table>
        <thead>
            <tr><th>Description</th><th style="text-align: right;">Amount</th></tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line['label'] }}</td>
                    <td style="text-align: right;">{{ $currency_symbol }}{{ number_format($line['amount'], 2) }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td>Total</td>
                <td style="text-align: right;">{{ $currency_symbol }}{{ number_format($total, 2) }}</td>
            </tr>
            @if ($refunded_amount > 0)
                <tr>
                    <td class="muted">Refunded</td>
                    <td style="text-align: right;" class="muted">-{{ $currency_symbol }}{{ number_format($refunded_amount, 2) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="footer">
        This is a system-generated {{ $type }} and does not require a signature.
        @if ($captured_at) Payment captured {{ $captured_at->format('d M Y, h:i A') }}. @endif
    </div>
</body>
</html>
