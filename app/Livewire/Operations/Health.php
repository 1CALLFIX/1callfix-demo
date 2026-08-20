<?php

namespace App\Livewire\Operations;

use App\Contracts\PushAdapter;
use App\Contracts\SmsAdapter;
use App\Models\ActivityLog;
use App\Models\NotificationLog;
use App\Models\PaymentWebhookLog;
use App\Models\ScheduledTaskRun;
use App\Models\Setting;
use App\Notifications\Adapters\LogPushAdapter;
use App\Notifications\Adapters\LogSmsAdapter;
use App\Services\ActivityLogger;
use App\Services\Operations\DispatchHealthService;
use App\Services\Operations\ReconciliationService;
use App\Services\Operations\StuckBookingService;
use App\Services\Payments\RazorpayWebhookHandler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The "Operations / Troubleshoot" screen a full admin-panel forensic audit
 * this session found completely absent -- no failed-job visibility, no
 * notification-failure visibility, no queue/DB/storage health indicators
 * anywhere, despite the tooling to surface all of it already existing:
 * Laravel's own failed_jobs table (queue:retry/queue:forget), and
 * notification_logs (AppServiceProvider's own NotificationSent/
 * NotificationFailed listeners, already populated in production, never
 * displayed anywhere).
 *
 * Deliberately scoped to what's REAL and inspectable without inventing
 * anything: no Sentry/Flare/Telescope-style exception log exists in this
 * codebase (composer.json has neither installed), so "recent exceptions" is
 * NOT included here -- that would require a genuinely new capability
 * (either installing Telescope or building a dedicated exception log),
 * which is a separate decision, not silently faked as data by this screen.
 *
 * Extended (mission Phase 10 -- Operations expansion) with the four pieces
 * the original screen didn't cover: reconciliation warnings, dispatch
 * health, stuck bookings, scheduled-task run history, and a payment
 * webhook log browser with a reprocess action. Every mutating action here
 * (retry/discard/reprocess) is now recorded to ActivityLog -- the real,
 * previously-unused audit trail this session wired in specifically for
 * these Operations mutations.
 */
class Health extends Component
{
    use WithPagination;

    public string $flashMessage = '';
    public string $flashType = 'success';

    /** @var string 'all'|'unprocessed'|'processed' */
    public string $webhookFilter = 'all';

    /** operations.view was seeded (2026_08_14_001000) specifically for this screen. */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('operations.view'), 403, 'You do not have permission to view operations.');
    }

    private function canManage(): bool
    {
        return auth()->user()->hasPermissionAnywhere('operations.manage');
    }

    /**
     * Re-queues the job via Laravel's own queue:retry (which itself removes
     * the failed_jobs row once re-pushed) -- not a hand-rolled re-dispatch,
     * so it goes through exactly the same retry mechanics Laravel documents
     * and this app's Supervisor-managed worker already expects.
     */
    public function retryJob(string $uuid): void
    {
        if (! $this->canManage()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage operations.';
            return;
        }

        Artisan::call('queue:retry', ['id' => [$uuid]]);
        ActivityLogger::log(auth()->user(), 'failed_job', 0, "Retried failed job {$uuid}", ['uuid' => $uuid]);
        $this->flashType = 'success';
        $this->flashMessage = "Job {$uuid} re-queued.";
    }

    /** Discards a failed job permanently — for a job that's confirmed unfixable/obsolete, not meant to be retried. */
    public function discardJob(string $uuid): void
    {
        if (! $this->canManage()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage operations.';
            return;
        }

        DB::table('failed_jobs')->where('uuid', $uuid)->delete();
        ActivityLogger::log(auth()->user(), 'failed_job', 0, "Discarded failed job {$uuid}", ['uuid' => $uuid]);
        $this->flashType = 'success';
        $this->flashMessage = "Job {$uuid} discarded.";
    }

    /**
     * Re-runs a previously logged Razorpay webhook receipt through the SAME
     * idempotent RazorpayWebhookHandler the live endpoint uses (extracted
     * verbatim for exactly this purpose) — for events that arrived before
     * their local Payment row existed (`unmatched_order`) or that weren't
     * acted on (`unhandled_event`), or a signature that's since been
     * re-verified as trustworthy (`invalid_signature`, e.g. after rotating
     * the wrong webhook secret back). Does nothing to rows already
     * terminally processed (captured/failed/already_processed) — those
     * aren't offered a reprocess button in the view at all.
     */
    public function reprocessWebhook(int $id): void
    {
        if (! $this->canManage()) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to manage operations.';
            return;
        }

        $log = PaymentWebhookLog::findOrFail($id);
        $handler = app(RazorpayWebhookHandler::class);

        $result = match ($log->event) {
            'payment.captured' => $handler->handleCaptured($log->payload),
            'payment.failed' => $handler->handleFailed($log->payload),
            default => ['outcome' => 'unhandled_event', 'payment' => null],
        };

        $log->update([
            'payment_id' => $result['payment']?->id ?? $log->payment_id,
            'processed' => in_array($result['outcome'], ['captured', 'failed', 'already_processed'], true),
            'outcome' => $result['outcome'],
        ]);

        ActivityLogger::logModel(auth()->user(), $log, "Reprocessed webhook log #{$log->id}", ['new_outcome' => $result['outcome']]);

        $this->flashType = in_array($result['outcome'], ['captured', 'failed'], true) ? 'success' : 'error';
        $this->flashMessage = "Webhook log #{$log->id} reprocessed — outcome: {$result['outcome']}.";
    }

    private function healthChecks(): array
    {
        $dbOk = true;
        $dbError = null;
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $dbOk = false;
            $dbError = $e->getMessage();
        }

        return [
            ['label' => 'Database connection', 'ok' => $dbOk, 'detail' => $dbOk ? config('database.default') : $dbError],
            ['label' => 'Queue driver', 'ok' => true, 'detail' => config('queue.default')],
            ['label' => 'Cache driver', 'ok' => true, 'detail' => config('cache.default')],
            ['label' => 'Mail driver', 'ok' => true, 'detail' => config('mail.default')],
            [
                'label' => 'SMS provider',
                'ok' => ! (app(SmsAdapter::class) instanceof LogSmsAdapter),
                'detail' => app(SmsAdapter::class) instanceof LogSmsAdapter
                    ? 'LogSmsAdapter (dev-only — writes OTP codes to the server log, not delivered to real phones)'
                    : get_class(app(SmsAdapter::class)),
            ],
            [
                'label' => 'Push provider',
                'ok' => ! (app(PushAdapter::class) instanceof LogPushAdapter),
                'detail' => app(PushAdapter::class) instanceof LogPushAdapter
                    ? 'LogPushAdapter (dev-only — logs push payloads, not delivered to real devices)'
                    : get_class(app(PushAdapter::class)),
            ],
            ['label' => 'Storage writable', 'ok' => is_writable(storage_path()), 'detail' => storage_path()],
            [
                'label' => 'Maintenance mode',
                'ok' => Setting::get('system.maintenance_mode', '0') !== '1',
                'detail' => Setting::get('system.maintenance_mode', '0') === '1' ? 'ON — booking operations are currently blocked' : 'off',
            ],
        ];
    }

    public function render()
    {
        $failedJobs = DB::table('failed_jobs')->orderByDesc('failed_at')->paginate(15, ['*'], 'failedJobsPage');

        $notificationFailures = NotificationLog::where('status', 'failed')
            ->latest('sent_at')
            ->limit(50)
            ->get();

        $webhookLogsQuery = PaymentWebhookLog::query()->latest('created_at');
        if ($this->webhookFilter === 'unprocessed') {
            $webhookLogsQuery->where('processed', false);
        } elseif ($this->webhookFilter === 'processed') {
            $webhookLogsQuery->where('processed', true);
        }
        $webhookLogs = $webhookLogsQuery->paginate(15, ['*'], 'webhookLogsPage');

        $scheduledTaskRuns = ScheduledTaskRun::latest('created_at')->limit(30)->get();

        // ActivityLog has existed since Phase P3 and was wired into every
        // Operations mutation (this session added Modules\Manage::toggle())
        // but was write-only -- no admin screen anywhere displayed it, so
        // the audit trail nobody could see was barely more useful than no
        // audit trail at all. Global (not franchise-scoped), same as the
        // rest of this screen -- operations.view/manage are already
        // super-admin-only-by-default permissions, and a causer's own
        // actions can span multiple franchises' data, so there's no single
        // owning franchise to scope a log row to.
        $activityLogs = ActivityLog::with('causer')->latest('id')->paginate(15, ['*'], 'activityLogPage');

        return view('livewire.operations.health', [
            'failedJobs' => $failedJobs,
            'failedJobsCount' => DB::table('failed_jobs')->count(),
            'notificationFailures' => $notificationFailures,
            'notificationFailureCount' => NotificationLog::where('status', 'failed')->count(),
            'checks' => $this->healthChecks(),
            'canManage' => $this->canManage(),
            'reconciliation' => app(ReconciliationService::class)->detect(auth()->user()),
            'dispatchHealth' => app(DispatchHealthService::class)->stats(auth()->user()),
            'stuckBookings' => app(StuckBookingService::class)->detect(auth()->user()),
            'scheduledTaskRuns' => $scheduledTaskRuns,
            'webhookLogs' => $webhookLogs,
            'webhookLogsCount' => PaymentWebhookLog::count(),
            'activityLogs' => $activityLogs,
            'activityLogsCount' => ActivityLog::count(),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('layouts.admin', ['title' => 'Operations']);
    }
}
