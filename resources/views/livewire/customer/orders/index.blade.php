{{-- Phase E6 — booking history. Same customer_id-scoped query as
     BookingController::mine(); each row's status is the real FSM value. --}}
@php
    $filters = ['' => 'All', 'active' => 'Active', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    $currencySymbol = \App\Models\Setting::get('locale.currency_symbol', '₹');
@endphp

<div class="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
    <h1 class="text-2xl font-bold tracking-tight">My bookings</h1>

    <div class="mt-4 flex flex-wrap gap-2" role="tablist" aria-label="Filter bookings">
        @foreach ($filters as $key => $label)
            <button wire:click="$set('filter', '{{ $key }}')"
                    @class([
                        'rounded-full px-3.5 py-1.5 text-sm font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600',
                        'bg-blue-600 text-white' => $filter === $key,
                        'bg-slate-100 text-slate-600 hover:bg-slate-200' => $filter !== $key,
                    ])>{{ $label }}</button>
        @endforeach
    </div>

    @forelse ($bookings as $booking)
        <a href="{{ route('customer.orders.show', $booking) }}" wire:navigate
           class="mt-3 block rounded-xl border border-slate-200 p-4 transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="truncate font-semibold text-slate-900">{{ $booking->service?->name ?? 'Service' }}</p>
                    <p class="text-xs text-slate-500">{{ $booking->code }} · {{ app(\App\Services\TimezoneResolver::class)->format($booking->created_at, $booking->franchise, 'j M Y') }}</p>
                </div>
                <x-customer.order-status :status="$booking->status" :paid="$booking->payment_status === 'paid'" />
            </div>
            <div class="mt-2 flex items-center justify-between text-sm">
                <span class="text-slate-600">
                    {{ $booking->scheduled_at ? app(\App\Services\TimezoneResolver::class)->format($booking->scheduled_at, $booking->franchise, 'D j M, g:i A') : 'As soon as possible' }}
                </span>
                <span class="font-medium text-slate-900">
                    {{ $currencySymbol }}{{ number_format((float) ($booking->price_final ?? $booking->price_quoted), 2) }}
                </span>
            </div>
        </a>
    @empty
        <div class="mt-6 rounded-xl border border-dashed border-slate-300 p-8 text-center">
            <p class="text-sm text-slate-600">No bookings here yet.</p>
            <a href="{{ route('customer.services.index') }}" wire:navigate
               class="mt-3 inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                Book a service
            </a>
        </div>
    @endforelse

    <div class="mt-5">{{ $bookings->links() }}</div>
</div>
