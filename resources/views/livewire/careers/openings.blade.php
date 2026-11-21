<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Careers page</h1>
            <p class="text-sm text-gray-500">The jobs on <a href="{{ route('customer.careers') }}" target="_blank" class="text-blue-700 hover:underline">/careers</a>. People apply on each job's page; see them in <a href="{{ route('admin.careers.applications') }}" class="text-blue-700 hover:underline">Job applications</a>. The page's own words are in Website text &amp; language → Careers page.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="togglePage" data-testid="careers-page-toggle"
                    @class(['inline-flex min-h-[44px] items-center gap-2 rounded-xl px-4 text-sm font-semibold', 'bg-green-100 text-green-800' => $pageEnabled, 'bg-gray-100 text-gray-700' => ! $pageEnabled])>
                <span @class(['h-2.5 w-2.5 rounded-full', 'bg-green-500' => $pageEnabled, 'bg-gray-400' => ! $pageEnabled])></span>
                {{ $pageEnabled ? 'Page is on' : 'Page is off' }}
            </button>
            <x-ui.button wire:click="create" class="min-h-[44px]">+ Add a job</x-ui.button>
        </div>
    </div>

    @if ($flashMessage)
        <div class="mb-4 rounded px-4 py-2 text-sm bg-green-50 text-green-700">{{ $flashMessage }}</div>
    @endif

    @if ($showForm)
        <x-ui.card class="mb-6">
            <h2 class="mb-4 text-lg font-semibold">{{ $editingId ? 'Edit job' : 'Add a job' }}</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-medium">Job title</label>
                    <input type="text" wire:model="title" maxlength="150" placeholder="Customer Support Executive" class="w-full rounded border px-3 py-2 text-sm">
                    @error('title') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Web address</label>
                    <div class="flex items-center gap-1 text-sm"><span class="text-gray-500">/careers/</span>
                        <input type="text" wire:model="slug" maxlength="160" placeholder="made from the title" class="w-full rounded border px-3 py-2 text-sm">
                    </div>
                    <p class="mt-1 text-xs text-gray-400">Blank = made from the title. If you change it, the old address keeps working.</p>
                    @error('slug') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Team (badge colour)</label>
                    <select wire:model="team" class="w-full rounded border px-3 py-2 text-sm">
                        <option value="">None</option>
                        @foreach ($teams as $tm) <option value="{{ $tm }}">{{ $tm }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Type</label>
                    <select wire:model="employmentType" class="w-full rounded border px-3 py-2 text-sm">
                        @foreach ($types as $code => $label) <option value="{{ $code }}">{{ $label }}</option> @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Town or city</label>
                        <input type="text" wire:model="location" maxlength="100" class="w-full rounded border px-3 py-2 text-sm">
                        @error('location') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">State</label>
                        <input type="text" wire:model="region" maxlength="100" class="w-full rounded border px-3 py-2 text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium">People needed</label>
                        <input type="number" min="1" wire:model="openings" class="w-full rounded border px-3 py-2 text-sm">
                        @error('openings') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Apply by (optional)</label>
                        <input type="date" wire:model="applyBy" class="w-full rounded border px-3 py-2 text-sm">
                        @error('applyBy') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Pay (optional)</label>
                    <input type="text" wire:model="payText" maxlength="120" placeholder="₹12,000 – ₹15,000 a month + incentives" class="w-full rounded border px-3 py-2 text-sm">
                    <p class="mt-1 text-xs text-gray-400">Blank = nothing about pay is shown.</p>
                    @error('payText') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-medium">Short description</label>
                    <textarea wire:model="summary" rows="2" maxlength="600" class="w-full rounded border px-3 py-2 text-sm"></textarea>
                    @error('summary') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                @foreach (['duties' => "What they'll do", 'requirements' => "What we're looking for", 'perks' => 'What they get'] as $field => $label)
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ $label }}</label>
                        <textarea wire:model="{{ $field }}" rows="6" class="w-full rounded border px-3 py-2 text-sm"></textarea>
                        <p class="mt-1 text-xs text-gray-400">One point per line.</p>
                        @error($field) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endforeach
                <div class="flex flex-col gap-3 md:col-span-2 md:flex-row md:items-center">
                    <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" wire:model="needsTwoWheeler" class="rounded"> Needs a two-wheeler (the form asks)</label>
                    <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" wire:model="isOpen" class="rounded"> Open for applications</label>
                </div>
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <x-ui.button variant="secondary" wire:click="cancel">Cancel</x-ui.button>
                <x-ui.button wire:click="save" data-testid="job-save">Save job</x-ui.button>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-gray-500">
                        <th class="py-2 pr-4">Job</th><th class="py-2 pr-4">Team</th><th class="py-2 pr-4">People</th>
                        <th class="py-2 pr-4">Applications</th><th class="py-2 pr-4">Status</th><th class="py-2 pr-4"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($jobs as $job)
                        <tr class="border-t" data-job-row="{{ $job->id }}">
                            <td class="py-3 pr-4">
                                <p class="font-semibold text-gray-900">{{ $job->title }}</p>
                                <a href="{{ $job->url() }}" target="_blank" class="text-xs text-blue-700 hover:underline">/careers/{{ $job->slug }}</a>
                                @if ($job->pay_text) <p class="text-xs text-emerald-700">{{ $job->pay_text }}</p> @endif
                            </td>
                            <td class="py-3 pr-4">{{ $job->team ?: '—' }}</td>
                            <td class="py-3 pr-4">{{ $job->openings }}</td>
                            <td class="py-3 pr-4">
                                <a href="{{ route('admin.careers.applications', ['jobFilter' => $job->id]) }}" class="text-blue-700 hover:underline">{{ $job->applications_count }}</a>
                                @if (($newCounts[$job->id] ?? 0) > 0)
                                    <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">{{ $newCounts[$job->id] }} new</span>
                                @endif
                            </td>
                            <td class="py-3 pr-4">
                                @if ($job->isAccepting())
                                    <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800">Open</span>
                                @else
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700">Closed</span>
                                @endif
                            </td>
                            <td class="py-3 pr-4">
                                <div class="flex flex-wrap items-center justify-end gap-1">
                                    <button type="button" wire:click="move({{ $job->id }}, 'up')" class="rounded px-2 py-1 text-gray-500 hover:bg-gray-100" title="Move up">↑</button>
                                    <button type="button" wire:click="move({{ $job->id }}, 'down')" class="rounded px-2 py-1 text-gray-500 hover:bg-gray-100" title="Move down">↓</button>
                                    <x-ui.button variant="secondary" size="sm" wire:click="edit({{ $job->id }})">Edit</x-ui.button>
                                    <x-ui.button variant="secondary" size="sm" wire:click="toggleOpen({{ $job->id }})">{{ $job->is_open ? 'Close' : 'Reopen' }}</x-ui.button>
                                    <button type="button" wire:click="delete({{ $job->id }})" wire:confirm="Remove this job? Its page will say the job is closed; applications are kept." class="rounded px-2 py-1 text-xs font-semibold text-red-600 hover:bg-red-50">Remove</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-gray-500">No jobs yet. Tap “+ Add a job”.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
