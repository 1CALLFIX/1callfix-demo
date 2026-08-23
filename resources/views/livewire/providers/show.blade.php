<div>
    <a href="{{ route('admin.providers.index') }}" class="text-sm text-blue-600 hover:underline">&larr; Back to Providers</a>

    <div class="flex items-center justify-between mt-2 mb-4">
        <h1 class="text-2xl font-bold">{{ $provider->user->name ?? 'Provider #'.$provider->id }}</h1>
        <x-ui.status-badge type="provider_kyc" :status="$provider->kyc_status" size="lg" />
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
        <x-ui.card>
            <div class="font-semibold mb-2">Details</div>
            <dl class="text-sm space-y-1">
                <div class="flex justify-between"><dt class="text-gray-500">Phone</dt><dd>{{ $provider->user->phone ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Type</dt><dd>{{ ucfirst($provider->provider_type) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Franchise / Zone</dt><dd>{{ $provider->franchise->name ?? '—' }} / {{ $provider->zone->name ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Applied</dt><dd>{{ app(\App\Services\TimezoneResolver::class)->format($provider->created_at, $provider->franchise, 'd M Y, h:i A') }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card>
            <div class="font-semibold mb-2">Track Record</div>
            <dl class="text-sm space-y-1">
                <div class="flex justify-between"><dt class="text-gray-500">Jobs completed</dt><dd>{{ $provider->jobs_completed }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Rating</dt><dd>{{ number_format($provider->rating_avg, 2) }} / 5</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Currently online</dt><dd>{{ $provider->is_online ? 'Yes' : 'No' }}</dd></div>
                <div class="flex justify-between items-center pt-2 border-t mt-2">
                    <dt class="text-gray-500">Ranking priority</dt>
                    <dd class="flex items-center gap-2">
                        <input type="number" min="0" max="1000" wire:model="priorityInput" class="w-20 border rounded px-2 py-1 text-sm text-right">
                        <x-ui.button size="sm" wire:click="updatePriority">Save</x-ui.button>
                    </dd>
                </div>
                @error('priorityInput') <p class="text-red-600 text-xs text-right">{{ $message }}</p> @enderror
            </dl>
        </x-ui.card>
    </div>

    {{-- Assign this provider to categories/services — writes `skills`, the
         array DispatchService::hasSkill() checks a provider against for
         real dispatch eligibility. Until now this was display-only
         ("Skills (category IDs)" above) with no admin action anywhere
         that could ever set it. --}}
    <x-ui.card class="mb-6">
        <div class="font-semibold mb-1">Assigned Categories</div>
        <p class="text-xs text-gray-400 mb-3">Which service categories this provider is eligible to receive job offers for. A service under an unchecked category won't be dispatched to them, no matter its subcategory or price.</p>

        @if ($categories->isEmpty())
            <p class="text-sm text-gray-400">No active categories exist yet — <a href="{{ route('admin.categories.index') }}" class="text-blue-600 hover:underline">create one first</a>.</p>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-2 mb-3">
                @foreach ($categories->groupBy('module') as $moduleSlug => $group)
                    <div class="col-span-full text-xs font-semibold text-gray-400 uppercase tracking-wide mt-2 first:mt-0">{{ \App\Support\Modules::label($moduleSlug) }}</div>
                    @foreach ($group as $cat)
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="skillsInput" value="{{ $cat->id }}" class="rounded">
                            {{ $cat->name }}
                        </label>
                    @endforeach
                @endforeach
            </div>
            <x-ui.button size="sm" wire:click="updateSkills">Save Assigned Categories</x-ui.button>
        @endif
    </x-ui.card>

    <x-ui.card class="mb-6">
        <div class="flex items-center justify-between mb-2">
            <div class="font-semibold">Withdrawal eligibility</div>
            <x-ui.badge :color="$withdrawalRestricted ? 'red' : 'green'">
                {{ $withdrawalRestricted ? 'Restricted' : 'Eligible' }}
            </x-ui.badge>
        </div>
        <p class="text-xs text-gray-400">{{ str_replace('_', ' ', $withdrawalReason) }}@if($provider->kyc_deadline_at) &middot; KYC deadline: {{ app(\App\Services\TimezoneResolver::class)->format($provider->kyc_deadline_at, $provider->franchise, 'd M Y') }}@endif</p>
    </x-ui.card>

    <x-ui.card class="mb-6">
        <div class="font-semibold mb-2">Submitted Documents ({{ $provider->currentDocuments->count() }})</div>
        @if ($provider->currentDocuments->isEmpty())
            <x-ui.empty-state icon="document-text" title="No documents uploaded yet" />
        @else
            {{-- Admin Polish + AI session, Part 1 item 3 — "document preview
                 inline where feasible". mime_type is a real ProviderDocument
                 column (set at upload); an image renders as an actual inline
                 thumbnail instead of a bare filename link, so a reviewer can
                 tell documents apart at a glance without opening each one.
                 Anything else (PDF, etc.) keeps the icon+link card — the
                 same private, authorization-checked streaming route either
                 way (KycDocumentController::providerDocument()), just an
                 <img> tag pointed at it instead of a bare <a>. --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                @foreach ($provider->currentDocuments as $doc)
                    <a href="{{ route('admin.kyc.documents.provider', $doc->id) }}" target="_blank"
                       class="border rounded overflow-hidden text-center hover:bg-gray-50 hover:border-gray-300 transition block">
                        @if (str_starts_with($doc->mime_type ?? '', 'image/'))
                            <img src="{{ route('admin.kyc.documents.provider', $doc->id) }}"
                                 alt="{{ str_replace('_', ' ', $doc->type) }} document preview"
                                 class="w-full h-24 object-cover bg-gray-100" loading="lazy">
                        @else
                            <div class="w-full h-24 bg-gray-50 flex items-center justify-center text-gray-300">
                                <x-icon name="document-text" class="w-8 h-8" />
                            </div>
                        @endif
                        <div class="p-2">
                            <div class="text-xs font-medium mb-1">{{ str_replace('_', ' ', $doc->type) }}</div>
                            <x-ui.status-badge type="document" :status="$doc->status" />
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </x-ui.card>

    <x-ui.card class="mb-6">
        <div class="font-semibold mb-2">Verification video</div>
        @if ($provider->latestVerificationVideo)
            <a href="{{ route('admin.kyc.videos.show', $provider->latestVerificationVideo->id) }}" target="_blank" class="text-sm text-blue-600 hover:underline">View submitted video</a>
            <span class="text-xs text-gray-400 ml-2">Status: {{ $provider->latestVerificationVideo->status }}</span>
        @else
            <p class="text-sm text-gray-400">No verification video submitted yet.</p>
        @endif
    </x-ui.card>

    <x-ui.card class="mb-6">
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
                        <div class="text-[11px] text-gray-400 mt-1">{{ app(\App\Services\TimezoneResolver::class)->format($review->created_at, $provider->franchise, 'd M Y') }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-ui.card>

    @if ($provider->kyc_status === 'pending')
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <x-ui.card>
                <div class="font-semibold mb-2 text-green-700">Approve</div>
                <p class="text-sm text-gray-500 mb-3">Provider will be able to go online and receive job offers immediately.</p>
                <x-ui.button variant="success" size="lg" class="w-full" wire:click="approve">
                    Approve Provider
                </x-ui.button>
            </x-ui.card>

            <x-ui.card>
                <div class="font-semibold mb-2 text-red-700">Reject</div>
                <input type="text" wire:model="rejectionReason" placeholder="Reason for rejection..."
                       class="w-full border rounded px-3 py-2 text-sm mb-3">
                <x-ui.button variant="danger" size="lg" class="w-full" wire:click="reject">
                    Reject Provider
                </x-ui.button>
            </x-ui.card>
        </div>
    @endif
</div>
