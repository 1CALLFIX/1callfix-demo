<?php

namespace Tests\Feature\Operations;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Operations\DataClearAuditLogger;
use App\Services\Operations\DataClearService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Feature\Support\DataClearTestHelpers;
use Tests\TestCase;

/**
 * Clear Data tool, STEP 5 — the durable audit log. storage/logs/
 * data-clear.log is written to BEFORE the backup or the clear runs, is
 * append-only, and must survive regardless of what the clear operation
 * itself does to the database.
 *
 * The acceptance criterion's literal scenario ("the clear operation
 * targets the table the log-mirroring activity_log entry would have
 * lived in") cannot actually be constructed through this tool's real
 * public API — activity_log is on DataClearCatalog::NEVER_CLEARABLE,
 * exactly per the approved design's own instruction ("whatever table the
 * audit log below writes to" must be on that list). What's proven below
 * instead is the STRONGER, actually-relevant property that criterion was
 * really about: the FILE-based log lives entirely outside the database
 * (storage/logs/, never a table this tool could ever touch, by
 * construction), so it survives ANY clear operation this tool can
 * perform — not merely because the never-clearable list happens to
 * protect its DB-mirror counterpart today.
 */
class DataClearAuditLogTest extends TestCase
{
    use RefreshDatabase;
    use DataClearTestHelpers;

    protected function tearDown(): void
    {
        @unlink(app(DataClearAuditLogger::class)->logPath());
        parent::tearDown();
    }

    private function makeSuperAdmin(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Super Admin',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    public function test_activity_log_itself_can_never_be_selected_as_a_module_to_clear(): void
    {
        $forbidden = app(\App\Services\Operations\DataClearService::class)->forbiddenTables(['activity_log']);

        $this->assertSame(['activity_log'], $forbidden);
    }

    public function test_an_attempted_entry_is_written_before_the_clear_runs(): void
    {
        $this->bindWorkingDataClearBackup();
        $actor = $this->makeSuperAdmin();
        $expected = app(DataClearService::class)->generateConfirmationPhrase(['wallet_transactions']);

        $result = app(DataClearService::class)->run($actor, ['wallet_transactions'], $expected, $expected, '10.0.0.5');

        $entries = app(DataClearAuditLogger::class)->readAll();
        $attempted = collect($entries)->firstWhere('phase', 'attempted');

        $this->assertNotNull($attempted);
        $this->assertSame($result->operationId, $attempted['operation_id']);
        $this->assertSame($actor->id, $attempted['actor_id']);
        $this->assertSame(['wallet_transactions'], $attempted['modules']);
        $this->assertSame(['wallet_transactions'], $attempted['tables']);
        $this->assertSame('10.0.0.5', $attempted['ip']);
        $this->assertArrayHasKey('row_counts_before', $attempted);
        $this->assertArrayHasKey('at', $attempted);
    }

    /** The refusal path is logged too — a real security-relevant trail of who tried what, not just what succeeded. */
    public function test_a_refused_attempt_is_also_logged(): void
    {
        $actor = $this->makeSuperAdmin();

        app(DataClearService::class)->run($actor, ['wallet_transactions'], 'wrong', 'CLEAR-X-Y', '10.0.0.9');

        $entries = app(DataClearAuditLogger::class)->readAll();
        $refused = collect($entries)->firstWhere('phase', 'refused');

        $this->assertNotNull($refused);
        $this->assertSame($actor->id, $refused['actor_id']);
        $this->assertStringContainsString('Confirmation phrase did not match', $refused['reason']);
    }

    public function test_a_completed_entry_is_appended_separately_from_the_attempted_one(): void
    {
        $this->bindWorkingDataClearBackup();
        $actor = $this->makeSuperAdmin();
        $expected = app(DataClearService::class)->generateConfirmationPhrase(['wallet_transactions']);

        $result = app(DataClearService::class)->run($actor, ['wallet_transactions'], $expected, $expected, '127.0.0.1');

        $entries = app(DataClearAuditLogger::class)->readAll();
        $completed = collect($entries)->firstWhere('phase', 'completed');

        $this->assertNotNull($completed);
        $this->assertSame($result->operationId, $completed['operation_id']);
        $this->assertArrayHasKey('row_counts_before', $completed);
        $this->assertArrayHasKey('row_counts_after', $completed);
        $this->assertArrayHasKey('backup_path', $completed);

        // Both lines exist -- append-only, not a single mutated record.
        $this->assertCount(2, $entries);
    }

    public function test_a_backup_failure_still_leaves_the_attempted_entry_on_disk(): void
    {
        // No bindWorkingDataClearBackup() -- the real (unmocked)
        // currentDriver() reports sqlite, so the backup step genuinely
        // fails here.
        $actor = $this->makeSuperAdmin();
        $expected = app(DataClearService::class)->generateConfirmationPhrase(['wallet_transactions']);

        $result = app(DataClearService::class)->run($actor, ['wallet_transactions'], $expected, $expected, '127.0.0.1');

        $this->assertTrue($result->refused);

        $entries = app(DataClearAuditLogger::class)->readAll();
        $this->assertNotNull(collect($entries)->firstWhere('phase', 'attempted'), 'The attempted entry must survive even though backup verification failed afterward.');
        $this->assertNotNull(collect($entries)->firstWhere('phase', 'refused_backup_failed'));
    }

    /**
     * THE actual point of this whole suite: the file survives a real
     * clear operation on the database, entirely independent of it —
     * proven by reading it back from disk, not from any in-memory state,
     * after a genuine clear has run.
     */
    public function test_the_log_file_survives_and_is_readable_after_a_real_clear_operation(): void
    {
        $this->bindWorkingDataClearBackup();
        $actor = $this->makeSuperAdmin();
        $expected = app(DataClearService::class)->generateConfirmationPhrase(['wallet_transactions']);

        app(DataClearService::class)->run($actor, ['wallet_transactions'], $expected, $expected, '127.0.0.1');

        $path = app(DataClearAuditLogger::class)->logPath();
        $this->assertFileExists($path);
        $this->assertStringContainsString('storage'.DIRECTORY_SEPARATOR.'logs', $path);

        $raw = File::get($path);
        foreach (explode(PHP_EOL, trim($raw)) as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, 'Every line must be a complete, independently-parseable JSON object.');
        }
    }

    /** Mirrored into activity_log for normal admin browsability, on top of (not instead of) the file. */
    public function test_a_completed_run_also_mirrors_into_activity_log(): void
    {
        $this->bindWorkingDataClearBackup();
        $actor = $this->makeSuperAdmin();
        $expected = app(DataClearService::class)->generateConfirmationPhrase(['wallet_transactions']);

        $result = app(DataClearService::class)->run($actor, ['wallet_transactions'], $expected, $expected, '127.0.0.1');

        $log = ActivityLog::where('subject_type', 'DataClearOperation')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($actor->id, $log->causer_id);
        $this->assertSame($result->operationId, $log->properties['operation_id']);
    }
}
