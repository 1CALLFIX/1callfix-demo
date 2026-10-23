<?php

namespace Tests\Feature\Backup;

use App\Models\Booking;
use App\Models\Franchise;
use App\Models\Zone;
use App\Services\Backup\ScopedRestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-BACKUP-RESTORE-001 — Restore one: brings back what was deleted after the backup, for the
 * chosen item only; never overwrites newer data, never writes money tables.
 */
class ScopedRestoreServiceTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;
    use SnapshotTestHelpers;

    private array $a;

    private Zone $spareZone;

    private Zone $otherFranchiseZone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerSnapshotConnection();

        $this->a = $this->makeBookingScenario();
        $this->spareZone = Zone::create([
            'franchise_id' => $this->a['franchise']->id, 'name' => 'Spare zone',
            'boundary_polygon' => [['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]],
            'is_active' => true, 'default_dispatch_radius_km' => 8,
        ]);
        DB::table('payments')->insert([
            'booking_id' => $this->a['booking']->id, 'amount' => 500, 'status' => 'captured', 'created_at' => now(), 'updated_at' => now(),
        ]);

        [, , , $this->otherFranchiseZone] = $this->makeFranchiseTree();

        $this->takeSnapshot();

        // After the backup: the accidents, one later edit and one new record.
        $this->spareZone->forceDelete();
        $this->a['booking']->delete();
        DB::table('payments')->delete();
        $this->otherFranchiseZone->forceDelete();
        $this->a['franchise']->update(['name' => 'Renamed later']);
        Zone::create([
            'franchise_id' => $this->a['franchise']->id, 'name' => 'Created after the backup',
            'boundary_polygon' => [['lat' => 1, 'lng' => 1]], 'is_active' => true, 'default_dispatch_radius_km' => 8,
        ]);
    }

    private function service(): ScopedRestoreService
    {
        return new ScopedRestoreService('restore');
    }

    private function row(array $summary, string $table): ?array
    {
        return collect($summary['tables'])->firstWhere('table', $table);
    }

    public function test_compare_reports_missing_deleted_changed_new_and_money_for_the_franchise_only(): void
    {
        $summary = $this->service()->compare('franchise', $this->a['franchise']->id);

        $this->assertSame(1, $this->row($summary, 'zones')['missing']);
        $this->assertSame(1, $this->row($summary, 'zones')['new']);
        $this->assertSame(1, $this->row($summary, 'bookings')['archived']);
        $this->assertSame(1, $this->row($summary, 'franchises')['changed']);
        $this->assertSame('Franchise', $this->row($summary, 'franchises')['changed_rows'][0]['columns']['name']['then']);
        $this->assertTrue($this->row($summary, 'payments')['money']);
        $this->assertSame(1, $this->row($summary, 'payments')['missing']);
        $this->assertSame(1, $summary['totals']['money']);
    }

    public function test_restore_missing_brings_back_deleted_rows_with_their_ids_and_touches_nothing_else(): void
    {
        $result = $this->service()->restoreMissing('franchise', $this->a['franchise']->id);

        $this->assertSame(['zones' => 1], $result['inserted']);
        $this->assertSame(['bookings' => 1], $result['undeleted']);

        $this->assertSame('Spare zone', Zone::find($this->spareZone->id)?->name, 'same id, backup values');
        $this->assertFalse(Booking::withTrashed()->find($this->a['booking']->id)->trashed());

        $this->assertSame(0, DB::table('payments')->count(), 'money is never restored');
        $this->assertNull(Zone::withTrashed()->find($this->otherFranchiseZone->id), 'other franchises are not touched');
        $this->assertSame('Renamed later', Franchise::find($this->a['franchise']->id)->name, 'newer values are not overwritten');
        $this->assertTrue(Zone::where('name', 'Created after the backup')->exists(), 'records made after the backup stay');
    }

    public function test_restore_row_puts_one_changed_record_back(): void
    {
        $this->service()->restoreRow('franchise', $this->a['franchise']->id, 'franchises', $this->a['franchise']->id);

        $this->assertSame('Franchise', Franchise::find($this->a['franchise']->id)->name);
    }

    public function test_money_rows_and_out_of_scope_rows_cannot_be_put_back_one_at_a_time(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->restoreRow('franchise', $this->a['franchise']->id, 'payments', 1);
    }

    public function test_city_scope_covers_its_franchises(): void
    {
        $summary = $this->service()->compare('city', $this->a['city']->id);

        $this->assertSame(1, $this->row($summary, 'zones')['missing']);
        $this->assertSame(1, $this->row($summary, 'franchises')['changed']);
    }

    public function test_an_item_that_did_not_exist_at_backup_time_is_refused(): void
    {
        $this->expectExceptionMessage('did not exist at the time of the chosen backup');
        $this->service()->compare('franchise', 999999);
    }

    public function test_money_table_detection(): void
    {
        foreach (['payments', 'wallet_transactions', 'payouts', 'commissions', 'provider_commission_receivables', 'charge_waivers', 'mismatch_refunds'] as $t) {
            $this->assertTrue(ScopedRestoreService::isMoneyTable($t), $t);
        }
        foreach (['zones', 'bookings', 'services', 'addresses'] as $t) {
            $this->assertFalse(ScopedRestoreService::isMoneyTable($t), $t);
        }
    }
}
