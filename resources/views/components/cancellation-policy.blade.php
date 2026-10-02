@props(['lines' => [], 'booking' => null])

{{-- REF 1CF-CANCEL-POLICY-001 — the cancellation policy in plain language (numbers come from CancellationPolicy::policyLines;
     the visit-charge sentence comes from the booking's snapshot, or the live setting before a booking exists). Never hard-coded. --}}
@php($visitText = app(\App\Services\Cancellation\CancellationPolicy::class)->visitChargeText($booking))
@if ($visitText)
    <p {{ $attributes->merge(['class' => 'mb-2 rounded-xl border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900']) }} data-testid="visit-charge-text">{{ $visitText }}</p>
@endif
<details {{ $attributes->merge(['class' => 'rounded-xl border border-slate-200 bg-white p-3 text-sm']) }}>
    <summary class="cursor-pointer font-semibold text-slate-800">Cancellation policy</summary>
    <ul class="mt-2 list-disc space-y-1.5 pl-5 text-slate-600">
        @foreach ($lines as $line)
            <li>{{ $line }}</li>
        @endforeach
    </ul>
</details>
