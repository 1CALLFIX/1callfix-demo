<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Services\Operations\DataClearBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Clear Data tool, STEP 2 — backup then verify. "mysqldump exited 0" is
 * deliberately never treated as sufficient proof on its own; every one of
 * these failure modes must independently refuse. Uses Process::fake() to
 * simulate mysqldump's output rather than needing a real MySQL server
 * (this sandboxed suite has none) — see DataClearBackupService::
 * currentDriver()'s own docblock for why the driver check specifically is
 * stubbed via a partial mock in the "verification succeeds" tests, while
 * every other line of the real size/row-count verification logic runs
 * unmocked against that faked process output.
 */
class DataClearBackupVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function seedUsers(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            User::create([
                'uuid' => (string) Str::uuid(),
                'name' => "Seed User {$i}",
                'phone' => '9'.fake()->unique()->numerify('#########'),
                'role' => 'customer',
                'status' => 'active',
            ]);
        }
    }

    public function test_refuses_when_the_db_connection_is_not_mysql(): void
    {
        // Genuinely real -- no mock at all. phpunit.xml's DB_CONNECTION is
        // sqlite, so DataClearBackupService::currentDriver() (unstubbed)
        // reports the real, actual driver this test runs against.
        $result = app(DataClearBackupService::class)->backupAndVerify(['users']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('requires a MySQL connection', $result->reason);
        $this->assertStringContainsString('sqlite', $result->reason);
    }

    public function test_refuses_when_no_tables_are_given(): void
    {
        $result = app(DataClearBackupService::class)->backupAndVerify([]);

        $this->assertFalse($result->success);
        $this->assertSame('No tables given to back up.', $result->reason);
    }

    private function backupServiceReportingMysql(): DataClearBackupService
    {
        $fake = \Mockery::mock(DataClearBackupService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $fake->shouldReceive('currentDriver')->andReturn('mysql');

        return $fake;
    }

    public function test_refuses_when_mysqldump_itself_fails(): void
    {
        Process::fake(fn () => Process::result(output: '', errorOutput: 'mysqldump: Access denied', exitCode: 1));
        $this->seedUsers(2);

        $result = $this->backupServiceReportingMysql()->backupAndVerify(['users']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Access denied', $result->reason);
    }

    public function test_refuses_when_the_dump_output_is_empty(): void
    {
        Process::fake(fn () => Process::result(output: '   ', exitCode: 0));
        $this->seedUsers(2);

        $result = $this->backupServiceReportingMysql()->backupAndVerify(['users']);

        $this->assertFalse($result->success);
        $this->assertSame('mysqldump produced empty output.', $result->reason);
    }

    public function test_refuses_when_the_dump_is_suspiciously_small(): void
    {
        // Exits 0, non-empty, but nowhere near a real dump -- exactly the
        // "0-byte or truncated dump" failure mode DEPLOYMENT_RUNBOOK.md
        // warns gives false confidence if only the exit code is trusted.
        Process::fake(fn () => Process::result(output: '-- x', exitCode: 0));
        $this->seedUsers(1);

        $result = $this->backupServiceReportingMysql()->backupAndVerify(['users']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('suspiciously small', $result->reason);
    }

    public function test_refuses_on_a_row_count_mismatch(): void
    {
        $this->seedUsers(3);
        // Only 2 INSERT lines for a table that genuinely has 3 rows.
        $badDump = "INSERT INTO `users` VALUES (1);\nINSERT INTO `users` VALUES (2);\n";
        Process::fake(fn () => Process::result(output: $badDump, exitCode: 0));

        $result = $this->backupServiceReportingMysql()->backupAndVerify(['users']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Row-count mismatch', $result->reason);
        $this->assertStringContainsString('`users`', $result->reason);
        $this->assertStringContainsString('dump has 2', $result->reason);
        $this->assertStringContainsString('live table had 3', $result->reason);
    }

    public function test_succeeds_with_a_dump_whose_row_counts_genuinely_match(): void
    {
        $this->seedUsers(3);
        $goodDump = "INSERT INTO `users` VALUES (1);\nINSERT INTO `users` VALUES (2);\nINSERT INTO `users` VALUES (3);\n";
        Process::fake(fn () => Process::result(output: $goodDump, exitCode: 0));

        $result = $this->backupServiceReportingMysql()->backupAndVerify(['users']);

        $this->assertTrue($result->success, $result->reason ?? '');
        $this->assertNotNull($result->path);
        $this->assertFileExists($result->path);
        $this->assertGreaterThan(0, $result->sizeBytes);
        $this->assertSame(['users' => 3], $result->preClearRowCounts);

        // The file on disk is genuinely the gzip-compressed dump, not a placeholder.
        $this->assertSame($goodDump, gzdecode(file_get_contents($result->path)));

        @unlink($result->path);
    }

    /** A table with zero rows must not falsely report a mismatch (0 INSERT lines vs. a live count of 0 is a correct match, not a failure). Mixed with a non-empty table in the same call so this also proves per-table counting doesn't cross-contaminate. */
    public function test_an_empty_table_alongside_a_non_empty_one_is_verified_correctly(): void
    {
        // users stays at 0 rows on purpose; notification_campaigns gets 2.
        \App\Models\NotificationCampaign::create([
            'category' => 'business', 'type' => 'admin_direct_message', 'title' => 'A', 'message' => 'A',
            'recipient_type' => 'everyone', 'scope_type' => 'global', 'channels' => 'mail', 'priority' => 'normal', 'status' => 'draft',
        ]);
        \App\Models\NotificationCampaign::create([
            'category' => 'business', 'type' => 'admin_direct_message', 'title' => 'B', 'message' => 'B',
            'recipient_type' => 'everyone', 'scope_type' => 'global', 'channels' => 'mail', 'priority' => 'normal', 'status' => 'draft',
        ]);

        $dump = "INSERT INTO `notification_campaigns` VALUES (1);\nINSERT INTO `notification_campaigns` VALUES (2);\n";
        Process::fake(fn () => Process::result(output: $dump, exitCode: 0));

        $result = $this->backupServiceReportingMysql()->backupAndVerify(['users', 'notification_campaigns']);

        $this->assertTrue($result->success, $result->reason ?? '');
        $this->assertSame(['users' => 0, 'notification_campaigns' => 2], $result->preClearRowCounts);

        @unlink($result->path);
    }
}
