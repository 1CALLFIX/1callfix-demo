@props(['active' => 'offers', 'offers' => 0])
{{-- Owner 2026-11-25: past jobs belong on the Jobs tab, not hidden under More. Owner 2026-11-26: the same tab as
     every other partner app (x-partner.tab), 🕘 past work last. --}}
<nav aria-label="Jobs" data-testid="partner-jobs-tabs" class="flex flex-wrap gap-2">
    <x-partner.tab :href="route('provider.jobs.index')" :active="$active === 'offers'" color="amber" icon="🔔" :count="$offers ?: null">New offers</x-partner.tab>
    <x-partner.tab :href="route('provider.history')" :active="$active === 'history'" color="emerald" icon="🕘">Job history</x-partner.tab>
</nav>
