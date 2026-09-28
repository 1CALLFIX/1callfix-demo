<?php

namespace Tests\Feature\Scheduling;

use App\Models\Country;
use App\Models\Setting;
use App\Support\BookingSchedule;
use App\Support\BookingScheduleSlots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * REF 1CF-SCHEDULING-DISPATCH-001 (Part 1) — see BookingScheduleSlots's own
 * docblock for why this is a fresh implementation rather than a literal
 * "bug fix": no slot-picker existed anywhere in this codebase to carry the
 * described "future days lose morning slots" regression forward from.
 */
class BookingScheduleSlotsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('booking.service_window_start_hour', '8');
        Setting::set('booking.service_window_end_hour', '20');
        Setting::set('booking.max_schedule_days_ahead', '3');

        // Pins TimezoneResolver::platformTimezone() to Asia/Kolkata (its
        // "exactly one Country.default_timezone" rule) so every Carbon
        // below, parsed explicitly in Asia/Kolkata, is interpreted the same
        // way the class under test interprets it — otherwise the platform
        // timezone falls back to config('app.timezone') (UTC in this
        // suite), which would silently shift every assertion by 5:30.
        Country::create(['name' => 'Testland', 'code' => 'IN', 'currency_code' => 'INR', 'default_timezone' => 'Asia/Kolkata', 'is_active' => true]);
    }

    // ---------------------------------------------------------- buffer

    public function test_default_buffer_is_thirty_minutes(): void
    {
        $this->assertSame(30, BookingSchedule::bufferMinutes());
    }

    public function test_buffer_can_be_set_to_sixty_minutes(): void
    {
        Setting::set('booking.scheduling_buffer_minutes', '60');
        $this->assertSame(60, BookingSchedule::bufferMinutes());
    }

    public function test_an_invalid_buffer_value_falls_back_to_thirty(): void
    {
        Setting::set('booking.scheduling_buffer_minutes', '45');
        $this->assertSame(30, BookingSchedule::bufferMinutes());
    }

    // ---------------------------------------------------------- today, 30-min buffer

    public function test_today_slots_with_thirty_minute_buffer(): void
    {
        Setting::set('booking.scheduling_buffer_minutes', '30');
        $now = Carbon::parse('2026-10-01 09:47', 'Asia/Kolkata');

        $slots = app(BookingScheduleSlots::class)->availableSlots(referenceNow: $now);
        $today = $slots['2026-10-01'];

        // First valid slot is the next buffer-aligned slot >= now + 30min
        // (10:17) rounded up to the grid (10:30, since the grid is fixed at
        // 08:00, 08:30, 09:00, ...).
        $this->assertSame('10:30', $today[0]->format('H:i'));
    }

    // ---------------------------------------------------------- today, 60-min buffer

    public function test_today_slots_with_sixty_minute_buffer(): void
    {
        Setting::set('booking.scheduling_buffer_minutes', '60');
        $now = Carbon::parse('2026-10-01 09:10', 'Asia/Kolkata');

        $slots = app(BookingScheduleSlots::class)->availableSlots(referenceNow: $now);
        $today = $slots['2026-10-01'];

        // now + 60min = 10:10; grid is hourly from 08:00 -> first slot >= 10:10 is 11:00.
        $this->assertSame('11:00', $today[0]->format('H:i'));
    }

    // ---------------------------------------------------------- exact boundary

    public function test_a_slot_exactly_at_the_buffer_boundary_is_valid(): void
    {
        Setting::set('booking.scheduling_buffer_minutes', '30');
        // now is exactly 30 minutes before a grid slot (10:00 - 30min = 09:30).
        $now = Carbon::parse('2026-10-01 09:30', 'Asia/Kolkata');

        $slots = app(BookingScheduleSlots::class)->availableSlots(referenceNow: $now);
        $today = $slots['2026-10-01'];

        $this->assertContains('10:00', array_map(fn ($s) => $s->format('H:i'), $today));
        $this->assertSame('10:00', $today[0]->format('H:i'));
    }

    public function test_a_slot_one_minute_inside_the_buffer_is_rejected(): void
    {
        Setting::set('booking.scheduling_buffer_minutes', '30');
        // 09:31 -> 10:00 is only 29 minutes away, inside the buffer.
        $now = Carbon::parse('2026-10-01 09:31', 'Asia/Kolkata');

        $slots = app(BookingScheduleSlots::class)->availableSlots(referenceNow: $now);
        $today = $slots['2026-10-01'];

        $this->assertNotContains('10:00', array_map(fn ($s) => $s->format('H:i'), $today));
        $this->assertSame('10:30', $today[0]->format('H:i'));
    }

    // ---------------------------------------------------------- today disappears

    public function test_today_is_omitted_once_no_valid_slots_remain(): void
    {
        Setting::set('booking.scheduling_buffer_minutes', '30');
        // Window ends 20:00, last slot is 19:30. now + 30min = 19:45, past it.
        $now = Carbon::parse('2026-10-01 19:20', 'Asia/Kolkata');

        $slots = app(BookingScheduleSlots::class)->availableSlots(referenceNow: $now);

        $this->assertArrayNotHasKey('2026-10-01', $slots);
        $this->assertArrayHasKey('2026-10-02', $slots);
    }

    // ---------------------------------------------------------- future days

    public function test_future_days_show_the_full_morning_schedule(): void
    {
        Setting::set('booking.scheduling_buffer_minutes', '30');
        $now = Carbon::parse('2026-10-01 09:00', 'Asia/Kolkata');

        $slots = app(BookingScheduleSlots::class)->availableSlots(referenceNow: $now);
        $tomorrow = $slots['2026-10-02'];

        // Window starts 08:00 — the very first slot of the day must be present.
        $this->assertSame('08:00', $tomorrow[0]->format('H:i'));
    }

    public function test_future_days_are_not_restricted_by_current_time(): void
    {
        Setting::set('booking.scheduling_buffer_minutes', '30');
        // "now" is late in the day (19:55) — a naive implementation that
        // applied the TODAY lead-time rule to every day would wrongly trim
        // tomorrow's morning slots too.
        $now = Carbon::parse('2026-10-01 19:55', 'Asia/Kolkata');

        $slots = app(BookingScheduleSlots::class)->availableSlots(referenceNow: $now);
        $tomorrow = $slots['2026-10-02'];

        $this->assertSame('08:00', $tomorrow[0]->format('H:i'));
    }

    public function test_final_future_day_slot_equals_service_end_minus_buffer(): void
    {
        Setting::set('booking.scheduling_buffer_minutes', '30');
        $now = Carbon::parse('2026-10-01 09:00', 'Asia/Kolkata');

        $slots = app(BookingScheduleSlots::class)->availableSlots(referenceNow: $now);
        $tomorrow = $slots['2026-10-02'];

        // window end 20:00 - buffer 30min = 19:30.
        $this->assertSame('19:30', end($tomorrow)->format('H:i'));
    }

    public function test_days_ahead_count_comes_from_the_existing_admin_setting(): void
    {
        Setting::set('booking.max_schedule_days_ahead', '2');
        $now = Carbon::parse('2026-10-01 09:00', 'Asia/Kolkata');

        $slots = app(BookingScheduleSlots::class)->availableSlots(referenceNow: $now);

        $this->assertArrayHasKey('2026-10-03', $slots);
        $this->assertArrayNotHasKey('2026-10-04', $slots);
    }
}
