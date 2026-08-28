@props(['status', 'paid' => false])

{{--
    Phase E6 — a booking's real FSM status, worded for the customer and
    never shown by colour alone (each state also carries an icon and text,
    WCAG 1.4.1). The `status` string is the authoritative Booking::status
    value straight from the server; this component only chooses wording and
    a tone for it and invents no state. Unknown values fall through to a
    neutral "Processing" rather than breaking the page.

    `paid` refines the one genuinely ambiguous state: `pending` is both
    "unpaid, waiting on payment" AND "paid, waiting for dispatch to pick it
    up" (a wallet/online booking sits in `pending` until ServiceMatchingJob
    runs). "Awaiting payment" is wrong for the second case, so a paid
    `pending` booking is shown as "Confirmed" instead.
--}}
@php
    if ($status === 'pending' && $paid) {
        $status = '__paid_pending';
    }
    $map = [
        '__paid_pending'     => ['label' => 'Confirmed', 'tone' => 'blue', 'icon' => 'check-circle'],
        'pending'            => ['label' => 'Awaiting payment', 'tone' => 'amber',   'icon' => 'clock'],
        'searching_provider' => ['label' => 'Finding a professional', 'tone' => 'blue', 'icon' => 'magnifying-glass'],
        'assigned'           => ['label' => 'Professional assigned', 'tone' => 'blue', 'icon' => 'check-circle'],
        'provider_en_route'  => ['label' => 'On the way', 'tone' => 'blue', 'icon' => 'arrow-path'],
        'in_progress'        => ['label' => 'Work in progress', 'tone' => 'amber', 'icon' => 'arrow-path'],
        'completed'          => ['label' => 'Completed', 'tone' => 'emerald', 'icon' => 'check-circle'],
        'cancelled'          => ['label' => 'Cancelled', 'tone' => 'rose', 'icon' => 'x-circle'],
        'disputed'           => ['label' => 'Under review', 'tone' => 'amber', 'icon' => 'exclamation-triangle'],
    ];
    $entry = $map[$status] ?? ['label' => 'Processing', 'tone' => 'slate', 'icon' => 'clock'];

    $tones = [
        'amber'   => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'blue'    => 'bg-blue-50 text-blue-700 ring-blue-600/20',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'rose'    => 'bg-rose-50 text-rose-700 ring-rose-600/20',
        'slate'   => 'bg-slate-100 text-slate-600 ring-slate-500/20',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset '.$tones[$entry['tone']]]) }}>
    <x-icon :name="$entry['icon']" class="h-3.5 w-3.5" />
    {{ $entry['label'] }}
</span>
