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
    <div class="bg-white rounded-lg shadow-sm overflow-hidden mb-6">
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

    {{-- Reconciliation warnings --}}
    <div class="bg-white rounded-lg shadow-sm overflow-hidden mb-6">
        <div class="px-4 py-3 border-b">
            <h2 class="text-sm font-semibold text-gray-500 uppercase">Reconciliation warnings</h2>
        </div>
        <div class="p-4 grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div>
                <div class="font-medium text-gray-700 mb-2">Paid bookings without captured payment ({{ $reconciliation['paid_bookings_without_captured_payment']->count() }})</div>
                @forelse ($reconciliation['paid_bookings_without_captured_payment'] as $booking)
                    <div class="text-xs text-gray-500 border-t py-1.5">
                        <a href="{{ route('admin.bookings.show', $booking->id) }}" class="text-blue-600 hover:underline">Booking #{{ $booking->id }}</a>
                        — {{ $booking->customer->name ?? 'Unknown customer' }}
                    </div>
                @empty
                    <div class="text-xs text-gray-400">None.</div>
                @endforelse
            </div>
            <div>
                <div class="font-medium text-gray-700 mb-2">Completed bookings without commission ({{ $reconciliation['completed_bookings_without_commission']->count() }})</div>
                @forelse ($reconciliation['completed_bookings_without_commission'] as $booking)
                    <div class="text-xs text-gray-500 border-t py-1.5">
                        <a href="{{ route('admin.bookings.show', $booking->id) }}" class="text-blue-600 hover:underline">Booking #{{ $booking->id }}</a>
                        — {{ $booking->provider->user->name ?? 'Unknown provider' }}
                    </div>
                @empty
                    <div class="text-xs text-gray-400">None.</div>
                @endforelse
            </div>
            <div>
                <div class="font-medium text-gray-700 mb-2">Wallet balance mismatches ({{ $reconciliation['wallet_balance_mismatches']->count() }})</div>
                @forelse ($reconciliation['wallet_balance_mismatches'] as $row)
                    <div class="text-xs text-gray-500 border-t py-1.5">
                        {{ $row['wallet']->user->name ?? 'Unknown user' }}
                        — stored: {{ number_format($row['stored_balance'], 2) }}, ledger: {{ number_format($row['ledger_sum'], 2) }}
                    </div>
                @empty
                    <div class="text-xs text-gray-400">None.</div>
                @endforelse
            </div>
            <div>
                <div class="font-medium text-gray-700 mb-2">Wallet top-ups captured without credit ({{ $reconciliation['wallet_topups_captured_without_credit']->count() }})</div>
                @forelse ($reconciliation['wallet_topups_captured_without_credit'] as $payment)
                    <div class="text-xs text-gray-500 border-t py-1.5">
                        Payment #{{ $payment->id }} — {{ $payment->user->name ?? 'Unknown user' }}, {{ $currencySymbol }}{{ number_format($payment->amount, 2) }}
                    </div>
                @empty
                    <div class="text-xs text-gray-400">None.</div>
                @endforelse
            </div>
            <div>
                <div class="font-medium text-gray-700 mb-2">Negative loyalty balances ({{ $reconciliation['negative_loyalty_balances']->count() }})</div>
                @forelse ($reconciliation['negative_loyalty_balances'] as $row)
                    <div class="text-xs text-gray-500 border-t py-1.5">
                        {{ $row->user->name ?? 'User #'.$row->user_id }} — {{ $row->balance }} points
                    </div>
                @empty
                    <div class="text-xs text-gray-400">None.</div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Dispatch health --}}
    <div class="bg-white rounded-lg shadow-sm overflow-hidden mb-6">
        <div class="px-4 py-3 border-b">
            <h2 class="text-sm font-semibold text-gray-500 uppercase">Dispatch health</h2>
        </div>
        <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div>
                <div class="font-medium text-gray-700 mb-2">Stale offers ({{ $dispatchHealth['stale_offer_count'] }})</div>
                @forelse ($dispatchHealth['stale_offers'] as $attempt)
                    <div class="text-xs text-gray-500 border-t py-1.5">
                        <a href="{{ route('admin.bookings.show', $attempt->booking_id) }}" class="text-blue-600 hover:underline">Booking #{{ $attempt->booking_id }}</a>
                        — offered to {{ $attempt->provider->user->name ?? 'Unknown provider' }} at {{ app(\App\Services\TimezoneResolver::class)->format($attempt->notified_at, $attempt->booking?->franchise, 'd M, h:i A') }}
                    </div>
                @empty
                    <div class="text-xs text-gray-400">None.</div>
                @endforelse
            </div>
            <div>
                <div class="font-medium text-gray-700 mb-2">Exhausted bookings ({{ $dispatchHealth['exhausted_booking_count'] }})</div>
                @forelse ($dispatchHealth['exhausted_bookings'] as $booking)
                    <div class="text-xs text-gray-500 border-t py-1.5">
                        <a href="{{ route('admin.bookings.show', $booking->id) }}" class="text-blue-600 hover:underline">Booking #{{ $booking->id }}</a>
                        — {{ $booking->customer->name ?? 'Unknown customer' }}, no providers currently available
                    </div>
                @empty
                    <div class="text-xs text-gray-400">None.</div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Stuck bookings --}}
    <div class="bg-white rounded-lg shadow-sm overflow-hidden mb-6">
        <div class="px-4 py-3 border-b">
            <h2 class="text-sm font-semibold text-gray-500 uppercase">Stuck bookings ({{ $stuckBookings->count() }})</h2>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Booking</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Stuck since</th>
                    <th class="px-4 py-2">Minutes stuck</th>
                    <th class="px-4 py-2">Threshold</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($stuckBookings as $row)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2"><a href="{{ route('admin.bookings.show', $row['booking']->id) }}" class="text-blue-600 hover:underline">#{{ $row['booking']->id }}</a></td>
                        <td class="px-4 py-2 text-gray-500">{{ $row['status'] }}</td>
                        <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ app(\App\Services\TimezoneResolver::class)->format($row['stuck_since'], $row['booking']->franchise, 'd M Y, h:i A') }}</td>
                        <td class="px-4 py-2 text-red-700 font-medium">{{ $row['minutes_stuck'] }}m</td>
                        <td class="px-4 py-2 text-gray-400">{{ $row['threshold_minutes'] }}m</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No stuck bookings.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Scheduled task runs --}}
    <div class="bg-white rounded-lg shadow-sm overflow-hidden mb-6">
        <div class="px-4 py-3 border-b">
            <h2 class="text-sm font-semibold text-gray-500 uppercase">Scheduled task runs (last {{ $scheduledTaskRuns->count() }})</h2>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Command</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Started</th>
                    <th class="px-4 py-2">Finished</th>
                    <th class="px-4 py-2">Output</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($scheduledTaskRuns as $run)
                    <tr class="border-t hover:bg-gray-50 align-top">
                        <td class="px-4 py-2 font-mono text-xs">{{ $run->command }}</td>
                        <td class="px-4 py-2">
                            <span @class(['px-2 py-0.5 rounded text-xs', 'bg-green-100 text-green-700' => $run->status === 'success', 'bg-red-100 text-red-700' => $run->status === 'failure'])>{{ $run->status }}</span>
                        </td>
                        <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ $run->started_at?->format('d M Y, h:i:s A') }}</td>
                        <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ $run->finished_at?->format('d M Y, h:i:s A') }}</td>
                        <td class="px-4 py-2 text-gray-500 text-xs max-w-xs truncate" title="{{ $run->output }}">{{ $run->output ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No scheduled tasks have run yet — this does not mean the scheduler is unhealthy, just that nothing has fired since this tracking was added.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Payment webhook log --}}
    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-500 uppercase">Payment webhook log ({{ $webhookLogsCount }})</h2>
            <select wire:model.live="webhookFilter" class="text-xs border rounded px-2 py-1">
                <option value="all">All</option>
                <option value="unprocessed">Unprocessed only</option>
                <option value="processed">Processed only</option>
            </select>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Event</th>
                    <th class="px-4 py-2">Outcome</th>
                    <th class="px-4 py-2">Order ID</th>
                    <th class="px-4 py-2">Signature</th>
                    <th class="px-4 py-2">When</th>
                    @if ($canManage)
                        <th class="px-4 py-2">Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($webhookLogs as $log)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2 font-mono text-xs">{{ $log->event ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <span @class([
                                'px-2 py-0.5 rounded text-xs',
                                'bg-green-100 text-green-700' => in_array($log->outcome, ['captured', 'failed', 'already_processed']),
                                'bg-red-100 text-red-700' => in_array($log->outcome, ['invalid_signature', 'unmatched_order']),
                                'bg-gray-100 text-gray-600' => $log->outcome === 'unhandled_event',
                            ])>{{ $log->outcome }}</span>
                        </td>
                        <td class="px-4 py-2 text-gray-500 font-mono text-xs">{{ $log->gateway_order_id ?? '—' }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $log->signature_valid ? 'valid' : 'invalid' }}</td>
                        <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ $log->created_at?->format('d M Y, h:i A') }}</td>
                        @if ($canManage)
                            <td class="px-4 py-2 whitespace-nowrap">
                                @if (in_array($log->outcome, ['unmatched_order', 'unhandled_event', 'invalid_signature']))
                                    <button type="button" wire:click="reprocessWebhook({{ $log->id }})" wire:confirm="Reprocess this webhook event?" class="text-blue-600 hover:underline text-xs">Reprocess</button>
                                @else
                                    <span class="text-gray-300 text-xs">—</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canManage ? 6 : 5 }}" class="px-4 py-6 text-center text-gray-400">No webhook receipts logged.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t">{{ $webhookLogs->links() }}</div>
    </div>
</div>
