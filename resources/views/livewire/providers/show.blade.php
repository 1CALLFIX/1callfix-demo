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
                <div class="flex justify-between items-center pt-2 border-t mt-2">
                    <dt class="text-gray-500">Ranking priority</dt>
                    <dd class="flex items-center gap-2">
                        <input type="number" min="0" max="1000" wire:model="priorityInput" class="w-20 border rounded px-2 py-1 text-sm text-right">
                        <button type="button" wire:click="updatePriority" class="text-xs bg-slate-900 text-white px-3 py-1.5 rounded hover:bg-slate-800">Save</button>
                    </dd>
                </div>
                @error('priorityInput') <p class="text-red-600 text-xs text-right">{{ $message }}</p> @enderror
            </dl>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
        <div class="flex items-center justify-between mb-2">
            <div class="font-semibold">Withdrawal eligibility</div>
            <span @class(['px-2 py-0.5 rounded text-xs', 'bg-red-100 text-red-700' => $withdrawalRestricted, 'bg-green-100 text-green-700' => ! $withdrawalRestricted])>
                {{ $withdrawalRestricted ? 'Restricted' : 'Eligible' }}
            </span>
        </div>
        <p class="text-xs text-gray-400">{{ str_replace('_', ' ', $withdrawalReason) }}@if($provider->kyc_deadline_at) &middot; KYC deadline: {{ $provider->kyc_deadline_at->format('d M Y') }}@endif</p>
    </div>

    <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
        <div class="font-semibold mb-2">Submitted Documents ({{ $provider->currentDocuments->count() }})</div>
        @if ($provider->currentDocuments->isEmpty())
            <p class="text-sm text-gray-400">No documents uploaded yet.</p>
        @else
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                @foreach ($provider->currentDocuments as $doc)
                    <a href="{{ route('admin.kyc.documents.provider', $doc->id) }}" target="_blank"
                       class="border rounded p-3 text-center hover:bg-gray-50">
                        <div class="text-xs font-medium mb-1">{{ str_replace('_', ' ', $doc->type) }}</div>
                        <div class="text-xs text-gray-400">{{ $doc->status }}</div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
        <div class="font-semibold mb-2">Verification video</div>
        @if ($provider->latestVerificationVideo)
            <a href="{{ route('admin.kyc.videos.show', $provider->latestVerificationVideo->id) }}" target="_blank" class="text-sm text-blue-600 hover:underline">View submitted video</a>
            <span class="text-xs text-gray-400 ml-2">Status: {{ $provider->latestVerificationVideo->status }}</span>
        @else
            <p class="text-sm text-gray-400">No verification video submitted yet.</p>
        @endif
    </div>

    <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
        <div class="font-semibold mb-2">Recent Reviews (showing last {{ $recentReviews->count() }})</div>
        @if ($recentReviews->isEmpty())
            <p class="text-sm text-gray-400">No reviews yet.</p>
        @else
            <div class="space-y-3">
                @foreach ($recentReviews as $review)
                    <div class="border-t pt-3 first:border-t-0 first:pt-0">
                        <div class="flex items-center justify-between">
                            <div class="text-sm font-medium">{{ $review->customer->name ?? 'Customer #'.$review->customer_id }}</div>
                            <div class="text-amber-500 text-sm">{{ str_repeat('★', $review->rating) }}{{ str_repeat('☆', 5 - $review->rating) }}</div>
                        </div>
                        @if ($review->comment)
                            <p class="text-sm text-gray-600 mt-1">{{ $review->comment }}</p>
                        @endif
                        @if ($review->provider_reply)
                            <p class="text-xs text-gray-400 mt-1 pl-3 border-l-2">Provider reply: {{ $review->provider_reply }}</p>
                        @endif
                        <div class="text-[11px] text-gray-400 mt-1">{{ $review->created_at->format('d M Y') }}</div>
                    </div>
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
