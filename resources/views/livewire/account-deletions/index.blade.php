<div>
    <div class="mb-4">
        <h1 class="text-xl font-semibold">Account deletions</h1>
        <p class="text-sm text-gray-500">From the Delete account page (needed by Google Play). Customers close their own account; partners ask here.</p>
    </div>

    @if ($flashMessage)
        <div class="rounded px-4 py-2 mb-4 text-sm bg-green-50 text-green-700">{{ $flashMessage }}</div>
    @endif

    <x-ui.card>
        <h2 class="font-semibold">Partners waiting ({{ $waiting->count() }})</h2>
        <p class="text-sm text-gray-500 mb-3">Settle their cash dues and payouts first, then press Close.</p>
        @forelse ($waiting as $u)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t py-3" wire:key="w-{{ $u->id }}">
                <div class="text-sm">
                    <a href="{{ route('admin.all-users.index', ['search' => $u->phone]) }}" class="font-medium text-blue-700 hover:underline">{{ $u->name }}</a>
                    · {{ $u->phone }}
                    <span class="text-gray-500">· asked {{ $u->deletion_requested_at->diffForHumans() }}</span>
                </div>
                <x-ui.button size="sm" variant="danger" wire:click="close({{ $u->id }})" wire:confirm="Close {{ $u->name }}'s account? Sign-in stops now.">Close</x-ui.button>
            </div>
        @empty
            <p class="text-sm text-gray-500">Nobody waiting.</p>
        @endforelse
    </x-ui.card>

    <x-ui.card class="mt-4">
        <h2 class="font-semibold">Closed, not erased yet ({{ $closed->count() }})</h2>
        <p class="text-sm text-gray-500 mb-3">Details are erased {{ $days }} days after closing. Reopen if the person called Help to undo it.</p>
        @forelse ($closed as $u)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t py-3" wire:key="c-{{ $u->id }}">
                <div class="text-sm">
                    <span class="font-medium">{{ $u->name }}</span> · {{ $u->phone }}
                    <span class="text-gray-500">· erased {{ $u->closed_at->copy()->addDays($days)->format('d M Y') }}</span>
                </div>
                <x-ui.button size="sm" variant="secondary" wire:click="reopen({{ $u->id }})">Reopen</x-ui.button>
            </div>
        @empty
            <p class="text-sm text-gray-500">None.</p>
        @endforelse
    </x-ui.card>
</div>
