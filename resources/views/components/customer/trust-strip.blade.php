@php $t = fn (string $key) => \App\Support\SiteText::get($key); @endphp
{{-- Phase 2 look (owner 2026-11-07): three promises under the service name, in the page's module colour. Only true
     ones: partners are document-checked and approved, the customer's OTP starts and ends the job, every job is rated.
     Words: Admin → Website text → "Service page trust strip". --}}
<ul {{ $attributes->merge(['class' => 'grid grid-cols-3 gap-2']) }} data-trust-strip>
    @foreach ([['shield', 'verified'], ['check-circle', 'otp'], ['star', 'rate']] as [$icon, $key])
        <li class="flex flex-col items-center gap-1 rounded-2xl bg-[var(--mod-soft,#EFF6FF)] px-2 py-3 text-center sm:flex-row sm:items-start sm:gap-2 sm:text-left">
            <x-icon :name="$icon" class="h-5 w-5 shrink-0 text-[var(--mod,#2563EB)]" />
            <span class="min-w-0">
                <span class="block text-xs font-semibold leading-tight text-slate-900">{{ $t('trust.'.$key) }}</span>
                <span class="mt-0.5 block text-[11px] leading-tight text-slate-600">{{ $t('trust.'.$key.'_hint') }}</span>
            </span>
        </li>
    @endforeach
</ul>
