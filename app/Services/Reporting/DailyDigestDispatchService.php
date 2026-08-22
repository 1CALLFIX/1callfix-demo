<?php

namespace App\Services\Reporting;

use App\Contracts\WhatsAppAdapter;
use App\Mail\DailyDigestMail;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sidebar Reorganization + Daily Digest session — orchestrates the
 * `digest:send-daily` run: finds recipients, builds each one's SCOPED
 * payload via DailyDigestService, sends the real Mailable, and best-effort
 * sends a short WhatsApp summary. One recipient's failure (bad email, mail
 * driver hiccup, WhatsApp adapter throwing) never aborts the rest of the
 * run — same "don't let one bad row take down the whole batch" discipline
 * as every other batch job in this codebase (e.g. KycReminderService).
 */
class DailyDigestDispatchService
{
    /** Same Setting key the Settings "Notifications" tab writes — see Livewire\Settings\Manage::saveDigest(). Value is "HH:mm", interpreted in IST regardless of APP_TIMEZONE (see routes/console.php). */
    public const SEND_TIME_KEY = 'digest.send_time_local';

    public const LAST_SENT_KEY = 'digest.last_sent_date';

    public function __construct(
        private DailyDigestService $digest,
        private WhatsAppAdapter $whatsapp,
    ) {
    }

    /**
     * The actual scheduled entry point (see routes/console.php). Runs
     * frequently (every 15 minutes) rather than at one exact cron minute —
     * same idiom as this codebase's other Setting-independent schedule
     * entries (campaigns:dispatch-due everyMinute(), etc.) — because the
     * send time itself is admin-configurable via Settings and Laravel's
     * Schedule::command()->dailyAt() argument is resolved once, at
     * routes/console.php LOAD time, not re-read per run. Reading the
     * configured time here instead (inside the command's own execution,
     * long after migrations have run) avoids the alternative of calling
     * Setting::get() — a real DB query — at console-route-registration
     * time, which runs on EVERY artisan invocation including `artisan
     * migrate` on a brand-new database before the settings table exists.
     *
     * Idempotent per calendar day via digest.last_sent_date — a missed or
     * re-run schedule:run within the same day never double-sends.
     *
     * @return array{mailed: int, mail_failed: int, whatsapp_sent: int, whatsapp_failed: int, recipients: int, skipped: bool}
     */
    public function sendIfDue(): array
    {
        $nowIst = now('Asia/Kolkata');
        $today = $nowIst->toDateString();

        if (Setting::get(self::LAST_SENT_KEY, '') === $today) {
            return $this->skippedResult();
        }

        $configuredTime = Setting::get(self::SEND_TIME_KEY, '08:00');
        $target = Carbon::parse($today.' '.$configuredTime, 'Asia/Kolkata');

        if ($nowIst->lt($target)) {
            return $this->skippedResult();
        }

        $result = $this->sendToday();
        Setting::set(self::LAST_SENT_KEY, $today);

        return $result + ['skipped' => false];
    }

    private function skippedResult(): array
    {
        return ['mailed' => 0, 'mail_failed' => 0, 'whatsapp_sent' => 0, 'whatsapp_failed' => 0, 'recipients' => 0, 'skipped' => true];
    }

    /**
     * Unconditional send to every eligible recipient RIGHT NOW — the real
     * work sendIfDue() gates by time/idempotency above, and also what an
     * admin-triggered "send a test digest now" action would call directly.
     *
     * @return array{mailed: int, mail_failed: int, whatsapp_sent: int, whatsapp_failed: int, recipients: int}
     */
    public function sendToday(): array
    {
        $recipients = $this->recipients();
        $forDate = now()->toDateString();
        $whatsappEnabled = (bool) (int) Setting::get('digest.whatsapp_enabled', '0');

        $result = ['mailed' => 0, 'mail_failed' => 0, 'whatsapp_sent' => 0, 'whatsapp_failed' => 0, 'recipients' => $recipients->count()];

        foreach ($recipients as $recipient) {
            // Building the payload (real queries, scoped per recipient) AND
            // sending the mail are both inside this one try/catch — a
            // payload-building failure for one recipient must not abort
            // the loop any more than a Mail transport failure would.
            try {
                $payload = $this->digest->forUser($recipient);
                Mail::to($recipient->email)->send(new DailyDigestMail($recipient, $payload, $forDate));
                $result['mailed']++;
            } catch (\Throwable $e) {
                $result['mail_failed']++;
                report($e);
                Log::warning("Daily Digest mail failed for user #{$recipient->id}: {$e->getMessage()}");

                continue; // no $payload to build a WhatsApp summary from either
            }

            if (! $whatsappEnabled || empty($recipient->phone)) {
                continue;
            }

            try {
                $this->whatsapp->send($recipient->phone, $this->whatsappSummary($recipient, $payload, $forDate));
                $result['whatsapp_sent']++;
            } catch (\Throwable $e) {
                // Best-effort per the mission spec — a WhatsApp failure never
                // affects the mail result above, and never aborts the loop.
                $result['whatsapp_failed']++;
                report($e);
                Log::warning("Daily Digest WhatsApp send failed for user #{$recipient->id}: {$e->getMessage()}");
            }
        }

        return $result;
    }

    /**
     * Super Admins (users.role = 'super_admin', which bypasses
     * role_assignments entirely — see AuthorizationService::canAnywhere())
     * plus every user holding dashboard.view via a real role_assignment
     * (Franchise-scoped admins included) — deduplicated, and only those
     * with a real email address, since Mail delivery is the required
     * channel and a phone-only account has nothing to receive it at.
     */
    private function recipients(): Collection
    {
        $superAdmins = User::where('role', 'super_admin');

        $granted = User::whereHas('roleAssignments.role.permissions', fn ($q) => $q->where('slug', 'dashboard.view'));

        return $superAdmins->union($granted)->get()
            ->unique('id')
            ->filter(fn (User $u) => filled($u->email));
    }

    /**
     * "a shorter summary version (KPIs + insight count, not the full
     * detailed email)" — mission spec, verbatim.
     */
    private function whatsappSummary(User $recipient, array $payload, string $forDate): string
    {
        $kpis = $payload['kpis'];
        $insightCount = $payload['insights'] === null ? null : collect($payload['insights'])->sum(fn ($c) => $c->count());

        $lines = [
            "Daily Digest — {$forDate}",
            "Bookings today: {$kpis['bookings_today']} (yesterday: {$kpis['bookings_yesterday']})",
            'Completion rate: '.($kpis['completion_rate'] !== null ? $kpis['completion_rate'].'%' : 'n/a'),
            "Providers online: {$kpis['providers_online']}/{$kpis['providers_total']}",
        ];

        if ($insightCount !== null) {
            $lines[] = "Needs attention: {$insightCount} item(s)";
        }

        return implode("\n", $lines);
    }
}
