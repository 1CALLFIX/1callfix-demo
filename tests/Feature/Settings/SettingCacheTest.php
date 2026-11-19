<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026-11-19: Setting::get cached only keys that had a value. An unset key (most of them) went to the database and
 * wrote the cache again on every lookup, which made pages slow everywhere and timed out at 30 s locally. "Not set" is
 * cached now, and set() / clear() still show at once.
 */
class SettingCacheTest extends TestCase
{
    use RefreshDatabase;

    private function settingQueries(callable $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $count = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"settings"') || str_contains($q['query'], '`settings`'))->count();
        DB::disableQueryLog();

        return $count;
    }

    public function test_an_unset_key_is_read_from_the_database_once_then_from_the_cache(): void
    {
        $this->assertSame(1, $this->settingQueries(fn () => Setting::get('test.never_set', 'fallback')));
        $this->assertSame(0, $this->settingQueries(function () {
            for ($i = 0; $i < 5; $i++) {
                $this->assertSame('fallback', Setting::get('test.never_set', 'fallback'));
            }
        }), 'A cached "not set" never goes back to the database.');
    }

    public function test_set_and_clear_show_at_once_after_a_cached_not_set(): void
    {
        $this->assertNull(Setting::get('test.flag'));
        Setting::set('test.flag', '1');
        $this->assertSame('1', Setting::get('test.flag'));
        Setting::clear('test.flag', 'global', null);
        $this->assertNull(Setting::get('test.flag'));
    }

    public function test_a_zone_value_set_after_a_cached_miss_wins_over_global(): void
    {
        Setting::set('test.kind', '0');
        $this->assertSame('0', Setting::get('test.kind', null, ['zone_id' => 7]));
        Setting::set('test.kind', '1', 'zone', 7);
        $this->assertSame('1', Setting::get('test.kind', null, ['zone_id' => 7]));
        $this->assertSame('0', Setting::get('test.kind', null, ['zone_id' => 8]));
    }

    public function test_a_row_written_or_deleted_through_the_model_shows_at_once(): void
    {
        $this->assertNull(Setting::get('test.direct'));
        $row = Setting::updateOrCreate(['key' => 'test.direct', 'scope_type' => 'global', 'scope_id' => null], ['value' => '45']);
        $this->assertSame('45', Setting::get('test.direct'));
        $row->delete();
        $this->assertNull(Setting::get('test.direct'));
    }

    public function test_a_plain_value_cached_before_this_change_still_reads(): void
    {
        cache()->forever('setting:global::test.old_entry', 'old');
        $this->assertSame('old', Setting::get('test.old_entry'));
    }
}
