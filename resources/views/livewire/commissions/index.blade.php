<div>
    <h1 class="text-2xl font-bold mb-4">Commissions</h1>

    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-4">
        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="text-xs text-gray-500 mb-1">Provider earnings (filtered)</div>
            <div class="text-xl font-semibold font-mono">₹{{ number_format($providerTotal, 2) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="text-xs text-gray-500 mb-1">Franchise revenue share (filtered)</div>
            <div class="text-xl font-semibold font-mono">₹{{ number_format($franchiseTotal, 2) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="text-xs text-gray-500 mb-1">Platform commission (filtered)</div>
            <div class="text-xl font-semibold font-mono">₹{{ number_format($platformTotal, 2) }}</div>
        </div>
    </div>

    <div class="flex flex-wrap gap-3 mb-4">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search booking code or provider name..." class="border rounded px-3 py-2 text-sm w-72">
        <select wire:model.live="franchiseFilter" class="border rounded px-3 py-2 text-sm">
            <option value="">All franchises</option>
            @foreach ($franchises as $f)
                <option value="{{ $f->id }}">{{ $f->name }}</option>
            @endforeach
        </select>
        <input type="date" wire:model.live="fromDate" class="border rounded px-3 py-2 text-sm">
        <span class="self-center text-gray-400 text-sm">to</span>
        <input type="date" wire:model.live="toDate" class="border rounded px-3 py-2 text-sm">
    </div>

    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Booking</th>
                    <th class="px-4 py-2">Franchise</th>
                    <th class="px-4 py-2">Provider</th>
                    <th class="px-4 py-2">Provider share</th>
                    <th class="px-4 py-2">Franchise share</th>
                    <th class="px-4 py-2">Platform share</th>
                    <th class="px-4 py-2">Date</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($commissions as $c)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2 font-mono text-xs">{{ $c->booking->code ?? '—' }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $c->booking->franchise->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $c->booking->provider->user->name ?? '—' }}</td>
                        <td class="px-4 py-2 font-mono">₹{{ number_format($c->provider_commission, 2) }}</td>
                        <td class="px-4 py-2 font-mono">₹{{ number_format($c->franchise_commission, 2) }}</td>
                        <td class="px-4 py-2 font-mono">₹{{ number_format($c->platform_commission, 2) }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $c->created_at->format('d M Y, h:i A') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No commission records match your filters.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t">{{ $commissions->links() }}</div>
    </div>
</div>
