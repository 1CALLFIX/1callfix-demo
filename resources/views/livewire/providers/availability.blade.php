<div>
    <h1 class="text-xl font-semibold mb-1">Availability &amp; shifts</h1>
    <p class="text-sm text-gray-500 mb-4">
        How long a provider stays "online", and Swiggy-style shifts: you define the time slots, providers choose the ones they will work.
        Every change is audit-logged. With the mode on <strong>Off</strong> nothing changes for providers.
    </p>

    @if ($flashMessage)
        <div class="rounded px-4 py-2 mb-4 text-sm bg-green-50 text-green-700">{{ $flashMessage }}</div>
    @endif

    @php $input = 'w-full border rounded px-3 py-2 text-sm'; @endphp

    <x-ui.card class="mb-6">
        <h2 class="text-sm font-semibold mb-2">Readiness for "Required"</h2>
        <div class="grid gap-3 sm:grid-cols-4 text-sm">
            <div><p class="text-xs text-gray-500">Approved providers</p><p class="text-xl font-bold">{{ $readiness['total'] }}</p></div>
            <div><p class="text-xs text-gray-500">Have chosen a shift</p><p class="text-xl font-bold">{{ $readiness['with_shift'] }}</p></div>
            <div><p class="text-xs text-gray-500">Not chosen yet</p><p class="text-xl font-bold">{{ $readiness['without_shift'] }}</p></div>
            <div><p class="text-xs text-gray-500">Ready</p><p class="text-xl font-bold {{ $readiness['percent'] >= (int) $requiredMinPercent ? 'text-green-700' : 'text-amber-700' }}">{{ $readiness['percent'] }}%</p></div>
        </div>
        <p class="text-xs text-gray-500 mt-2">Required mode will only save once at least {{ (int) $requiredMinPercent }}% of approved providers have chosen a shift and there is at least one active shift. Stay on Reminder until then.</p>
    </x-ui.card>

    <form wire:submit="saveSettings" class="space-y-6 mb-8">
        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Online rules</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium mb-1" for="av-stale">Auto-offline after (minutes of silence)</label>
                    <input id="av-stale" type="number" min="5" max="240" wire:model="staleMinutes" class="{{ $input }}">
                    <p class="text-xs text-gray-500 mt-1">A provider's phone reports its location every 2 minutes. If nothing arrives for this long they are set offline, so offers never ring a phone that is switched off. Dispatch also ignores them after this time.</p>
                    @error('staleMinutes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="av-mode">Shifts</label>
                    <select id="av-mode" wire:model="mode" class="{{ $input }}">
                        <option value="off">Off — providers go online whenever they like</option>
                        <option value="reminder">Reminder — providers pick shifts and get a nudge before each one</option>
                        <option value="required">Required — providers can only be online inside their shifts</option>
                    </select>
                    @error('mode') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="av-rem">Reminder before shift (minutes, 0 = none)</label>
                    <input id="av-rem" type="number" min="0" max="240" wire:model="reminderMinutes" class="{{ $input }}">
                    @error('reminderMinutes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="av-grace">Grace after shift ends (minutes)</label>
                    <input id="av-grace" type="number" min="0" max="240" wire:model="graceMinutes" class="{{ $input }}">
                    <p class="text-xs text-gray-500 mt-1">Required mode only: how long a provider may stay online after their shift ends, so a job in progress is not cut off.</p>
                    @error('graceMinutes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="mt-4 md:w-1/2">
                <label class="block text-sm font-medium mb-1" for="av-minpct">Required needs at least this % of providers to have chosen a shift</label>
                <input id="av-minpct" type="number" min="0" max="100" wire:model="requiredMinPercent" class="{{ $input }}">
                @error('requiredMinPercent') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            @if ($mode === 'required' && $shifts->where('is_active', true)->isEmpty())
                <p class="mt-3 text-sm text-amber-700">Required mode with no active shifts means nobody can go online. Add a shift below first.</p>
            @endif
        </x-ui.card>
        <x-ui.button type="submit">Save settings</x-ui.button>
    </form>

    <x-ui.card class="mb-6">
        <h2 class="text-sm font-semibold mb-3">Shift slots</h2>
        @if ($shifts->isEmpty())
            <p class="text-sm text-gray-500">No shifts yet. Add the first one below (for example "Morning 08:00–14:00").</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500"><th class="py-1 pr-3">Shift</th><th class="pr-3">Time</th><th class="pr-3">Days</th><th class="pr-3">Providers</th><th class="pr-3">Status</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($shifts as $shift)
                            <tr class="border-t">
                                <td class="py-2 pr-3 font-medium">{{ $shift->name }}</td>
                                <td class="pr-3">{{ \App\Services\Providers\ShiftSchedule::label($shift->start_time) }} – {{ \App\Services\Providers\ShiftSchedule::label($shift->end_time) }}{{ $shift->end_time <= $shift->start_time ? ' (next day)' : '' }}</td>
                                <td class="pr-3">{{ $shift->daysLabel() }}</td>
                                <td class="pr-3">{{ $shift->providers_count }}</td>
                                <td class="pr-3">{{ $shift->is_active ? 'Active' : 'Hidden' }}</td>
                                <td class="text-right whitespace-nowrap">
                                    <button type="button" wire:click="editShift({{ $shift->id }})" class="text-xs underline text-blue-700 mr-3">Edit</button>
                                    <button type="button" wire:click="deleteShift({{ $shift->id }})" wire:confirm="Delete this shift? Providers who chose it lose it." class="text-xs underline text-red-700">Delete</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    <form wire:submit="saveShift">
        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">{{ $editingId ? 'Edit shift' : 'Add a shift' }}</h2>
            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <label class="block text-sm font-medium mb-1" for="sh-name">Name</label>
                    <input id="sh-name" type="text" wire:model="shiftName" placeholder="Morning" class="{{ $input }}">
                    @error('shiftName') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="sh-start">Starts</label>
                    <input id="sh-start" type="time" wire:model="shiftStart" class="{{ $input }}">
                    @error('shiftStart') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="sh-end">Ends</label>
                    <input id="sh-end" type="time" wire:model="shiftEnd" class="{{ $input }}">
                    <p class="text-xs text-gray-500 mt-1">An end earlier than the start runs past midnight.</p>
                    @error('shiftEnd') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <fieldset class="mt-4">
                <legend class="block text-sm font-medium mb-1">Days (none ticked = every day)</legend>
                <div class="flex flex-wrap gap-3 text-sm">
                    @foreach ($dayNames as $n => $label)
                        <label class="inline-flex items-center gap-1"><input type="checkbox" value="{{ $n }}" wire:model="shiftDays" class="rounded border-gray-300"> {{ $label }}</label>
                    @endforeach
                </div>
                @error('shiftDays.*') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </fieldset>
            <label class="mt-4 flex items-center gap-2 text-sm"><input type="checkbox" wire:model="shiftActive" class="rounded border-gray-300"> Active (providers can choose it)</label>
            <div class="mt-4 flex items-center gap-3">
                <x-ui.button type="submit">{{ $editingId ? 'Save shift' : 'Add shift' }}</x-ui.button>
                @if ($editingId) <button type="button" wire:click="cancelEdit" class="text-sm underline text-gray-600">Cancel</button> @endif
            </div>
            <p class="text-xs text-gray-500 mt-3">Times are read in each provider's own city timezone.</p>
        </x-ui.card>
    </form>
</div>
