<div>
    <a href="{{ route('admin.workers.index') }}" class="text-sm text-blue-600 hover:underline">&larr; Back to Workers</a>

    <div class="flex items-center justify-between mt-2 mb-4">
        <h1 class="text-2xl font-bold">{{ $fieldWorker->user->name ?? 'Worker #'.$fieldWorker->id }}</h1>
        <div class="flex items-center gap-2">
            <span @class([
                'px-3 py-1.5 rounded text-sm font-medium',
                'bg-amber-100 text-amber-700' => $fieldWorker->kyc_status === 'pending',
                'bg-green-100 text-green-700' => $fieldWorker->kyc_status === 'approved',
                'bg-red-100 text-red-700' => $fieldWorker->kyc_status === 'rejected',
            ])>
                {{ ucfirst($fieldWorker->kyc_status) }}
            </span>
            <span @class(['px-3 py-1.5 rounded text-sm font-medium', 'bg-green-100 text-green-700' => $fieldWorker->is_active, 'bg-gray-100 text-gray-500' => !$fieldWorker->is_active])>
                {{ $fieldWorker->is_active ? 'Active' : 'Inactive' }}
            </span>
        </div>
    </div>

    @if ($flashMessage)
        <div @class(['rounded p-3 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="font-semibold mb-2">Details</div>
            <dl class="text-sm space-y-1">
                <div class="flex justify-between"><dt class="text-gray-500">Phone</dt><dd>{{ $fieldWorker->user->phone ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Franchise / Zone</dt><dd>{{ $fieldWorker->franchise->name ?? '—' }} / {{ $fieldWorker->zone->name ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Applied</dt><dd>{{ app(\App\Services\TimezoneResolver::class)->format($fieldWorker->created_at, $fieldWorker->franchise, 'd M Y, h:i A') }}</dd></div>
                <div class="flex justify-between items-center pt-2 border-t mt-2">
                    <dt class="text-gray-500">Active</dt>
                    <dd><button type="button" wire:click="toggleActive" class="text-xs bg-slate-900 text-white px-3 py-1.5 rounded hover:bg-slate-800">{{ $fieldWorker->is_active ? 'Deactivate' : 'Activate' }}</button></dd>
                </div>
            </dl>
        </div>

        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="font-semibold mb-2">Track Record</div>
            <dl class="text-sm space-y-1">
                <div class="flex justify-between"><dt class="text-gray-500">Jobs completed</dt><dd>{{ $fieldWorker->jobs_completed }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Rating</dt><dd>{{ number_format($fieldWorker->rating_avg, 2) }} / 5</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Currently online</dt><dd>{{ $fieldWorker->is_online ? 'Yes' : 'No' }}</dd></div>
            </dl>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
        <div class="font-semibold mb-3">Capabilities ({{ $fieldWorker->capabilities->count() }})</div>
        @if ($fieldWorker->capabilities->isEmpty())
            <p class="text-sm text-gray-400 mb-3">No capabilities assigned yet.</p>
        @else
            <div class="flex flex-wrap gap-2 mb-4">
                @foreach ($fieldWorker->capabilities as $cap)
                    <span class="inline-flex items-center gap-2 bg-gray-100 rounded px-2 py-1 text-xs">
                        {{ str_replace('_', ' ', $cap->capability_type) }}
                        @if ($cap->serviceCategory) <span class="text-gray-400">— {{ $cap->serviceCategory->name }}</span> @endif
                        <button type="button" wire:click="removeCapability({{ $cap->id }})" class="text-red-500 hover:text-red-700" title="Remove">&times;</button>
                    </span>
                @endforeach
            </div>
        @endif

        <div class="flex flex-wrap items-end gap-2">
            <div>
                <label class="block text-xs font-medium mb-1">Capability type</label>
                <select wire:model="newCapabilityType" class="border rounded px-2 py-1.5 text-sm">
                    @foreach ($this->workerTypeOptions() as $slug => $label)
                        <option value="{{ $slug }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Service category (optional)</label>
                <input type="number" wire:model="newCapabilityServiceCategoryId" placeholder="category ID" class="w-32 border rounded px-2 py-1.5 text-sm">
            </div>
            <button type="button" wire:click="addCapability" class="bg-slate-900 text-white px-4 h-8 rounded text-xs font-medium hover:bg-slate-800">Add Capability</button>
        </div>
        @error('newCapabilityType') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
        <div class="font-semibold mb-2">Partner Relationships ({{ $fieldWorker->partnerLinks->count() }})</div>
        @if ($fieldWorker->partnerLinks->isEmpty())
            <p class="text-sm text-gray-400">No Partner relationships — this worker is platform-direct.</p>
        @else
            <table class="w-full text-xs">
                <thead class="text-left text-gray-500"><tr><th class="pr-3 py-1">Partner</th><th class="pr-3 py-1">Status</th><th class="pr-3 py-1">Primary</th></tr></thead>
                <tbody>
                    @foreach ($fieldWorker->partnerLinks as $link)
                        <tr class="border-t"><td class="pr-3 py-1">{{ $link->provider->user->name ?? 'Provider #'.$link->provider_id }}</td><td class="pr-3 py-1">{{ ucfirst($link->status) }}</td><td class="pr-3 py-1">{{ $link->is_primary ? 'Yes' : 'No' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
        <div class="font-semibold mb-2">Submitted Documents ({{ $fieldWorker->currentDocuments->count() }})</div>
        @if ($fieldWorker->currentDocuments->isEmpty())
            <p class="text-sm text-gray-400">No documents uploaded yet.</p>
        @else
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                @foreach ($fieldWorker->currentDocuments as $doc)
                    <a href="{{ route('admin.kyc.documents.field-worker', $doc->id) }}" target="_blank" class="border rounded p-3 text-center hover:bg-gray-50">
                        <div class="text-xs font-medium mb-1">{{ str_replace('_', ' ', $doc->type) }}</div>
                        <div class="text-xs text-gray-400">{{ $doc->status }}</div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    @if ($fieldWorker->kyc_status === 'pending')
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white rounded-lg shadow-sm p-4">
                <div class="font-semibold mb-2 text-green-700">Approve</div>
                <p class="text-sm text-gray-500 mb-3">Worker will be able to go online and be assigned jobs immediately.</p>
                <button wire:click="approve" class="w-full bg-green-600 text-white rounded py-2 text-sm font-medium hover:bg-green-700">Approve Worker</button>
            </div>

            <div class="bg-white rounded-lg shadow-sm p-4">
                <div class="font-semibold mb-2 text-red-700">Reject</div>
                <input type="text" wire:model="rejectionReason" placeholder="Reason for rejection..." class="w-full border rounded px-3 py-2 text-sm mb-3">
                <button wire:click="reject" class="w-full bg-red-600 text-white rounded py-2 text-sm font-medium hover:bg-red-700">Reject Worker</button>
            </div>
        </div>
    @endif
</div>
