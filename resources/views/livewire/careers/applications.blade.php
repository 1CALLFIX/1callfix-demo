<div>
    @php
        $statusColour = ['new' => 'bg-amber-100 text-amber-800', 'shortlisted' => 'bg-blue-100 text-blue-800', 'interview' => 'bg-violet-100 text-violet-800', 'hired' => 'bg-green-100 text-green-800', 'rejected' => 'bg-gray-100 text-gray-700'];
    @endphp
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Job applications @if ($newCount > 0)<span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-sm font-semibold text-amber-800">{{ $newCount }} new</span>@endif</h1>
            <p class="text-sm text-gray-500">People who applied on the <a href="{{ route('admin.careers.openings') }}" class="text-blue-700 hover:underline">Careers page</a>. Call them, then move the status along.</p>
        </div>
        <x-ui.button variant="secondary" size="sm" wire:click="exportCsv" title="Export the current filtered view as CSV">Export CSV</x-ui.button>
    </div>

    @if ($flashMessage)
        <div class="mb-4 rounded px-4 py-2 text-sm bg-green-50 text-green-700">{{ $flashMessage }}</div>
    @endif
    @error('status') <p class="mb-3 text-sm text-red-600">{{ $message }}</p> @enderror

    <div class="mb-4 flex flex-wrap gap-3">
        <select wire:model.live="statusFilter" class="min-h-[44px] rounded border px-3 text-sm" aria-label="Filter by status">
            <option value="">All statuses</option>
            @foreach ($statuses as $code => $label) <option value="{{ $code }}">{{ $label }}</option> @endforeach
        </select>
        <select wire:model.live="jobFilter" class="min-h-[44px] rounded border px-3 text-sm" aria-label="Filter by job">
            <option value="">All jobs</option>
            @foreach ($jobs as $j) <option value="{{ $j->id }}">{{ $j->title }}{{ $j->deleted_at ? ' (removed)' : '' }}</option> @endforeach
            <option value="any">Any role</option>
        </select>
    </div>

    <div class="space-y-3">
        @forelse ($applications as $a)
            @php($dial = \App\Livewire\PartnerPage\Leads::dialable($a->phone))
            <div data-application-row="{{ $a->id }}" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <div class="flex flex-wrap items-start gap-3">
                    <div class="min-w-[12rem] flex-1">
                        <p class="text-base font-semibold text-gray-900">{{ $a->name }}
                            <span class="ml-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $statusColour[$a->status] ?? 'bg-gray-100' }}">{{ $statuses[$a->status] ?? $a->status }}</span>
                        </p>
                        <p class="text-sm text-gray-700">{{ $a->jobTitle() }}</p>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ $a->phone }}@if ($a->city) · {{ $a->city }}@endif
                            @if ($a->experience) · {{ \App\Models\JobApplication::EXPERIENCE[$a->experience] ?? $a->experience }}@endif
                            @if ($a->two_wheeler !== null) · Two-wheeler: {{ $a->two_wheeler ? 'yes' : 'no' }}@endif
                            @if ($a->email) · {{ $a->email }}@endif
                        </p>
                        @if ($a->current_work) <p class="mt-1 text-sm text-gray-600"><span class="text-gray-400">Now:</span> {{ $a->current_work }}</p> @endif
                        @if ($a->message) <p class="mt-1 whitespace-pre-line text-sm text-gray-600"><span class="text-gray-400">Note:</span> {{ $a->message }}</p> @endif
                        @if ($a->admin_notes) <p class="mt-2 whitespace-pre-line rounded-lg bg-yellow-50 px-3 py-2 text-sm text-gray-800"><span class="font-semibold">Team notes:</span> {{ $a->admin_notes }}</p> @endif
                        <p class="mt-1 text-xs text-gray-400">Applied <x-ui.datetime :at="$a->created_at" />@if ($a->submit_count > 1) · sent {{ $a->submit_count }} times @endif</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="tel:{{ $dial['tel'] }}" class="inline-flex min-h-[44px] items-center rounded-xl bg-green-600 px-4 font-semibold text-white hover:bg-green-700">📞 Call</a>
                        <a href="{{ $dial['wa'] }}" target="_blank" rel="noopener" class="inline-flex min-h-[44px] items-center rounded-xl bg-emerald-100 px-4 font-semibold text-emerald-800 hover:bg-emerald-200">WhatsApp</a>
                        @if ($a->cv_path)
                            <a href="{{ route('admin.careers.cv', $a->id) }}" target="_blank" rel="noopener" data-cv-link class="inline-flex min-h-[44px] items-center rounded-xl bg-blue-50 px-4 font-semibold text-blue-700 hover:bg-blue-100">📄 CV</a>
                        @endif
                        <select wire:change="setStatus({{ $a->id }}, $event.target.value)" class="min-h-[44px] rounded-xl border px-2 text-sm" aria-label="Status for {{ $a->name }}">
                            @foreach ($statuses as $code => $label)
                                <option value="{{ $code }}" @selected($a->status === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <button type="button" wire:click="openNotes({{ $a->id }})" class="inline-flex min-h-[44px] items-center rounded-xl bg-gray-100 px-3 text-sm font-semibold text-gray-700 hover:bg-gray-200">Notes</button>
                    </div>
                </div>
                @if ($notesId === $a->id)
                    <div class="mt-3 border-t pt-3">
                        <textarea wire:model="notes" rows="3" maxlength="2000" class="w-full rounded border px-3 py-2 text-sm" placeholder="Called on…, interview on…"></textarea>
                        @error('notes') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        <div class="mt-2 flex justify-end gap-2">
                            <x-ui.button variant="secondary" size="sm" wire:click="$set('notesId', null)">Cancel</x-ui.button>
                            <x-ui.button size="sm" wire:click="saveNotes">Save notes</x-ui.button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <x-ui.card><p class="py-6 text-center text-gray-500">No applications match.</p></x-ui.card>
        @endforelse
    </div>
    <div class="mt-4">{{ $applications->links() }}</div>
</div>
