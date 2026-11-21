<?php

namespace Tests\Feature\Backup;

use App\Console\Commands\CheckOffsiteBackup;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\BackupAlertNotification;
use App\Notifications\Channels\PushChannel;
use App\Services\Backup\BackupCatalog;
use App\Services\Backup\BackupShell;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * 2026-11-21 — backups:check-offsite: email + push to Backup & Restore admins when copies stop reaching the Drive
 * (the Drive filled up on 29 Oct and the backup failed for three weeks unnoticed), and once when it works again.
 */
class OffsiteBackupAlertTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('notifications.channels', 'mail,push');
        $this->log = storage_path('framework/testing/backup-offsite-'.Str::random(6).'.log');
        File::ensureDirectoryExists(dirname($this->log));
        config([
            'backup.remote' => 'gdrive-crypt:superapp',
            'backup.local_dir' => storage_path('framework/testing/no-backups-here'),
            'backup.clock_timezone' => 'Asia/Kolkata',
            'backup.log' => $this->log,
        ]);
        Cache::forget(BackupCatalog::CACHE_KEY);
        Cache::forget(CheckOffsiteBackup::STATE_KEY);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        File::delete($this->log);
        parent::tearDown();
    }

    /** A file name as scripts/backup-offsite.sh writes it: the server clock (India time). */
    private function backupFile(string $prefix, CarbonImmutable $at): string
    {
        return $prefix.$at->setTimezone('Asia/Kolkata')->format('Y-m-d_H-i-s').'.sql.gz';
    }

    /** The Drive holds an hourly copy $hourlyMinutes old and a daily copy $dailyHours old. */
    private function drive(int $hourlyMinutes, int $dailyHours = 10): void
    {
        $now = CarbonImmutable::now();
        $tree = [
            'hourly' => [$this->backupFile('1cal_api_hourly_', $now->subMinutes($hourlyMinutes))],
            'daily' => [$this->backupFile('1cal_api_', $now->subHours($dailyHours))],
        ];
        $shell = Mockery::mock(BackupShell::class);
        $shell->shouldReceive('remoteTree')->andReturn($tree);
        $this->app->instance(BackupShell::class, $shell);
        $this->app->forgetInstance(BackupCatalog::class);
        Cache::forget(BackupCatalog::CACHE_KEY);
    }

    private function admin(): User
    {
        $admin = $this->makeSuperAdmin();
        $admin->forceFill(['email' => 'owner-'.Str::random(6).'@example.test'])->save();

        return $admin;
    }

    public function test_healthy_backups_send_nothing(): void
    {
        $this->admin();
        $this->drive(20);

        $this->artisan('backups:check-offsite')->expectsOutput('Off-site backups are arriving on time.')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_late_hourly_copy_alerts_backup_admins_only_with_the_cause_in_plain_words(): void
    {
        $owner = $this->admin();
        $backupAdmin = $this->makeUserWithPermission('backups.restore', 'global');
        $backupAdmin->forceFill(['email' => 'ops@example.test'])->save();
        $otherAdmin = $this->makeUserWithPermission('bookings.view', 'global');
        $otherAdmin->forceFill(['email' => 'other@example.test'])->save();
        File::put($this->log, "Hourly offsite backup OK: x.sql.gz (5M)\n"
            ."2026/11/21 14:15:17 ERROR : Attempt 3/3 failed with 1 errors and: googleapi: Error 403: The user's Drive storage quota has been exceeded., storageQuotaExceeded\n"
            ."FAILED: offsite hourly backup (line 75)\n");
        $this->drive(200);

        $this->artisan('backups:check-offsite')->assertSuccessful();

        foreach ([$owner, $backupAdmin] as $who) {
            Notification::assertSentTo($who, BackupAlertNotification::class, function (BackupAlertNotification $n, array $channels) use ($who) {
                $copy = $n->toPush($who);

                return $n->event === 'failing' && $channels === ['mail']
                    && $copy['title'] === 'Off-site backup is failing'
                    && str_contains($copy['body'], 'No new hourly database backup has reached Google Drive since')
                    && str_contains($copy['body'], 'the Google Drive is full');
            });
        }
        Notification::assertNotSentTo($otherAdmin, BackupAlertNotification::class);
        $this->assertNotNull(Cache::get(CheckOffsiteBackup::STATE_KEY));
    }

    public function test_alert_time_is_shown_in_india_time(): void
    {
        $owner = $this->admin();
        $this->drive(200);
        $expected = CarbonImmutable::now()->subMinutes(200)->setTimezone('Asia/Kolkata')->format('d/m/Y, h:i A');

        $this->artisan('backups:check-offsite')->assertSuccessful();

        Notification::assertSentTo($owner, BackupAlertNotification::class, fn ($n) => str_contains($n->toPush($owner)['body'], $expected));
    }

    public function test_one_missed_run_stays_quiet(): void
    {
        $this->admin();
        $this->drive(100); // one hourly run missed: under the 150-minute limit

        $this->artisan('backups:check-offsite')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_a_late_daily_copy_alerts_even_when_hourly_copies_arrive(): void
    {
        $owner = $this->admin();
        $this->drive(20, 30);

        $this->artisan('backups:check-offsite')->assertSuccessful();

        Notification::assertSentTo($owner, BackupAlertNotification::class, fn ($n) => str_contains($n->toPush($owner)['body'], 'No new daily backup'));
    }

    public function test_it_repeats_every_six_hours_while_broken_then_says_once_that_it_works_again(): void
    {
        $owner = $this->admin();
        $this->drive(200);

        $this->artisan('backups:check-offsite')->assertSuccessful();
        $this->artisan('backups:check-offsite')->assertSuccessful();
        Notification::assertSentToTimes($owner, BackupAlertNotification::class, 1);

        $this->travel(7)->hours();
        $this->drive(200);
        $this->artisan('backups:check-offsite')->assertSuccessful();
        Notification::assertSentToTimes($owner, BackupAlertNotification::class, 2);

        $this->drive(10);
        $this->artisan('backups:check-offsite')->assertSuccessful();
        $this->artisan('backups:check-offsite')->assertSuccessful();

        Notification::assertSentToTimes($owner, BackupAlertNotification::class, 3);
        Notification::assertSentTo($owner, BackupAlertNotification::class, fn ($n) => $n->event === 'recovered'
            && $n->toPush($owner)['title'] === 'Backup is working again');
        $this->assertNull(Cache::get(CheckOffsiteBackup::STATE_KEY));
    }

    public function test_an_unreadable_drive_alerts(): void
    {
        $owner = $this->admin();
        $shell = Mockery::mock(BackupShell::class);
        $shell->shouldReceive('remoteTree')->andThrow(new RuntimeException('Could not list the off-site backups: didn\'t find section in config file'));
        $this->app->instance(BackupShell::class, $shell);

        $this->artisan('backups:check-offsite')->assertSuccessful();

        Notification::assertSentTo($owner, BackupAlertNotification::class, fn ($n) => str_contains($n->toPush($owner)['body'], 'cannot read the backup folder')
            && str_contains($n->toPush($owner)['body'], 'didn\'t find section'));
    }

    public function test_no_error_after_the_last_success_points_at_the_cron(): void
    {
        $owner = $this->admin();
        File::put($this->log, "2026/11/01 ERROR : storageQuotaExceeded\nFAILED: offsite hourly backup (line 75)\nHourly offsite backup OK: y.sql.gz (5M)\n");
        $this->drive(300);

        $this->artisan('backups:check-offsite')->assertSuccessful();

        Notification::assertSentTo($owner, BackupAlertNotification::class, fn ($n) => str_contains((string) $n->reason, 'crontab -l')
            && ! str_contains((string) $n->reason, 'full'));
    }

    public function test_email_switch_off_leaves_push_for_opted_in_admins(): void
    {
        Setting::set('alerts.email.backup_failed', '0');
        $owner = $this->admin();
        $owner->forceFill(['push_ops_alerts' => true, 'fcm_token' => 'tok-'.Str::random(8)])->save();
        $mailOnly = $this->admin();
        $this->drive(200);

        $this->artisan('backups:check-offsite')->assertSuccessful();

        Notification::assertSentTo($owner, BackupAlertNotification::class, fn ($n, array $channels) => $channels === [PushChannel::class]);
        Notification::assertNotSentTo($mailOnly, BackupAlertNotification::class);
    }

    public function test_suspended_admins_get_nothing_and_it_is_silent_where_backup_is_not_set_up(): void
    {
        $owner = $this->admin();
        $owner->forceFill(['status' => 'suspended'])->save();
        $this->drive(200);
        $this->artisan('backups:check-offsite')->assertSuccessful();
        Notification::assertNothingSent();

        config(['backup.remote' => null]);
        $this->artisan('backups:check-offsite')->expectsOutput('Off-site backup is not set up here; nothing to check.')->assertSuccessful();
    }

    public function test_file_names_are_read_in_server_clock_so_a_stale_copy_is_not_shown_healthy(): void
    {
        $shell = Mockery::mock(BackupShell::class);
        // The last copy of the 29 Oct outage pattern: a name written 3 hours ago in India time. Read as UTC it
        // looked 2.5 hours in the future and the screen kept showing "Healthy".
        $shell->shouldReceive('remoteTree')->andReturn(['hourly' => [$this->backupFile('1cal_api_hourly_', CarbonImmutable::now()->subHours(3))]]);

        $status = (new BackupCatalog($shell))->status();

        $this->assertFalse($status['healthy']);
        $this->assertEqualsWithDelta(CarbonImmutable::now()->subHours(3)->timestamp, $status['last_at']->timestamp, 1);
    }

    public function test_the_type_is_on_the_alert_emails_screen(): void
    {
        $this->assertArrayHasKey('backup_failed', \App\Services\AdminOpsAlertService::EMAIL_TYPES);
    }
}
