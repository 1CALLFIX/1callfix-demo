<div>
    @if (session('message'))
        <div class="mb-4 rounded bg-green-50 px-4 py-2 text-sm text-green-700" data-flash>{{ session('message') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded bg-red-50 px-4 py-2 text-sm text-red-700" data-flash>{{ session('error') }}</div>
    @endif

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="mb-1 text-2xl font-bold">Rent anything</h1>
            <p class="text-sm text-gray-500">People post things they rent out (camera, speaker, furniture, tools); customers call the owner. Check every post before customers see it, and every report. Oldest first.</p>
        </div>
        <a href="{{ route('admin.halls.settings') }}#set-rent-anything-choices" class="text-sm underline">Rental Settings →</a>
    </div>

    @if (isset($item))
        <x-ui.button variant="ghost" wire:click="backToList" class="mb-2">&larr; Back to items</x-ui.button>
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold">{{ $item->title }} @if ($item->is_featured) <span class="text-sm text-amber-600">★</span> @endif</h2>
                <p class="text-sm text-gray-500">₹{{ number_format((float) $item->price) }} per {{ mb_strtolower((string) $item->price_unit) }} · deposit {{ $item->deposit ? '₹'.number_format((float) $item->deposit) : '—' }} · {{ $item->can_deliver ? 'can deliver' : 'pickup only' }}</p>
                <p class="text-sm text-gray-500">{{ $item->category }} · {{ $item->condition ?? '—' }}</p>
                <p class="text-sm text-gray-500">{{ $item->zone?->name }} · {{ $item->landmark ?? '—' }} · {{ $item->franchise?->name }}</p>
                <p class="text-sm text-gray-500">Owner: {{ $item->user?->name }} · {{ $item->user?->phone }} · sent {{ $item->submitted_at?->diffForHumans() ?? '—' }}</p>
            </div>
            <div class="flex flex-col items-end gap-1">
                <x-ui.badge size="lg">{{ $item->statusLabel() }}</x-ui.badge>
                @if ($item->hidden_for_reports_at) <span class="text-xs font-semibold text-red-700">Hidden by reports</span>
                @elseif ($item->status === 'approved') <span class="text-xs text-gray-600">{{ $item->isLive() ? 'Customers see it until '.$item->expires_at?->format('j M') : ($item->is_active ? 'Ran out' : 'Switched off') }}</span> @endif
            </div>
        </div>

        @if ($item->reports->isNotEmpty())
            <x-ui.card class="mb-4 border-red-200">
                <h3 class="mb-2 font-semibold text-red-800">Reports ({{ $item->open_reports }} open)</h3>
                <ul class="space-y-1 text-sm" data-testid="item-reports">
                    @foreach ($item->reports->sortByDesc('id') as $r)
                        <li><x-ui.badge>{{ $r->status }}</x-ui.badge> <strong>{{ $r->reasonLabel() }}</strong>@if ($r->note) : {{ $r->note }} @endif <span class="text-gray-500">· {{ $r->user?->name }} · {{ $r->created_at->diffForHumans() }}</span></li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        @if ($item->coverUrl()) <img src="{{ $item->coverUrl() }}" alt="Item cover" class="mb-2 aspect-video w-full max-w-xl rounded-lg object-cover"> @endif
        @if ($item->photoUrls())
            <div class="mb-4 flex flex-wrap gap-2">@foreach ($item->photoUrls() as $url) <img src="{{ $url }}" alt="" class="h-20 w-28 rounded object-cover" loading="lazy"> @endforeach</div>
        @endif
        @if ($item->about) <x-ui.card class="mb-4"><h3 class="mb-2 font-semibold">A few words</h3><p class="whitespace-pre-line text-sm text-gray-700">{{ $item->about }}</p></x-ui.card> @endif

        <div class="mb-3">
            <input type="text" wire:model="reason" maxlength="500" placeholder="Reason, when you send it back or take it down (the owner sees it)" class="w-full rounded border px-3 py-2 text-sm">
            @error('reason') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($item->status === 'in_review') <x-ui.button wire:click="approve({{ $item->id }})" data-testid="item-approve">Approve</x-ui.button> @endif
            @if ($item->open_reports > 0)
                <x-ui.button wire:click="dismissReports({{ $item->id }})">Item is fine (close reports)</x-ui.button>
                <x-ui.button variant="danger" wire:click="upholdReports({{ $item->id }})">Agree, take it down</x-ui.button>
            @endif
            @if (in_array($item->status, ['in_review', 'approved'], true))
                <x-ui.button variant="danger" wire:click="sendBack({{ $item->id }})" wire:confirm="Send this item back to the owner? Customers stop seeing it until approved again.">Send back for changes</x-ui.button>
            @endif
            @if ($item->status === 'approved')
                <x-ui.button variant="secondary" wire:click="toggleActive({{ $item->id }})">{{ $item->is_active ? 'Switch off' : 'Switch on' }}</x-ui.button>
                <x-ui.button variant="secondary" wire:click="toggleFeatured({{ $item->id }})">{{ $item->is_featured ? 'Remove from featured' : 'Feature it' }}</x-ui.button>
            @endif
        </div>
    @else
        <x-ui.filter-tabs class="mb-3" :tabs="collect(\App\Livewire\RentalItems\Manage::TABS)->map(fn ($l, $k) => $l.($k === 'in_review' && $inReview ? ' ('.$inReview.')' : '').($k === 'reported' && $reported ? ' ('.$reported.')' : ''))->all()" :active="$statusFilter" model="statusFilter" />
        <x-ui.table>
            <x-slot:footer>{{ $items->links() }}</x-slot:footer>
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr><x-ui.sno-th /><th class="px-4 py-2">Item</th><th class="px-4 py-2">Owner</th><th class="px-4 py-2">Price</th><th class="px-4 py-2">Waiting</th><th class="px-4 py-2">Status</th><th class="px-4 py-2"></th></tr>
            </thead>
            <tbody>
                @forelse ($items as $i)
                    <tr class="border-t" data-testid="item-row">
                        <x-ui.sno :rows="$items" :loop="$loop" />
                        <td class="px-4 py-2"><div class="font-medium">{{ $i->name }} @if ($i->is_featured) <span class="text-xs text-amber-600">★</span> @endif</div><div class="text-xs text-gray-500">{{ $i->category }} · {{ $i->zone?->name }} · {{ $i->franchise?->name }}</div></td>
                        <td class="px-4 py-2">{{ $i->user?->name }}<div class="text-xs text-gray-500">{{ $i->user?->phone }}</div></td>
                        <td class="px-4 py-2">₹{{ number_format((float) $i->price) }} / {{ mb_strtolower((string) $i->price_unit) }}</td>
                        <td class="px-4 py-2 text-xs text-gray-600">{{ $i->status === 'in_review' ? $i->submitted_at?->diffForHumans(null, true) : '—' }}</td>
                        <td class="px-4 py-2">
                            <x-ui.badge>{{ $i->statusLabel() }}</x-ui.badge>
                            @if ($i->open_reports > 0) <div class="mt-1 text-xs font-semibold text-red-700">🚩 {{ $i->open_reports }} report(s){{ $i->hidden_for_reports_at ? ', hidden' : '' }}</div> @endif
                            @if ($i->status === 'approved' && ! $i->is_active) <div class="mt-1 text-xs text-gray-500">Switched off</div> @endif
                        </td>
                        <td class="px-4 py-2 text-right"><x-ui.button variant="ghost" wire:click="view({{ $i->id }})">View</x-ui.button></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500">Nothing here.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    @endif
</div>
