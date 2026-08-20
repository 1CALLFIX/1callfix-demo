<div>
    <h1 class="text-2xl font-bold mb-4">Operations</h1>

    @if ($flashMessage)
        <div @class(['rounded px-4 py-2 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    {{-- System health --}}
    <x-ui.card class="mb-6">
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
    </x-ui.card>

    {{-- Failed jobs --}}
    <x-ui.table class="mb-6">
        <x-slot:header>
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-500 uppercase">Failed jobs ({{ $failedJobsCount }})</h2>
            </div>
        </x-slot:header>
        <x-slot:footer>{{ $failedJobs->links() }}</x-slot:footer>

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
                            <x-ui.button variant="ghost" class="mr-3" wire:click="retryJob('{{ $job->uuid }}')" wire:confirm="Retry this job?">Retry</x-ui.button>
                            <x-ui.button variant="ghost" color="red" wire:click="discardJob('{{ $job->uuid }}')" wire:confirm="Discard this job permanently?">Discard</x-ui.button>
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $canManage ? 5 : 4 }}" class="px-4 py-6 text-center text-gray-400">No failed jobs.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>

    {{-- Notification delivery failures --}}
    <x-ui.table class="mb-6">
        <x-slot:header>
            <h2 class="text-sm font-semibold text-gray-500 uppercase">Recent notification failures ({{ $notificationFailureCount }} total, showing last {{ $notificationFailures->count() }})</h2>
        </x-slot:header>

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
                    <td class="px-4 py-2"><x-ui.badge color="red">{{ $log->channel }}</x-ui.badge></td>
                    <td class="px-4 py-2 font-mono text-xs">{{ class_basename($log->notification_type) }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ $log->event ?? '—' }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ class_basename($log->notifiable_type) }} #{{ $log->notifiable_id }}</td>
                    <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ $log->sent_at?->format('d M Y, h:i A') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No notification delivery failures recorded.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>

    {{-- Reconciliation warnings --}}
    <x-ui.card title="Reconciliation warnings" class="mb-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
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
    </x-ui.card>

    {{-- Reconciliation warnings -- Parcel/Taxi/Property/Marketplace/Rental/Hotel (same tables/checks as the card above, generalized across the six non-Booking Orderable verticals, previously invisible here) --}}
    <x-ui.card title="Reconciliation warnings — Parcel / Taxi / Property / Marketplace / Rental / Hotel" class="mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div>
                <div class="font-medium text-gray-700 mb-2">Paid without captured payment ({{ $reconciliation['order_paid_without_captured_payment']->count() }})</div>
                @forelse ($reconciliation['order_paid_without_captured_payment'] as $order)
                    @php($orderRoute = match (get_class($order)) {
                        \App\Models\ParcelOrder::class => 'admin.parcel-orders.index',
                        \App\Models\TaxiRide::class => 'admin.taxi-rides.index',
                        \App\Models\PropertyReservation::class => 'admin.property-reservations.index',
                        \App\Models\MarketplaceOrder::class => 'admin.marketplace-orders.index',
                        \App\Models\RentalReservation::class => 'admin.rental-reservations.index',
                        \App\Models\HotelReservation::class => 'admin.hotel-reservations.index',
                        default => null,
                    })
                    @php($orderLabel = match (get_class($order)) {
                        \App\Models\ParcelOrder::class => 'Parcel',
                        \App\Models\TaxiRide::class => 'Taxi',
                        \App\Models\PropertyReservation::class => 'Property',
                        \App\Models\MarketplaceOrder::class => 'Marketplace',
                        \App\Models\RentalReservation::class => 'Rental',
                        \App\Models\HotelReservation::class => 'Hotel',
                        default => 'Order',
                    })
                    <div class="text-xs text-gray-500 border-t py-1.5">
                        <a href="{{ $orderRoute ? route($orderRoute) : '#' }}" class="text-blue-600 hover:underline">{{ $orderLabel }} {{ $order->code ?? '#'.$order->id }}</a>
                        — {{ $order->customer->name ?? 'Unknown customer' }}
                    </div>
                @empty
                    <div class="text-xs text-gray-400">None.</div>
                @endforelse
            </div>
            <div>
                <div class="font-medium text-gray-700 mb-2">Completed without commission ({{ $reconciliation['order_completed_without_commission']->count() }})</div>
                @forelse ($reconciliation['order_completed_without_commission'] as $order)
                    @php($orderRoute = match (get_class($order)) {
                        \App\Models\ParcelOrder::class => 'admin.parcel-orders.index',
                        \App\Models\TaxiRide::class => 'admin.taxi-rides.index',
                        \App\Models\PropertyReservation::class => 'admin.property-reservations.index',
                        \App\Models\MarketplaceOrder::class => 'admin.marketplace-orders.index',
                        \App\Models\RentalReservation::class => 'admin.rental-reservations.index',
                        \App\Models\HotelReservation::class => 'admin.hotel-reservations.index',
                        default => null,
                    })
                    @php($orderLabel = match (get_class($order)) {
                        \App\Models\ParcelOrder::class => 'Parcel',
                        \App\Models\TaxiRide::class => 'Taxi',
                        \App\Models\PropertyReservation::class => 'Property',
                        \App\Models\MarketplaceOrder::class => 'Marketplace',
                        \App\Models\RentalReservation::class => 'Rental',
                        \App\Models\HotelReservation::class => 'Hotel',
                        default => 'Order',
                    })
                    <div class="text-xs text-gray-500 border-t py-1.5">
                        <a href="{{ $orderRoute ? route($orderRoute) : '#' }}" class="text-blue-600 hover:underline">{{ $orderLabel }} {{ $order->code ?? '#'.$order->id }}</a>
                        — {{ $order->customer->name ?? 'Unknown customer' }}
                    </div>
                @empty
                    <div class="text-xs text-gray-400">None.</div>
                @endforelse
            </div>
        </div>
    </x-ui.card>

    {{-- Dispatch health --}}
    <x-ui.card title="Dispatch health" class="mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
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
    </x-ui.card>

    {{-- Dispatch health -- Parcel/Taxi/Marketplace (same dispatch_attempts table, polymorphic dispatchable, previously invisible here) --}}
    <x-ui.card title="Dispatch health — Parcel / Taxi / Marketplace" class="mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div>
                <div class="font-medium text-gray-700 mb-2">Stale offers ({{ $dispatchHealth['stale_order_offer_count'] }})</div>
                @forelse ($dispatchHealth['stale_order_offers'] as $attempt)
                    @php($order = $attempt->dispatchable)
                    @php($orderRoute = match (get_class($order)) {
                        \App\Models\ParcelOrder::class => 'admin.parcel-orders.index',
                        \App\Models\TaxiRide::class => 'admin.taxi-rides.index',
                        \App\Models\MarketplaceOrder::class => 'admin.marketplace-orders.index',
                        default => null,
                    })
                    @php($orderLabel = match (get_class($order)) {
                        \App\Models\ParcelOrder::class => 'Parcel',
                        \App\Models\TaxiRide::class => 'Taxi',
                        \App\Models\MarketplaceOrder::class => 'Marketplace',
                        default => 'Order',
                    })
                    <div class="text-xs text-gray-500 border-t py-1.5">
                        <a href="{{ $orderRoute ? route($orderRoute) : '#' }}" class="text-blue-600 hover:underline">{{ $orderLabel }} {{ $order->code ?? '#'.$attempt->dispatchable_id }}</a>
                        — offered to {{ $attempt->notifiable->user->name ?? 'Unknown worker' }} at {{ app(\App\Services\TimezoneResolver::class)->format($attempt->notified_at, $order?->franchise, 'd M, h:i A') }}
                    </div>
                @empty
                    <div class="text-xs text-gray-400">None.</div>
                @endforelse
            </div>
            <div>
                <div class="font-medium text-gray-700 mb-2">Exhausted orders ({{ $dispatchHealth['exhausted_order_count'] }})</div>
                @forelse ($dispatchHealth['exhausted_orders'] as $order)
                    @php($orderRoute = match (get_class($order)) {
                        \App\Models\ParcelOrder::class => 'admin.parcel-orders.index',
                        \App\Models\TaxiRide::class => 'admin.taxi-rides.index',
                        \App\Models\MarketplaceOrder::class => 'admin.marketplace-orders.index',
                        default => null,
                    })
                    @php($orderLabel = match (get_class($order)) {
                        \App\Models\ParcelOrder::class => 'Parcel',
                        \App\Models\TaxiRide::class => 'Taxi',
                        \App\Models\MarketplaceOrder::class => 'Marketplace',
                        default => 'Order',
                    })
                    <div class="text-xs text-gray-500 border-t py-1.5">
                        <a href="{{ $orderRoute ? route($orderRoute) : '#' }}" class="text-blue-600 hover:underline">{{ $orderLabel }} {{ $order->code ?? '#'.$order->id }}</a>
                        — {{ $order->customer->name ?? 'Unknown customer' }}, no workers currently available
                    </div>
                @empty
                    <div class="text-xs text-gray-400">None.</div>
                @endforelse
            </div>
        </div>
    </x-ui.card>

    {{-- Stuck bookings --}}
    <x-ui.table class="mb-6">
        <x-slot:header>
            <h2 class="text-sm font-semibold text-gray-500 uppercase">Stuck bookings ({{ $stuckBookings->count() }})</h2>
        </x-slot:header>

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
    </x-ui.table>

    {{-- Scheduled task runs --}}
    <x-ui.table class="mb-6">
        <x-slot:header>
            <h2 class="text-sm font-semibold text-gray-500 uppercase">Scheduled task runs (last {{ $scheduledTaskRuns->count() }})</h2>
        </x-slot:header>

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
                        <x-ui.badge :color="match($run->status) { 'success' => 'green', 'failure' => 'red', default => 'gray' }">{{ $run->status }}</x-ui.badge>
                    </td>
                    <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ $run->started_at?->format('d M Y, h:i:s A') }}</td>
                    <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ $run->finished_at?->format('d M Y, h:i:s A') }}</td>
                    <td class="px-4 py-2 text-gray-500 text-xs max-w-xs truncate" title="{{ $run->output }}">{{ $run->output ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No scheduled tasks have run yet — this does not mean the scheduler is unhealthy, just that nothing has fired since this tracking was added.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>

    {{-- Payment webhook log --}}
    <x-ui.table>
        <x-slot:header>
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-500 uppercase">Payment webhook log ({{ $webhookLogsCount }})</h2>
                <select wire:model.live="webhookFilter" class="text-xs border rounded px-2 py-1">
                    <option value="all">All</option>
                    <option value="unprocessed">Unprocessed only</option>
                    <option value="processed">Processed only</option>
                </select>
            </div>
        </x-slot:header>
        <x-slot:footer>{{ $webhookLogs->links() }}</x-slot:footer>

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
                        <x-ui.badge :color="match(true) {
                            in_array($log->outcome, ['captured', 'failed', 'already_processed']) => 'green',
                            in_array($log->outcome, ['invalid_signature', 'unmatched_order']) => 'red',
                            $log->outcome === 'unhandled_event' => 'gray',
                            default => 'gray',
                        }">{{ $log->outcome }}</x-ui.badge>
                    </td>
                    <td class="px-4 py-2 text-gray-500 font-mono text-xs">{{ $log->gateway_order_id ?? '—' }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ $log->signature_valid ? 'valid' : 'invalid' }}</td>
                    <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ $log->created_at?->format('d M Y, h:i A') }}</td>
                    @if ($canManage)
                        <td class="px-4 py-2 whitespace-nowrap">
                            @if (in_array($log->outcome, ['unmatched_order', 'unhandled_event', 'invalid_signature']))
                                <x-ui.button variant="ghost" wire:click="reprocessWebhook({{ $log->id }})" wire:confirm="Reprocess this webhook event?">Reprocess</x-ui.button>
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
    </x-ui.table>

    {{-- Activity log -- the audit trail every consequential Operations mutation (and, as of this session, Modules\Manage's activation toggle) already writes to, made visible for the first time. --}}
    <x-ui.table class="mt-6">
        <x-slot:header>
            <h2 class="text-sm font-semibold text-gray-500 uppercase">Activity log ({{ $activityLogsCount }})</h2>
        </x-slot:header>
        <x-slot:footer>{{ $activityLogs->links() }}</x-slot:footer>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <th class="px-4 py-2">When</th>
                <th class="px-4 py-2">Actor</th>
                <th class="px-4 py-2">Subject</th>
                <th class="px-4 py-2">Description</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($activityLogs as $log)
                <tr class="border-t hover:bg-gray-50">
                    <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ $log->created_at?->format('d M Y, h:i A') }}</td>
                    <td class="px-4 py-2">{{ $log->causer->name ?? 'System' }}</td>
                    <td class="px-4 py-2 text-gray-500 font-mono text-xs">{{ class_basename($log->subject_type) }} #{{ $log->subject_id }}</td>
                    <td class="px-4 py-2">{{ $log->description }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No activity logged yet.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
