<div>
    <a href="{{ route('admin.providers.index') }}" class="text-sm text-blue-600 hover:underline">&larr; Back to Providers</a>

    <div class="flex items-center justify-between mt-2 mb-4">
        <h1 class="text-2xl font-bold">{{ $provider->user->name ?? 'Provider #'.$provider->id }}</h1>
        <span @class([
            'px-3 py-1.5 rounded text-sm font-medium',
            'bg-amber-100 text-amber-700' => $provider->kyc_status === 'pending',
            'bg-green-100 text-green-700' => $provider->kyc_status === 'approved',
            'bg-red-100 text-red-700' => $provider->kyc_status === 'rejected',
        ])>
            {{ ucfirst($provider->kyc_status) }}
        </span>
    </div>

    @if ($flashMessage)
        <div @class([
            'rounded p-3 mb-4 text-sm',
            'bg-green-50 text-green-700' => $flashType === 'success',
            'bg-red-50 text-red-700' => $flashType === 'error',
        ])>
            {{ $flashMessage }}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="font-semibold mb-2">Details</div>
            <dl class="text-sm space-y-1">
                <div class="flex justify-between"><dt class="text-gray-500">Phone</dt><dd>{{ $provider->user->phone ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Type</dt><dd>{{ ucfirst($provider->provider_type) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Franchise / Zone</dt><dd>{{ $provider->franchise->name ?? '—' }} / {{ $provider->zone->name ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Skills (category IDs)</dt><dd>{{ implode(', ', $provider->skills ?? []) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Applied</dt><dd>{{ $provider->created_at->format('d M Y, h:i A') }}</dd></div>
            </dl>
        </div>

        <div class="bg-white rounded-lg shadow-sm p-4">
            <div class="font-semibold mb-2">Track Record</div>
            <dl class="text-sm space-y-1">
                <div class="flex justify-between"><dt class="text-gray-500">Jobs completed</dt><dd>{{ $provider->jobs_completed }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Rating</dt><dd>{{ number_format($provider->rating_avg, 2) }} / 5</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Currently online</dt><dd>{{ $provider->is_online ? 'Yes' : 'No' }}</dd></div>
            </dl>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
        <div class="font-semibold mb-2">Submitted Documents ({{ $provider->documents->count() }})</div>
        @if ($provider->documents->isEmpty())
            <p class="text-sm text-gray-400">No documents uploaded yet.</p>
        @else
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                @foreach ($provider->documents as $doc)
                    <a href="{{ $doc->file_url }}" target="_blank"
                       class="border rounded p-3 text-center hover:bg-gray-50">
                        <div class="text-xs font-medium mb-1">{{ str_replace('_', ' ', $doc->type) }}</div>
                        <div class="text-xs text-gray-400">{{ $doc->status }}</div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    @if ($provider->kyc_status === 'pending')
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white rounded-lg shadow-sm p-4">
                <div class="font-semibold mb-2 text-green-700">Approve</div>
                <p class="text-sm text-gray-500 mb-3">Provider will be able to go online and receive job offers immediately.</p>
                <button wire:click="approve" class="w-full bg-green-600 text-white rounded py-2 text-sm font-medium hover:bg-green-700">
                    Approve Provider
                </button>
            </div>

            <div class="bg-white rounded-lg shadow-sm p-4">
                <div class="font-semibold mb-2 text-red-700">Reject</div>
                <input type="text" wire:model="rejectionReason" placeholder="Reason for rejection..."
                       class="w-full border rounded px-3 py-2 text-sm mb-3">
                <button wire:click="reject" class="w-full bg-red-600 text-white rounded py-2 text-sm font-medium hover:bg-red-700">
                    Reject Provider
                </button>
            </div>
        </div>
    @endif
</div>
