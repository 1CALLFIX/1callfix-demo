<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h1 class="text-2xl font-bold">Franchise Earnings</h1>
        <div class="flex flex-wrap gap-2">
            @if ($franchises->count() > 1)
                <select wire:model.live="franchiseId" class="border rounded px-3 py-2 text-sm">
                    @foreach ($franchises as $f)
                        <option value="{{ $f->id }}">{{ $f->name }}</option>
                    @endforeach
                </select>
            @endif
            <select wire:model.live="month" class="border rounded px-3 py-2 text-sm">
                @foreach ($months as $m)
                    <option value="{{ $m }}">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $m)->format('F Y') }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <p class="text-sm text-gray-500 mb-4">
        {{ $franchise->name }} earns
        @if ($franchise->commission_model === 'revenue_share')
            {{ rtrim(rtrim(number_format((float) $franchise->commission_value, 2), '0'), '.') }}% of every completed job.
        @else
            on the "{{ str_replace('_', ' ', $franchise->commission_model) }}" model.
        @endif
        Online jobs credit the share to the franchise owner's wallet when the job completes; on cash jobs it is credited as the provider pays the commission.
    </p>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
        <x-ui.card>
            <div class="text-xs text-gray-500 mb-1">Completed jobs</div>
            <div class="text-xl font-semibold font-mono">{{ $jobs }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-xs text-gray-500 mb-1">Job value</div>
            <div class="text-xl font-semibold font-mono">₹{{ number_format($gross, 2) }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-xs text-gray-500 mb-1">Franchise share earned</div>
            <div class="text-xl font-semibold font-mono text-green-700">₹{{ number_format($earned, 2) }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-xs text-gray-500 mb-1">Cash jobs: share not yet collected (all time)</div>
            <div class="text-xl font-semibold font-mono text-amber-700">₹{{ number_format($cashPending, 2) }}</div>
        </x-ui.card>
    </div>

    @if ($seesWallet)
        <x-ui.card class="mb-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <div class="text-xs text-gray-500">Franchise owner's wallet ({{ $franchise->owner?->name }})</div>
                    <div class="text-xl font-semibold font-mono">₹{{ number_format((float) $walletBalance, 2) }}</div>
                </div>
                <p class="text-xs text-gray-500 max-w-md">Payouts to the franchise owner are made by HQ from Payouts, within the franchise payout limits in Settings → Wallet.</p>
            </div>
            @if ($payouts->isNotEmpty())
                <div class="mt-3 text-sm">
                    <div class="font-medium mb-1">Recent payouts</div>
                    @foreach ($payouts as $p)
                        <div class="flex justify-between border-t py-1 text-xs">
                            <span>{{ $p->created_at->format('d M Y') }} · {{ str_replace('_', ' ', $p->status) }}</span>
                            <span class="font-mono">₹{{ number_format((float) $p->amount, 2) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    @elseif (! $franchise->owner_user_id)
        <div class="mb-4 rounded bg-amber-50 px-4 py-2 text-sm text-amber-800">This franchise has no owner set, so its share is not being credited to anyone. Set the owner in Franchises.</div>
    @endif

    <x-ui.table>
        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <th class="px-4 py-2">Booking</th>
                <th class="px-4 py-2">Paid by</th>
                <th class="px-4 py-2">Date</th>
                <th class="px-4 py-2 text-right">Job value</th>
                <th class="px-4 py-2 text-right">Franchise share</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $c)
                <tr class="border-t">
                    <td class="px-4 py-2 font-mono text-xs">{{ $c->booking?->code }}</td>
                    <td class="px-4 py-2 text-xs">{{ $c->booking?->payment_method }}</td>
                    <td class="px-4 py-2"><x-ui.datetime :at="$c->created_at" /></td>
                    <td class="px-4 py-2 text-right font-mono">₹{{ number_format((float) $c->provider_commission + (float) $c->franchise_commission + (float) $c->platform_commission, 2) }}</td>
                    <td class="px-4 py-2 text-right font-mono">₹{{ number_format((float) $c->franchise_commission, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No completed jobs this month.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
