<div>
    <h1 class="text-2xl font-bold mb-4">Operations</h1>

    @if ($flashMessage)
        <div @class(['rounded px-4 py-2 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    {{-- System health --}}
    <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
        <h2 class="text-sm font-semibold text-gray-500 uppercase mb-3">System health</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach ($checks as $check)
                <div class="border rounded p-3">
                    <div class="flex items-center gap-2 mb-1">
                        <span @class(['w-2.5 h-2.5 rounded-full shrink-0', 'bg-green-500' => $check['ok'], 'bg-red-500' => ! $check['ok']])></span>
                        <span class="text-xs font-medium text-gray-700">{{ $check['label'] }}</span>
                    </div>
                    <div class="text-xs text-gray-400 break-words">{{ $check['detail'] }}</div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Failed jobs --}}
    <div class="bg-white rounded-lg shadow-sm overflow-hidden mb-6">
        <div class="px-4 py-3 border-b flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-500 uppercase">Failed jobs ({{ $failedJobsCount }})</h2>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Queue</th>
                    <th class="px-4 py-2">Job</th>
                    <th class="px-4 py-2">Exception</th>
                    <th class="px-4 py-2">Failed at</th>
                    @if ($canManage)
                        <th class="px-4 py-2">Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($failedJobs as $job)
                    @php
                        $payload = json_decode($job->payload, true);
                        $jobClass = $payload['displayName'] ?? ($payload['data']['commandName'] ?? 'Unknown job');
                        $exceptionFirstLine = strtok($job->exception, "\n");
                    @endphp
                    <tr class="border-t hover:bg-gray-50 align-top">
                        <td class="px-4 py-2 text-gray-500">{{ $job->queue }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ $jobClass }}</td>
                        <td class="px-4 py-2 text-red-700 text-xs max-w-md truncate" title="{{ $job->exception }}">{{ $exceptionFirstLine }}</td>
                        <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($job->failed_at)->format('d M Y, h:i A') }}</td>
                        @if ($canManage)
                            <td class="px-4 py-2 whitespace-nowrap">
                                <button type="button" wire:click="retryJob('{{ $job->uuid }}')" wire:confirm="Retry this job?" class="text-blue-600 hover:underline text-xs mr-3">Retry</button>
                                <button type="button" wire:click="discardJob('{{ $job->uuid }}')" wire:confirm="Discard this job permanently?" class="text-red-600 hover:underline text-xs">Discard</button>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canManage ? 5 : 4 }}" class="px-4 py-6 text-center text-gray-400">No failed jobs.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t">{{ $failedJobs->links() }}</div>
    </div>

    {{-- Notification delivery failures --}}
    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b">
            <h2 class="text-sm font-semibold text-gray-500 uppercase">Recent notification failures ({{ $notificationFailureCount }} total, showing last {{ $notificationFailures->count() }})</h2>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Channel</th>
                    <th class="px-4 py-2">Notification</th>
                    <th class="px-4 py-2">Event</th>
                    <th class="px-4 py-2">Recipient</th>
                    <th class="px-4 py-2">When</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($notificationFailures as $log)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs bg-red-100 text-red-700">{{ $log->channel }}</span></td>
                        <td class="px-4 py-2 font-mono text-xs">{{ class_basename($log->notification_type) }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $log->event ?? '—' }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ class_basename($log->notifiable_type) }} #{{ $log->notifiable_id }}</td>
                        <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ $log->sent_at?->format('d M Y, h:i A') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No notification delivery failures recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
