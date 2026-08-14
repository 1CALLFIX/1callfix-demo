<?php

namespace App\Livewire\Operations;

use App\Contracts\PushAdapter;
use App\Contracts\SmsAdapter;
use App\Models\NotificationLog;
use App\Models\Setting;
use App\Notifications\Adapters\LogPushAdapter;
use App\Notifications\Adapters\LogSmsAdapter;
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
 */
class Health extends Component
{
    use WithPagination;

    public string $flashMessage = '';
    public string $flashType = 'success';

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
        $this->flashType = 'success';
        $this->flashMessage = "Job {$uuid} discarded.";
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

        return view('livewire.operations.health', [
            'failedJobs' => $failedJobs,
            'failedJobsCount' => DB::table('failed_jobs')->count(),
            'notificationFailures' => $notificationFailures,
            'notificationFailureCount' => NotificationLog::where('status', 'failed')->count(),
            'checks' => $this->healthChecks(),
            'canManage' => $this->canManage(),
        ])->layout('layouts.admin', ['title' => 'Operations']);
    }
}
