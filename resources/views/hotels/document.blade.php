<?php
    $m = fn (float $v) => $currencySymbol.number_format($v, 2);
    $isInvoice = $kind === 'invoice';
    $discount = (float) $r->coupon_discount_amount;
    $taxable = (float) $r->price_quoted - $discount;
    $time = fn ($t, $d) => \Illuminate\Support\Carbon::parse($t ?: $d)->format('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $isInvoice ? 'Tax invoice INV-'.$r->code : 'Booking voucher '.$r->code }}</title>
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
        .code { font-size: 36px; font-weight: 700; letter-spacing: .3em; margin: 4px 0; }
        @media print { .noprint { display: none; } body { padding: 0; } }
        @media (max-width: 600px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap" data-testid="hotel-document-{{ $kind }}">
    <p class="noprint"><button type="button" onclick="window.print()">Print / save as PDF</button></p>
    <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start">
        <div>
            <h1>{{ $isInvoice ? 'Tax invoice' : 'Booking voucher' }}</h1>
            <p class="muted">
                @if ($isInvoice) Invoice INV-{{ $r->code }} · {{ optional($r->checked_out_at ?? $r->completed_at)->format('d M Y') }} · @endif
                Booking {{ $r->code }} · booked {{ optional($r->created_at)->format('d M Y') }}
            </p>
        </div>
        <p class="muted" style="text-align:right">Booked through {{ $platformName }}<br>@if ($isInvoice)(e-commerce operator)@endif</p>
    </div>

    @if (! $isInvoice && $r->check_in_code && in_array($r->status, ['confirmed', 'checked_in'], true))
        <div class="box" style="margin-top:16px;text-align:center">
            <strong>Check-in code</strong>
            <p class="code">{{ $r->check_in_code }}</p>
            <span class="muted">Show this code and the guest's photo ID at the front desk.</span>
        </div>
    @endif

    <div class="grid">
        <div class="box">
            <strong>{{ $isInvoice ? 'Supplied by' : 'Hotel' }}</strong><br>
            {{ $hotel?->name }}<br>
            {{ $hotel?->address_line }}<br>
            @if ($isInvoice && $hotel?->gst_number) GSTIN: {{ $hotel->gst_number }}<br>@endif
            @if ($hotel?->contact_phone) Phone: {{ $hotel->contact_phone }}<br>@endif
            Check-in from {{ $time($hotel?->check_in_time_start, '14:00') }} · Check-out by {{ $time($hotel?->check_out_time, '11:00') }}
        </div>
        <div class="box">
            <strong>{{ $isInvoice ? 'Bill to' : 'Guest' }}</strong><br>
            @if ($isInvoice && $r->guest_gst_number)
                {{ $r->guest_business_name }}<br>GSTIN: {{ $r->guest_gst_number }}<br>Guest: {{ $guest?->name }}<br>
            @else
                {{ $guest?->name ?? $r->customer?->name }}<br>
                @if ($guest?->phone) {{ $guest->phone }}<br>@endif
            @endif
            {{ $r->number_of_adults }} {{ \Illuminate\Support\Str::plural('adult', $r->number_of_adults) }}@if ($r->number_of_children > 0), {{ $r->number_of_children }} {{ \Illuminate\Support\Str::plural('child', $r->number_of_children) }}@endif
        </div>
    </div>

    <table>
        <thead><tr><th>Stay</th><th class="r">Rooms</th><th class="r">Nights</th><th class="r">Amount</th></tr></thead>
        <tbody>
            @foreach ($r->rooms as $line)
                <tr>
                    <td>{{ $line->roomType?->name }} · {{ \App\Support\SiteText::get('hotel.meal.'.($line->ratePlan?->meal_plan ?? 'room_only')) }}<br>
                        <span class="muted">{{ $r->check_in_date->format('D, j M Y') }} → {{ $r->check_out_date->format('D, j M Y') }}</span></td>
                    <td class="r">{{ $line->room_count }}</td>
                    <td class="r">{{ $r->number_of_nights }}</td>
                    <td class="r">{{ $m((float) $r->price_quoted) }}</td>
                </tr>
            @endforeach
            @if ($discount > 0)
                <tr><td colspan="3" class="r">Coupon {{ $r->coupon_code }}</td><td class="r">−{{ $m($discount) }}</td></tr>
            @endif
            @if ($isInvoice)
                <tr><td colspan="3" class="r">Taxable value</td><td class="r">{{ $m($taxable) }}</td></tr>
            @endif
            @if ((float) $r->tax_amount > 0)
                <tr><td colspan="3" class="r">GST</td><td class="r">{{ $m((float) $r->tax_amount) }}</td></tr>
            @endif
            @if ((float) $r->convenience_fee > 0)
                <tr><td colspan="3" class="r">Convenience fee ({{ $platformName }})</td><td class="r">{{ $m((float) $r->convenience_fee) }}</td></tr>
            @endif
            <tr class="total"><td colspan="3" class="r">{{ $r->payment_status === 'paid' ? 'Total paid' : 'Total' }}</td><td class="r">{{ $m($r->amountDue()) }}</td></tr>
        </tbody>
    </table>
    <p class="muted">Paid by {{ ['online' => 'online payment', 'wallet' => 'wallet'][$r->payment_method] ?? $r->payment_method }}.
        @if ($r->special_requests) Requests to the hotel: {{ $r->special_requests }} @endif</p>
</div>
</body>
</html>
