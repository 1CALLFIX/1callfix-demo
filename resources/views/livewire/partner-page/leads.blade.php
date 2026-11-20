<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
            <h1 class="text-xl font-semibold">Partner leads</h1>
            <p class="text-sm text-gray-500">People who applied on the public Partner page. Read-only, except the status.</p>
        </div>
        <x-ui.button variant="secondary" size="sm" wire:click="exportCsv" title="Export the current filtered view as CSV">Export CSV</x-ui.button>
    </div>

    @if ($flashMessage)
        <div class="rounded px-4 py-2 mb-4 text-sm bg-green-50 text-green-700">{{ $flashMessage }}</div>
    @endif
    @error('status') <p class="mb-3 text-sm text-red-600">{{ $message }}</p> @enderror

    {{-- Owner 2026-11-20: partners who tapped "Help me join" — call them back. A row leaves this box when its status changes. --}}
    @if ($callbacks->isNotEmpty())
        <section data-testid="callbacks" class="mb-5 rounded-2xl border-2 border-amber-300 bg-amber-50 p-4">
            <h2 class="text-base font-bold text-amber-900">📞 Asked for a call ({{ $callbacks->count() }})</h2>
            <p class="mb-3 text-sm text-amber-800">Call them back, then change the status (handed off, converted, ...).</p>
            <div class="space-y-2">
                @foreach ($callbacks as $cb)
                    @php($dial = \App\Livewire\PartnerPage\Leads::dialable($cb->phone))
                    <div data-testid="callback-{{ $cb->id }}" class="flex flex-wrap items-center gap-3 rounded-xl bg-white p-3 shadow-sm">
                        <div class="min-w-[10rem] flex-1">
                            <p class="font-semibold text-gray-900">{{ $cb->name }}</p>
                            <p class="text-sm text-gray-600">{{ $roles[$cb->role] ?? $cb->role }}@if ($cb->city) · {{ $cb->city }}@endif · {{ $cb->phone }}</p>
                        </div>
                        <div class="text-sm text-gray-700"><span class="text-xs text-gray-500">Asked</span> <x-ui.datetime :at="$cb->consent_at ?? $cb->updated_at" /></div>
                        <a href="tel:{{ $dial['tel'] }}" data-testid="callback-call-{{ $cb->id }}" class="inline-flex min-h-[44px] items-center rounded-xl bg-green-600 px-4 font-semibold text-white hover:bg-green-700">📞 Call</a>
                        <a href="{{ $dial['wa'] }}" target="_blank" rel="noopener" class="inline-flex min-h-[44px] items-center rounded-xl bg-emerald-100 px-4 font-semibold text-emerald-800 hover:bg-emerald-200">WhatsApp</a>
                        <select wire:change="setStatus({{ $cb->id }}, $event.target.value)" class="min-h-[44px] rounded-xl border px-2 text-sm" aria-label="Status for {{ $cb->name }}">
                            @foreach ($statuses as $status)
                                <option value="{{ $status }}" @selected($cb->status === $status)>{{ str_replace('_', ' ', $status) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <div class="mb-4 flex flex-wrap gap-3">
        <select wire:model.live="statusFilter" class="border rounded px-3 py-2 text-sm" aria-label="Filter by status">
            <option value="">All statuses</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}">{{ str_replace('_', ' ', $status) }}</option>
            @endforeach
        </select>
        <select wire:model.live="roleFilter" class="border rounded px-3 py-2 text-sm" aria-label="Filter by role">
            <option value="">All roles</option>
            @foreach ($roles as $code => $label)
                <option value="{{ $code }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <x-ui.card>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-gray-500">
                        <th class="py-2 pr-4">Name</th><th class="py-2 pr-4">Mobile</th><th class="py-2 pr-4">City</th>
                        <th class="py-2 pr-4">Role</th><th class="py-2 pr-4">Status</th><th class="py-2 pr-4">Source</th><th class="py-2 pr-4">Applied</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($leads as $lead)
                        <tr data-lead-row class="border-t">
                            <td class="py-2 pr-4">{{ $lead->name }}</td>
                            <td class="py-2 pr-4"><a href="tel:{{ \App\Livewire\PartnerPage\Leads::dialable($lead->phone)['tel'] }}" class="text-blue-700 hover:underline" title="Call">{{ $lead->phone }}</a></td>
                            <td class="py-2 pr-4">{{ $lead->city }}</td>
                            <td class="py-2 pr-4">{{ $roles[$lead->role] ?? $lead->role }}</td>
                            <td class="py-2 pr-4">
                                <select wire:change="setStatus({{ $lead->id }}, $event.target.value)" class="border rounded px-2 py-1 text-xs" aria-label="Status for {{ $lead->name }}">
                                    @foreach ($statuses as $status)
                                        <option value="{{ $status }}" @selected($lead->status === $status)>{{ str_replace('_', ' ', $status) }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="py-2 pr-4">
                                @if ($lead->source === 'help_me_join') <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Asked for a call</span>
                                @else {{ $lead->acquisition['utm_source'] ?? $lead->source }} @endif
                            </td>
                            <td class="py-2 pr-4"><x-ui.datetime :at="$lead->created_at" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-6 text-center text-gray-500">No leads match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $leads->links() }}</div>
    </x-ui.card>
</div>
