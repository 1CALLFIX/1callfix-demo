@props(['order'])
@php
    $label = match (true) {
        $order->status === 'pending' && $order->isAwaitingOnlinePayment() => ['Waiting for payment', 'bg-slate-100 text-slate-600'],
        $order->status === 'pending' => ['Sent to the shop', 'bg-amber-50 text-amber-800'],
        $order->status === 'accepted' => ['Accepted', 'bg-blue-50 text-blue-700'],
        $order->status === 'preparing' => ['Being packed', 'bg-blue-50 text-blue-700'],
        $order->status === 'ready' && $order->order_type === 'pickup' => ['Ready to collect', 'bg-emerald-50 text-emerald-700'],
        $order->status === 'ready' => [$order->assigned_worker_id ? 'On the way' : 'Packed, finding a rider', 'bg-emerald-50 text-emerald-700'],
        $order->status === 'completed' => [$order->order_type === 'pickup' ? 'Collected' : 'Delivered', 'bg-emerald-600 text-white'],
        $order->status === 'cancelled' => ['Cancelled', 'bg-red-50 text-red-700'],
        default => [ucfirst($order->status), 'bg-slate-100 text-slate-600'],
    };
@endphp
<span {{ $attributes->merge(['class' => 'inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold '.$label[1]]) }}>{{ $label[0] }}</span>
