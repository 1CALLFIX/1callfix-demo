<?php

namespace Tests\Feature\ProviderWeb;

use App\Actions\SetProviderOnlineStatusAction;
use App\Exceptions\ShiftRequiredException;
use App\Livewire\Provider\Dashboard;
use App\Livewire\Provider\Shifts;
use App\Livewire\Providers\Availability;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Provider;
use App\Models\ProviderShift;
use App\Models\Setting;
use App\Notifications\ProviderShiftReminderNotification;
use App\Services\Providers\ShiftSchedule;
use App\Services\TimezoneResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Swiggy-style provider shifts: admin-defined slots, provider choice, off / reminder / required modes, overnight and
 * weekday handling, the auto-offline sweep, reminders, the admin screen and the dashboard summary strip. With the
 * mode left at its default (off) nothing about going online changes.
 */
class ProviderShiftsTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private Provider $provider;
    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();

        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->provider = $this->makeProviderIn($franchise, $zone);
        $this->provider->update(['is_online' => false]);
        $this->provider = $this->provider->fresh()->load('franchise.country');
        $this->tz = app(TimezoneResolver::class)->timezoneFor($this->provider->franchise);
    }

    /** A Monday at the given local wall-clock time, in the provider's own timezone. */
    private function at(string $hhmm, int $plusDays = 0): Carbon
    {
        return Carbon::parse('2026-10-12 '.$hhmm, $this->tz)->addDays($plusDays);
    }

    private function shift(string $name, string $start, string $end, ?array $days = null, bool $active = true): ProviderShift
    {
        return ProviderShift::create(['name' => $name, 'start_time' => $start, 'end_time' => $end, 'days' => $days, 'is_active' => $active]);
    }

    private function choose(ProviderShift ...$shifts): void
    {
        $this->provider->shifts()->sync(collect($shifts)->pluck('id')->all());
    }

    private function mode(string $mode): void
    {
        Setting::set(ShiftSchedule::MODE, $mode);
    }

    // ------------------------------------------------------------------ mode off = no change

    public function test_with_shifts_off_a_provider_can_go_online_with_no_shifts_at_all(): void
    {
        $online = app(SetProviderOnlineStatusAction::class)->execute($this->provider, true, 1.0, 1.0);

        $this->assertTrue($online->is_online);
    }

    // ------------------------------------------------------------------ required mode

    public function test_required_mode_blocks_going_online_without_a_chosen_shift(): void
    {
        $this->mode('required');
        $this->shift('Morning', '08:00', '14:00');

        $this->expectException(ShiftRequiredException::class);
        app(SetProviderOnlineStatusAction::class)->execute($this->provider, true, 1.0, 1.0);
    }

    public function test_required_mode_allows_online_inside_a_shift_and_blocks_outside_it(): void
    {
        $this->mode('required');
        Setting::set(ShiftSchedule::GRACE_MINUTES, '15');
        $this->choose($this->shift('Morning', '08:00', '14:00'));
        $action = app(SetProviderOnlineStatusAction::class);

        Carbon::setTestNow($this->at('10:00'));
        $this->assertTrue($action->execute($this->provider, true)->is_online);

        $action->execute($this->provider, false);

        Carbon::setTestNow($this->at('14:10'));                       // inside the 15-minute grace
        $this->assertTrue($action->execute($this->provider, true)->is_online);
        $action->execute($this->provider, false);

        Carbon::setTestNow($this->at('14:20'));                       // past the grace
        $this->expectException(ShiftRequiredException::class);
        $action->execute($this->provider, true);
    }

    public function test_the_dashboard_shows_the_reason_instead_of_going_online(): void
    {
        $this->mode('required');
        $this->shift('Morning', '08:00', '14:00');
        Carbon::setTestNow($this->at('10:00'));

        Livewire::actingAs($this->provider->user)->test(Dashboard::class)
            ->call('goOnline', 1.0, 1.0)
            ->assertSee('Choose at least one shift');

        $this->assertFalse($this->provider->fresh()->is_online);
    }

    public function test_an_overnight_shift_runs_past_midnight(): void
    {
        $night = $this->shift('Night', '22:00', '02:00');
        $this->choose($night);
        $schedule = app(ShiftSchedule::class);

        $this->assertTrue($schedule->isWithinShift($this->provider, $this->at('23:30')));
        $this->assertTrue($schedule->isWithinShift($this->provider, $this->at('01:00', 1)));
        $this->assertFalse($schedule->isWithinShift($this->provider, $this->at('03:00', 1)));
        $this->assertFalse($schedule->isWithinShift($this->provider, $this->at('12:00')));
    }

    public function test_a_shift_only_runs_on_its_chosen_weekdays(): void
    {
        $this->choose($this->shift('Weekend', '08:00', '14:00', [0, 6]));   // Sun, Sat

        $this->assertFalse(app(ShiftSchedule::class)->isWithinShift($this->provider, $this->at('10:00')));        // Monday
        $this->assertTrue(app(ShiftSchedule::class)->isWithinShift($this->provider, $this->at('10:00', 5)));       // Saturday
    }

    public function test_an_inactive_shift_does_not_count(): void
    {
        $this->choose($this->shift('Hidden', '08:00', '14:00', null, false));

        $this->assertFalse(app(ShiftSchedule::class)->isWithinShift($this->provider, $this->at('10:00')));
    }

    public function test_current_and_next_shift_are_reported(): void
    {
        $this->choose($this->shift('Morning', '08:00', '14:00'), $this->shift('Evening', '17:00', '21:00'));
        $schedule = app(ShiftSchedule::class);

        $this->assertSame('Morning', $schedule->current($this->provider, $this->at('09:00'))['shift']->name);
        $this->assertSame('Evening', $schedule->next($this->provider, $this->at('15:00'))['shift']->name);
        $this->assertNull($schedule->current($this->provider, $this->at('15:00')));
    }

    // ------------------------------------------------------------------ auto-offline sweep

    public function test_the_sweep_sets_out_of_shift_providers_offline_only_in_required_mode(): void
    {
        $this->choose($this->shift('Morning', '08:00', '14:00'));
        $this->provider->update(['is_online' => true, 'location_updated_at' => now()]);
        Carbon::setTestNow($this->at('20:00'));
        $this->provider->update(['location_updated_at' => now()]);

        $this->artisan('providers:expire-stale-online')->assertSuccessful();
        $this->assertTrue($this->provider->fresh()->is_online, 'mode off must not touch an online provider');

        $this->mode('required');
        $this->artisan('providers:expire-stale-online')->assertSuccessful();
        $this->assertFalse($this->provider->fresh()->is_online);
    }

    public function test_the_sweep_keeps_an_in_shift_provider_online(): void
    {
        $this->mode('required');
        $this->choose($this->shift('Morning', '08:00', '14:00'));
        Carbon::setTestNow($this->at('10:00'));
        $this->provider->update(['is_online' => true, 'location_updated_at' => now()]);

        $this->artisan('providers:expire-stale-online')->assertSuccessful();

        $this->assertTrue($this->provider->fresh()->is_online);
    }

    public function test_the_auto_offline_minutes_are_an_admin_setting(): void
    {
        Setting::set(ShiftSchedule::STALE_MINUTES, '10');
        Carbon::setTestNow($this->at('10:00'));
        $this->provider->update(['is_online' => true, 'location_updated_at' => now()->subMinutes(12)]);

        $this->artisan('providers:expire-stale-online')->assertSuccessful();

        $this->assertFalse($this->provider->fresh()->is_online);
    }

    // ------------------------------------------------------------------ reminders

    public function test_a_reminder_goes_out_once_before_a_chosen_shift_to_an_offline_provider(): void
    {
        Notification::fake();
        Cache::flush();
        $this->mode('reminder');
        Setting::set(ShiftSchedule::REMINDER_MINUTES, '10');
        $this->choose($this->shift('Morning', '08:00', '14:00'));

        Carbon::setTestNow($this->at('07:50'));
        $this->artisan('providers:shift-reminders')->assertSuccessful();
        $this->artisan('providers:shift-reminders')->assertSuccessful();

        Notification::assertSentToTimes($this->provider->user, ProviderShiftReminderNotification::class, 1);
    }

    public function test_no_reminder_when_shifts_are_off_or_the_provider_is_already_online(): void
    {
        Notification::fake();
        Cache::flush();
        $this->choose($this->shift('Morning', '08:00', '14:00'));
        Carbon::setTestNow($this->at('07:50'));

        $this->artisan('providers:shift-reminders')->assertSuccessful();          // mode off
        $this->mode('reminder');
        $this->provider->update(['is_online' => true]);
        $this->artisan('providers:shift-reminders')->assertSuccessful();          // already online

        Notification::assertNothingSent();
    }

    // ------------------------------------------------------------------ admin screen

    public function test_only_a_super_admin_can_open_the_admin_screen(): void
    {
        $editor = $this->makeUserWithPermission('settings.manage', 'global');
        Livewire::actingAs($editor)->test(Availability::class)->assertForbidden();

        $this->actingAs($this->makeSuperAdmin())->get(route('admin.providers.availability'))->assertOk()->assertSee('Availability');
    }

    public function test_the_admin_saves_settings_and_manages_shifts_with_an_audit_trail(): void
    {
        $admin = $this->makeSuperAdmin();
        $this->shift('Existing', '08:00', '14:00');

        Livewire::actingAs($admin)->test(Availability::class)
            ->set('staleMinutes', '15')->set('mode', 'required')->set('reminderMinutes', '5')->set('graceMinutes', '20')
            ->set('requiredMinPercent', '0')
            ->call('saveSettings')->assertHasNoErrors();

        $this->assertSame(15, ShiftSchedule::staleMinutes());
        $this->assertSame('required', ShiftSchedule::mode());
        $this->assertSame(5, ShiftSchedule::reminderMinutes());
        $this->assertSame(20, ShiftSchedule::graceMinutes());

        Livewire::actingAs($admin)->test(Availability::class)
            ->set('shiftName', 'Morning')->set('shiftStart', '08:00')->set('shiftEnd', '14:00')->set('shiftDays', ['1', '2', '3'])
            ->call('saveShift')->assertHasNoErrors();

        $shift = ProviderShift::where('name', 'Morning')->sole();
        $this->assertSame([1, 2, 3], $shift->days);
        $this->assertTrue(ActivityLog::where('description', 'like', 'Provider shift created: Morning%')->exists());

        Livewire::actingAs($admin)->test(Availability::class)
            ->call('editShift', $shift->id)->set('shiftEnd', '15:00')->call('saveShift')->assertHasNoErrors();
        $this->assertSame('15:00', $shift->fresh()->end_time);

        Livewire::actingAs($admin)->test(Availability::class)->call('deleteShift', $shift->id);
        $this->assertSame(1, ProviderShift::count());   // only the pre-existing shift is left
    }

    public function test_required_mode_will_not_save_without_an_active_shift(): void
    {
        Livewire::actingAs($this->makeSuperAdmin())->test(Availability::class)
            ->set('mode', 'required')->set('requiredMinPercent', '0')
            ->call('saveSettings')->assertHasErrors('mode');

        $this->assertSame('off', ShiftSchedule::mode());
    }

    public function test_required_mode_will_not_save_until_enough_providers_have_chosen_a_shift(): void
    {
        $shift = $this->shift('Morning', '08:00', '14:00');
        $admin = $this->makeSuperAdmin();

        // The one approved provider has not chosen a shift: 0% < 80%.
        Livewire::actingAs($admin)->test(Availability::class)
            ->set('mode', 'required')->set('requiredMinPercent', '80')
            ->call('saveSettings')->assertHasErrors('mode');
        $this->assertSame('off', ShiftSchedule::mode());
        $this->assertSame(0, ShiftSchedule::readiness()['percent']);

        // Once they choose one, readiness is 100% and it saves.
        $this->choose($shift);
        $this->assertSame(100, ShiftSchedule::readiness()['percent']);

        Livewire::actingAs($admin)->test(Availability::class)
            ->set('mode', 'required')->set('requiredMinPercent', '80')
            ->call('saveSettings')->assertHasNoErrors();
        $this->assertSame('required', ShiftSchedule::mode());
    }

    public function test_the_readiness_check_only_applies_when_switching_into_required(): void
    {
        $this->shift('Morning', '08:00', '14:00');
        Setting::set(ShiftSchedule::MODE, 'required');

        // Already required: saving other settings must not be blocked by the readiness check.
        Livewire::actingAs($this->makeSuperAdmin())->test(Availability::class)
            ->set('graceMinutes', '30')->call('saveSettings')->assertHasNoErrors();

        $this->assertSame(30, ShiftSchedule::graceMinutes());
    }

    public function test_inactive_or_unapproved_providers_are_not_counted_in_readiness(): void
    {
        $this->provider->update(['kyc_status' => 'pending']);

        $this->assertSame(0, ShiftSchedule::readiness()['total']);
        $this->assertSame(100, ShiftSchedule::readiness()['percent']);
    }

    public function test_the_admin_screen_rejects_bad_times_and_out_of_range_numbers(): void
    {
        Livewire::actingAs($this->makeSuperAdmin())->test(Availability::class)
            ->set('staleMinutes', '1')->set('mode', 'sometimes')->call('saveSettings')
            ->assertHasErrors(['staleMinutes', 'mode']);

        Livewire::actingAs($this->makeSuperAdmin())->test(Availability::class)
            ->set('shiftName', 'X')->set('shiftStart', '25:00')->set('shiftEnd', '08:00')->call('saveShift')
            ->assertHasErrors(['shiftStart']);

        Livewire::actingAs($this->makeSuperAdmin())->test(Availability::class)
            ->set('shiftName', 'X')->set('shiftStart', '08:00')->set('shiftEnd', '08:00')->call('saveShift')
            ->assertHasErrors(['shiftEnd']);
    }

    // ------------------------------------------------------------------ provider screen + dashboard strip

    public function test_a_provider_chooses_and_drops_shifts_and_cannot_choose_a_hidden_one(): void
    {
        $morning = $this->shift('Morning', '08:00', '14:00');
        $hidden = $this->shift('Hidden', '14:00', '18:00', null, false);

        Livewire::actingAs($this->provider->user)->test(Shifts::class)
            ->call('toggle', $morning->id)->assertSee('Added: Morning.')
            ->call('toggle', $morning->id)->assertSee('Removed: Morning.');

        $this->assertSame(0, $this->provider->shifts()->count());

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::actingAs($this->provider->user)->test(Shifts::class)->call('toggle', $hidden->id);
    }

    public function test_the_dashboard_strip_totals_todays_completed_jobs_and_earnings(): void
    {
        $scenario = $this->makeBookingScenario('completed');
        $booking = $scenario['booking'];
        $booking->update(['provider_id' => $this->provider->id, 'completed_at' => now()]);
        Commission::create(['booking_id' => $booking->id, 'provider_commission' => 321.50, 'franchise_commission' => 0, 'platform_commission' => 0]);

        $old = $this->makeBookingScenario('completed')['booking'];
        $old->update(['provider_id' => $this->provider->id, 'completed_at' => now()->subDays(3)]);
        Commission::create(['booking_id' => $old->id, 'provider_commission' => 999, 'franchise_commission' => 0, 'platform_commission' => 0]);

        Livewire::actingAs($this->provider->user)->test(Dashboard::class)
            ->assertSee('Jobs done today')
            ->assertSee('₹321.50')
            ->assertDontSee('₹999.00');
    }

    public function test_the_shift_tile_only_appears_when_shifts_are_on(): void
    {
        $this->choose($this->shift('Morning', '08:00', '14:00'));
        Carbon::setTestNow($this->at('09:00'));

        Livewire::actingAs($this->provider->user)->test(Dashboard::class)->assertDontSee('until');

        $this->mode('reminder');
        Livewire::actingAs($this->provider->user)->test(Dashboard::class)->assertSee('Morning')->assertSee('until');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
