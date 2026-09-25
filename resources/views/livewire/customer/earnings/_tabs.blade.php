{{-- REF 1CF-PROMPT-20260925-EARN3 — Earnings tab strip. Only tabs whose
     switch is ON for this customer are rendered at all. --}}
<div class="flex items-center justify-between">
    <h1 class="text-2xl font-bold tracking-tight">Earnings</h1>
    <a href="{{ route('customer.account') }}" wire:navigate class="text-sm text-slate-500 hover:text-slate-900">Account</a>
</div>
<nav aria-label="Earnings sections" class="mt-4 flex gap-1 border-b border-slate-200">
    @foreach ($tabs as $key => $t)
        @php $isCurrent = request()->routeIs($t['route']); @endphp
        <a href="{{ route($t['route']) }}" wire:navigate @if ($isCurrent) aria-current="page" @endif
           @class([
               'min-h-11 -mb-px inline-flex items-center border-b-2 px-3 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600',
               'border-blue-600 text-blue-700' => $isCurrent,
               'border-transparent text-slate-500 hover:text-slate-900' => ! $isCurrent,
           ])>{{ $t['label'] }}</a>
    @endforeach
</nav>
