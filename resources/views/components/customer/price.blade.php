@props([
    'card',
    'currencySymbol' => '₹',
    'size' => 'sm',
])

{{--
    A service's price, exactly as CatalogPresenter resolved it (Phase C).

    No arithmetic happens in this template. `price`, `original_price` and
    `discount_percent` are all computed server-side in
    App\Services\Customer\CatalogPresenter from the existing cascade
    (Service::resolvePrice -> FlashSaleService), and this component only
    formats them. Putting a subtraction in a Blade file is how a customer
    ends up seeing a different number from the one the server would charge.

    The strike-through and the "X% off" pill appear ONLY when
    `original_price` is non-null, which the presenter sets only when there is
    a genuine saving against the service's own list price. A franchise
    override that happens to be higher than base_price is a legitimate local
    price, not a discount, and never renders as one.

    The "From" / "Starts from" prefix is the service's own price_type
    wording (Service::PRICE_TYPE_LABELS): a `quote_on_inspection` service's
    price is explicitly not final, and the label has to say so.
--}}

@php
    $isLarge = $size === 'lg';
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-wrap items-baseline gap-x-2 gap-y-1']) }}>
    {{-- One element, not an sr-only/aria-hidden pair: the visible text and
         the announced text are identical here, so splitting them would just
         put the same words in the accessibility tree twice. --}}
    <span @class(['text-[11px] uppercase tracking-wide text-slate-500', 'text-xs' => $isLarge])>
        {{ $card['price_prefix'] }}
    </span>

    <span @class([
        'font-bold text-slate-900',
        'text-lg' => ! $isLarge,
        'text-3xl' => $isLarge,
    ])>{{ $currencySymbol }}{{ number_format($card['price'], 2) }}</span>

    @if ($card['original_price'] !== null)
        <span class="text-sm text-slate-400 line-through">
            <span class="sr-only">Usual price</span>{{ $currencySymbol }}{{ number_format($card['original_price'], 2) }}
        </span>
        @if ($card['discount_percent'])
            <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-[11px] font-semibold text-emerald-700">
                {{ $card['discount_percent'] }}% off
            </span>
        @endif
    @endif
</div>
