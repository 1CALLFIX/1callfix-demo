<?php

namespace Tests\Feature\Operations;

use App\Services\Operations\DataClearCatalog;
use App\Services\Operations\DataClearService;
use Tests\TestCase;

/**
 * Clear Data tool, STEP 3 (never-clearable half) — the code-level safety
 * floor: users, role_assignments, roles, permissions, settings, and
 * activity_log can never be cleared by this tool, and that must be true
 * regardless of what DataClearCatalog::MODULES claims, not merely true
 * because the catalog currently behaves.
 *
 * DataClearService::forbiddenTables() is tested directly against
 * ARBITRARY table lists (not just ones the real catalog would ever
 * produce) specifically so this proves the CHECK MECHANISM itself is
 * correct, independent of whether today's catalog happens to trigger it —
 * a future catalog bug that DID include a never-clearable table would hit
 * this exact same check inside run().
 */
class DataClearNeverClearableTest extends TestCase
{
    public function test_forbidden_tables_flags_a_single_never_clearable_table_mixed_with_safe_ones(): void
    {
        $forbidden = app(DataClearService::class)->forbiddenTables(['bookings', 'users', 'wallet_transactions']);

        $this->assertSame(['users'], $forbidden);
    }

    public function test_forbidden_tables_flags_every_never_clearable_table_present(): void
    {
        $forbidden = app(DataClearService::class)->forbiddenTables(['role_assignments', 'settings', 'activity_log', 'bookings']);

        sort($forbidden);
        $this->assertSame(['activity_log', 'role_assignments', 'settings'], $forbidden);
    }

    public function test_forbidden_tables_returns_empty_for_a_genuinely_safe_list(): void
    {
        $forbidden = app(DataClearService::class)->forbiddenTables(['bookings', 'wallet_transactions', 'notifications']);

        $this->assertSame([], $forbidden);
    }

    public function test_every_never_clearable_table_is_individually_caught(): void
    {
        foreach (DataClearCatalog::NEVER_CLEARABLE as $table) {
            $forbidden = app(DataClearService::class)->forbiddenTables([$table]);
            $this->assertSame([$table], $forbidden, "`{$table}` must be caught on its own, not just in combination with others.");
        }
    }

    /**
     * Sanity check on the SHIPPED catalog itself (complementary to, not a
     * substitute for, the direct forbiddenTables() tests above): today's
     * real module definitions never resolve to a forbidden table, which
     * is exactly why run() can never be driven into that refusal branch
     * through its real public API right now.
     */
    public function test_the_real_catalog_never_offers_a_never_clearable_table(): void
    {
        $allTables = DataClearCatalog::tablesFor(DataClearCatalog::allModuleKeys());

        $this->assertEmpty(array_intersect($allTables, DataClearCatalog::NEVER_CLEARABLE));
    }
}
