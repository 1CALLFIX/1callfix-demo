<?php

namespace Tests\Feature\Ai;

use App\Models\Zone;
use App\Services\Ai\BookingNaturalLanguageFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Admin Polish + AI session, Part 2 item 2 — real regression coverage for
 * the deterministic natural-language -> filter mapping (the part that must
 * be reliably correct; there is no LLM phrasing layer to separately test
 * here — see the class's own docblock for why).
 */
class BookingNaturalLanguageFilterTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    private function zones(): \Illuminate\Support\Collection
    {
        [, , , $zoneA] = $this->makeFranchiseTree();
        $zoneA->update(['name' => 'Downtown', 'code' => 'DT']);
        [, , , $zoneB] = $this->makeFranchiseTree();
        $zoneB->update(['name' => 'Uptown', 'code' => 'UT']);

        return Zone::whereIn('id', [$zoneA->id, $zoneB->id])->get();
    }

    public function test_recognizes_a_status_keyword(): void
    {
        $result = app(BookingNaturalLanguageFilter::class)->parse('cancelled bookings', $this->zones());

        $this->assertSame('cancelled', $result['status']);
    }

    public function test_recognizes_a_status_synonym(): void
    {
        $result = app(BookingNaturalLanguageFilter::class)->parse('show me all unassigned jobs', $this->zones());

        $this->assertSame('searching_provider', $result['status']);
    }

    public function test_recognizes_a_zone_by_name(): void
    {
        $zones = $this->zones();
        $result = app(BookingNaturalLanguageFilter::class)->parse('bookings in downtown', $zones);

        $this->assertSame($zones->firstWhere('name', 'Downtown')->id, $result['zone_id']);
    }

    public function test_recognizes_a_zone_by_numeric_id_only_when_it_is_a_real_scoped_zone(): void
    {
        $zones = $this->zones();
        $realId = $zones->first()->id;

        $result = app(BookingNaturalLanguageFilter::class)->parse("zone {$realId} bookings", $zones);
        $this->assertSame($realId, $result['zone_id']);

        // A number that doesn't correspond to any zone IN THE PASSED-IN,
        // already-scoped collection must never resolve to a zone_id — this
        // is the guard against an admin probing for ids outside their own
        // scope by typing arbitrary numbers into the search box.
        $bogusResult = app(BookingNaturalLanguageFilter::class)->parse('zone 999999 bookings', $zones);
        $this->assertNull($bogusResult['zone_id']);
    }

    public function test_recognizes_today(): void
    {
        $result = app(BookingNaturalLanguageFilter::class)->parse('bookings today', $this->zones());

        $this->assertNotNull($result['date_from']);
        $this->assertTrue($result['date_from']->isSameDay(Carbon::now()));
    }

    public function test_recognizes_last_n_days(): void
    {
        $result = app(BookingNaturalLanguageFilter::class)->parse('bookings from the last 14 days', $this->zones());

        $this->assertNotNull($result['date_from']);
        $this->assertEqualsWithDelta(now()->subDays(14)->startOfDay()->timestamp, $result['date_from']->timestamp, 5);
    }

    public function test_combines_status_zone_and_date_in_one_query(): void
    {
        $zones = $this->zones();
        $downtown = $zones->firstWhere('name', 'Downtown');

        $result = app(BookingNaturalLanguageFilter::class)->parse('cancelled bookings in Downtown this week', $zones);

        $this->assertSame('cancelled', $result['status']);
        $this->assertSame($downtown->id, $result['zone_id']);
        $this->assertNotNull($result['date_from']);
        $this->assertCount(3, $result['matched']);
    }

    public function test_unrecognized_text_returns_all_nulls_not_an_error(): void
    {
        $result = app(BookingNaturalLanguageFilter::class)->parse('asdkfjasldkfj random gibberish', $this->zones());

        $this->assertNull($result['status']);
        $this->assertNull($result['zone_id']);
        $this->assertNull($result['date_from']);
        $this->assertSame([], $result['matched']);
    }
}
