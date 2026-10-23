<div @if ($restore?->isBusy() || ($backupNow['state'] ?? null) === 'running') wire:poll.3s @endif>
    {{-- REF 1CF-BACKUP-RESTORE-001 --}}
    <h1 class="text-2xl font-bold mb-4">Backup &amp; Restore</h1>

    <x-ui.filter-tabs class="mb-4" :tabs="['backup' => 'Backup & restore one', 'deleted' => 'Deleted items']" :active="$tab" model="tab" />

    @if ($flash)
        <div @class(['rounded px-4 py-2 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>{{ $flash }}</div>
    @endif

    @if ($tab === 'backup')
        {{-- Data backup --}}
        <x-ui.card class="mb-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-gray-900">Data backup</h2>
                    <p class="text-sm text-gray-600 mt-1">
                        @if ($status['last_at'])
                            Last copy: {{ $status['last_at']->format('d/m/Y, H:i') }} (server clock). {{ $status['copies'] }} copies kept.
                        @else
                            No backup found yet.
                        @endif
                    </p>
                    @if ($status['remote_error'])
                        <p class="text-xs text-red-600 mt-1">{{ $status['remote_error'] }}</p>
                    @endif
                    @if ($backupNow)
                        <p @class(['text-xs mt-1', 'text-gray-500' => $backupNow['state'] === 'running', 'text-green-700' => $backupNow['state'] === 'done', 'text-red-600' => $backupNow['state'] === 'failed'])>
                            Back up now ({{ $backupNow['at'] }}): {{ $backupNow['message'] }}
                        </p>
                    @endif
                </div>
                <span @class(['rounded-full px-2 py-0.5 text-xs font-medium shrink-0', 'bg-green-50 text-green-700' => $status['healthy'], 'bg-amber-50 text-amber-700' => ! $status['healthy']])>
                    {{ $status['healthy'] ? 'Healthy' : 'Late' }}
                </span>
            </div>
            <div class="mt-3">
                <x-ui.button variant="secondary" wire:click="backUpNow" wire:loading.attr="disabled" :disabled="($backupNow['state'] ?? null) === 'running'">Back up now</x-ui.button>
            </div>
        </x-ui.card>

        {{-- Restore one --}}
        <x-ui.card class="mb-6">
            <h2 class="font-semibold text-gray-900">Restore one franchise, city, store or provider</h2>
            <p class="text-sm text-gray-600 mt-1">
                Brings back that item's records that were deleted after the chosen backup. Newer records are never
                overwritten and money (payments, wallets, payouts, commissions, refunds) is never changed: money
                differences are listed for the approval screens. A safety copy is saved first, so a restore can be undone.
            </p>

            @if (! $restore || in_array($restore->status, ['done', 'failed'], true))
                <div class="grid gap-3 mt-4">
                    <select wire:model.live="scopeType" class="border rounded px-3 py-2 text-sm">
                        @foreach ($types as $key => $def)
                            <option value="{{ $key }}">{{ $def['label'] }}</option>
                        @endforeach
                    </select>
                    <div class="flex gap-2">
                        <select wire:model="scopeId" class="border rounded px-3 py-2 text-sm flex-1 min-w-0">
                            <option value="">Choose a {{ strtolower($types[$scopeType]['label']) }}…</option>
                            @foreach ($items as $item)
                                <option value="{{ $item['id'] }}">{{ $item['name'] }}</option>
                            @endforeach
                        </select>
                        <input type="number" min="1" wire:model="scopeId" placeholder="or ID" class="border rounded px-3 py-2 text-sm w-28" title="If it was removed permanently, enter its ID">
                    </div>
                    @error('scopeId') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    <select wire:model="backupId" class="border rounded px-3 py-2 text-sm">
                        <option value="">Choose a backup time (server clock)…</option>
                        @foreach ($backups as $b)
                            <option value="{{ $b['id'] }}">{{ $b['label'] }}</option>
                        @endforeach
                    </select>
                    @error('backupId') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    <x-ui.button variant="secondary" wire:click="compare" wire:loading.attr="disabled">Compare with now</x-ui.button>
                </div>
            @endif

            @if ($restore)
                <div class="mt-5 border-t pt-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="font-medium text-gray-900">
                            {{ $types[$restore->scope_type]['label'] ?? $restore->scope_type }}: {{ $restore->scope_label ?? '#'.$restore->scope_id }}
                            <span class="text-gray-500 font-normal">vs backup of {{ $restore->backup_at?->format('d/m/Y H:i') }}</span>
                        </h3>
                        @unless ($restore->isBusy())
                            <button type="button" wire:click="startOver" class="text-sm text-indigo-700 hover:underline">Start over</button>
                        @endunless
                    </div>

                    @if ($restore->status === 'preparing')
                        <p class="text-sm text-gray-600 mt-2">Loading the backup and comparing… this takes up to a minute.</p>
                    @elseif ($restore->status === 'restoring')
                        <p class="text-sm text-gray-600 mt-2">Saving a safety copy and restoring… do not close this page.</p>
                    @endif

                    @if ($restore->error)
                        <div class="rounded bg-red-50 text-red-700 text-sm px-3 py-2 mt-2">{{ $restore->error }}</div>
                    @endif

                    @if ($restore->status === 'done')
                        @php($r = $restore->result ?? [])
                        <div class="rounded bg-green-50 text-green-800 text-sm px-3 py-2 mt-2">
                            Restored.
                            @forelse (array_merge(array_map(fn ($n) => $n.' brought back', $r['inserted'] ?? []), array_map(fn ($n) => $n.' undeleted', $r['undeleted'] ?? [])) as $table => $text)
                                <span class="block">{{ \Illuminate\Support\Str::headline($table) }}: {{ $text }}</span>
                            @empty
                                Nothing was missing.
                            @endforelse
                            @if ($restore->safety_copy_path)
                                <span class="block text-xs mt-1 text-green-700">Safety copy: {{ basename($restore->safety_copy_path) }}</span>
                            @endif
                        </div>
                    @endif

                    @if ($restore->summary && in_array($restore->status, ['ready', 'done', 'restoring'], true))
                        @php($t = $restore->summary['totals'])
                        <p class="text-sm text-gray-700 mt-3">
                            <strong>{{ $t['missing'] }}</strong> missing, <strong>{{ $t['archived'] }}</strong> deleted,
                            <strong>{{ $t['changed'] }}</strong> changed since the backup;
                            {{ $t['new'] }} new since (kept as they are).
                            @if ($t['money'] > 0)
                                <span class="text-amber-700">{{ $t['money'] }} money difference(s): not restored here.</span>
                            @endif
                        </p>

                        @if ($restore->summary['tables'])
                            <x-ui.table class="mt-3">
                                <thead>
                                    <tr class="text-left text-xs uppercase text-gray-500">
                                        <th class="px-3 py-2">Records</th>
                                        <th class="px-3 py-2 text-right">Missing</th>
                                        <th class="px-3 py-2 text-right">Deleted</th>
                                        <th class="px-3 py-2 text-right">Changed</th>
                                        <th class="px-3 py-2 text-right">New since</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($restore->summary['tables'] as $row)
                                        <tr class="border-t align-top">
                                            <td class="px-3 py-2">
                                                {{ \Illuminate\Support\Str::headline($row['table']) }}
                                                @if ($row['money'])
                                                    <span class="ml-1 rounded bg-amber-50 text-amber-700 text-xs px-1.5 py-0.5">Money: use the approval screens</span>
                                                @endif
                                                @if ($row['changed_rows'] && ! $row['money'] && $restore->status === 'ready')
                                                    <details class="mt-1">
                                                        <summary class="text-xs text-indigo-700 cursor-pointer">Show changed records</summary>
                                                        @foreach ($row['changed_rows'] as $changed)
                                                            <div class="mt-2 rounded border px-2 py-1 text-xs">
                                                                <div class="flex justify-between gap-2">
                                                                    <span class="font-medium">#{{ $changed['id'] }}</span>
                                                                    <button type="button" wire:click="restoreRow('{{ $row['table'] }}', {{ $changed['id'] }})" class="text-indigo-700 hover:underline">Put back the backup version</button>
                                                                </div>
                                                                @foreach ($changed['columns'] as $col => $v)
                                                                    <div class="text-gray-600 break-words">{{ $col }}: <span class="line-through">{{ $v['now'] ?? 'empty' }}</span> → {{ $v['then'] ?? 'empty' }}</div>
                                                                @endforeach
                                                            </div>
                                                        @endforeach
                                                    </details>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-right">{{ $row['missing'] }}</td>
                                            <td class="px-3 py-2 text-right">{{ $row['archived'] }}</td>
                                            <td class="px-3 py-2 text-right">{{ $row['changed'] }}</td>
                                            <td class="px-3 py-2 text-right text-gray-500">{{ $row['new'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </x-ui.table>
                        @endif

                        @if ($restore->status === 'ready')
                            <div class="grid gap-2 mt-4">
                                <textarea wire:model="reason" rows="2" placeholder="Reason (required): what was deleted and by whom" class="border rounded px-3 py-2 text-sm"></textarea>
                                @error('reason') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                @if ($t['missing'] + $t['archived'] > 0)
                                    <input type="text" wire:model="confirmText" placeholder="Type RESTORE to confirm" class="border rounded px-3 py-2 text-sm">
                                    @error('confirmText') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                    <x-ui.button wire:click="restoreMissing" wire:loading.attr="disabled">Restore missing records ({{ $t['missing'] + $t['archived'] }})</x-ui.button>
                                @else
                                    <p class="text-sm text-gray-600">Nothing is missing. Changed records can be put back one at a time above.</p>
                                @endif
                            </div>
                        @endif
                    @endif
                </div>
            @endif
        </x-ui.card>

        @if ($history->isNotEmpty())
            <x-ui.card title="Recent restores">
                <ul class="text-sm divide-y">
                    @foreach ($history as $h)
                        <li class="py-2 flex flex-wrap justify-between gap-2">
                            <span>{{ $types[$h->scope_type]['label'] ?? $h->scope_type }}: {{ $h->scope_label ?? '#'.$h->scope_id }} — backup {{ $h->backup_at?->format('d/m/Y H:i') }}</span>
                            <span class="text-gray-500">{{ $h->status }} · {{ $h->executor?->name ?? $h->requester?->name }} · {{ $h->created_at->format('d/m/Y H:i') }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif
    @else
        {{-- Deleted items --}}
        <div class="flex flex-wrap gap-3 mb-4">
            <select wire:model.live="deletedType" class="border rounded px-3 py-2 text-sm">
                <option value="">All kinds</option>
                @foreach ($deletedTypes as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            <input type="text" wire:model.live.debounce.400ms="deletedSearch" placeholder="Search by name…" class="border rounded px-3 py-2 text-sm w-72 max-w-full">
        </div>

        <x-ui.table>
            <thead>
                <tr class="text-left text-xs uppercase text-gray-500">
                    <th class="px-3 py-2">Kind</th>
                    <th class="px-3 py-2">Name</th>
                    <th class="px-3 py-2">Belongs to</th>
                    <th class="px-3 py-2">Deleted</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($deleted as $d)
                    <tr class="border-t" wire:key="del-{{ $d['type'] }}-{{ $d['id'] }}">
                        <td class="px-3 py-2 text-gray-600">{{ $d['type_label'] }}</td>
                        <td class="px-3 py-2 font-medium">{{ $d['name'] }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ $d['parent'] ?? '—' }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ $d['deleted_at']?->format('d/m/Y H:i') }}@if ($d['deleted_by']) by {{ $d['deleted_by'] }}@endif</td>
                        <td class="px-3 py-2 text-right">
                            <button type="button" wire:click="restoreDeleted('{{ $d['type'] }}', {{ $d['id'] }})" class="rounded px-2 py-1 text-xs text-green-700 hover:bg-green-50">Restore</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-6 text-center text-sm text-gray-500">Nothing deleted.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    @endif
</div>
