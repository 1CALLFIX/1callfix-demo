@props(['order'])
@php
    // A food order has its own words for "sent" and "packing" (Site text → Food).
    $t = fn (string $key) => \App\Support\SiteText::get(($order->isFood() && in_array($key, ['sent', 'packing'], true) ? 'food.order.status.' : 'shop_order.status.').$key);
    $label = match (true) {
        $order->status === 'pending' && $order->isAwaitingOnlinePayment() => [$t('awaiting_payment'), 'bg-slate-100 text-slate-600'],
        $order->status === 'pending' => [$t('sent'), 'bg-amber-50 text-amber-800'],
        $order->status === 'accepted' => [$t('accepted'), 'bg-blue-50 text-blue-700'],
        $order->status === 'preparing' => [$t('packing'), 'bg-blue-50 text-blue-700'],
        $order->status === 'ready' && $order->order_type === 'pickup' => [$t('ready_collect'), 'bg-emerald-50 text-emerald-700'],
        $order->status === 'ready' => [$order->assigned_worker_id ? $t('on_the_way') : $t('finding_rider'), 'bg-emerald-50 text-emerald-700'],
        $order->status === 'completed' => [$order->order_type === 'pickup' ? $t('collected') : $t('delivered'), 'bg-emerald-600 text-white'],
        $order->status === 'cancelled' => [$t('cancelled'), 'bg-red-50 text-red-700'],
        default => [ucfirst($order->status), 'bg-slate-100 text-slate-600'],
    };
@endphp
<span {{ $attributes->merge(['class' => 'inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold '.$label[1]]) }}>{{ $label[0] }}</span>
