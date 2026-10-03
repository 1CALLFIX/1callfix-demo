<div class="max-w-3xl">
    <h1 class="text-2xl font-bold mb-1">Alert Emails</h1>
    <p class="text-sm text-gray-500 mb-4">
        Critical admin alerts are also sent by email, independent of push notifications. Each alert goes only to admins
        who are allowed to see it (same scoping as the push alert). Super Admin only; every change is audit-logged.
    </p>

    @unless ($mailCanDeliver)
        <div class="rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700 mb-4">
            The mail driver on this server is <strong>{{ $mailDriver }}</strong>. Alert emails are written to the log and are
            <strong>not delivered</strong> to any inbox until MAIL_MAILER is set to a real driver.
        </div>
    @endunless

    @if ($flashMessage)
        <div @class(['rounded px-4 py-2 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <form wire:submit="save">
        <x-ui.card class="mb-4">
            <div class="space-y-3">
                @foreach ($types as $type => $label)
                    <label class="flex items-center gap-3 text-sm">
                        <input type="checkbox" wire:model="enabled.{{ $type }}">
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.button type="submit">Save</x-ui.button>
    </form>
</div>
