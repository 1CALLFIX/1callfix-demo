<div @if ($open) wire:poll.5s @endif class="rounded-2xl border border-slate-200 bg-white p-4 text-sm">
    <p class="font-semibold">Chat with the {{ strtolower($otherName) }}</p>
    <div class="mt-2 max-h-64 space-y-2 overflow-y-auto" aria-live="polite">
        @forelse ($messages as $m)
            <div wire:key="msg-{{ $m->id }}" @class(['flex', 'justify-end' => $m->sender_id === $me])>
                <p @class(['max-w-[80%] rounded-2xl px-3 py-2', 'bg-blue-600 text-white' => $m->sender_id === $me, 'bg-slate-100 text-slate-800' => $m->sender_id !== $me])>
                    {{ $m->body }}
                    <span @class(['mt-0.5 block text-[10px]', 'text-blue-100' => $m->sender_id === $me, 'text-slate-500' => $m->sender_id !== $me])>{{ $m->sender_id === $me ? 'You' : $otherName }} · {{ $m->created_at->diffForHumans(short: true) }}</span>
                </p>
            </div>
        @empty
            <p class="text-slate-500">No messages yet.</p>
        @endforelse
    </div>
    @if ($open)
        <form wire:submit="send" class="mt-3 flex gap-2">
            <label for="chat-body-{{ $orderId }}" class="sr-only">Message</label>
            <input id="chat-body-{{ $orderId }}" type="text" wire:model="body" maxlength="500" placeholder="Type a message"
                   class="block min-h-10 w-full rounded-lg border border-slate-300 px-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30">
            <button type="submit" class="inline-flex min-h-10 shrink-0 items-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700">Send</button>
        </form>
        @if ($error)<p class="mt-1 text-xs text-rose-700">{{ $error }}</p>@endif
    @endif
</div>
