<?php

namespace Tests\Feature\Dispatch;

use App\Jobs\ServiceMatchingJob;
use App\Livewire\Providers\Availability;
use App\Models\Provider;
use App\Models\Setting;
use App\Notifications\Channels\PushChannel;
use App\Notifications\ProviderJobOfferNotification;
use App\Notifications\ProviderWakeUpNotification;
use App\Services\DispatchService;
use App\Services\Providers\OfflineWakeUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-20: a "book now" job that finds nobody online wakes the offline providers who could take it — one
 * loud push each, once per job, never at night, switchable on Admin → Availability & shifts.
 */
class OfflineWakeUpTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
        Setting::set('notifications.channels', 'mail,push');
        Setting::set(OfflineWakeUp::QUIET_FROM, '00:00');
        Setting::set(OfflineWakeUp::QUIET_UNTIL, '00:00'); // no night in these tests unless one sets it
    }

    /** A searching job and its provider: approved, has the skill, phone near the job, but OFFLINE (app closed). */
    private function nobodyOnline(): array
    {
        $s = $this->makeBookingScenario('searching_provider');
        $s['provider']->update([
            'skills' => [$s['category']->id], 'current_lat' => 1.001, 'current_lng' => 1.001,
            'location_updated_at' => now()->subHours(6), 'is_online' => false,
        ]);
        $s['provider'] = $s['provider']->fresh();

        return $s;
    }

    private function round(array $s, int $round = 1): void
    {
        (new ServiceMatchingJob($s['booking']->id, $round))->handle(app(DispatchService::class));
    }

    public function test_nobody_online_wakes_the_offline_provider_once_per_job(): void
    {
        $s = $this->nobodyOnline();

        $this->round($s, 1);
        $this->round($s, 2);
        $this->round($s, 3);

        Notification::assertSentToTimes($s['provider']->user, ProviderWakeUpNotification::class, 1);
        Notification::assertSentTo($s['provider']->user, ProviderWakeUpNotification::class, function ($n, $channels) {
            return $channels === [PushChannel::class] && $n->pushApp() === 'partner' && $n->pushKind() === 'offer';
        });
        Notification::assertNotSentTo($s['provider']->user, ProviderJobOfferNotification::class);
        $this->assertTrue($s['booking']->statusHistory()->where('note', 'like', '%alert sent to 1 offline provider%')->exists());

        $push = (new ProviderWakeUpNotification($s['booking']->fresh(), []))->toPush($s['provider']->user);
        $this->assertStringContainsString('go online to take it', $push['body']);
    }

    public function test_once_online_the_woken_provider_gets_the_job_offer(): void
    {
        $s = $this->nobodyOnline();
        $this->round($s, 1);

        // The provider opens the app and goes online (fresh location).
        $s['provider']->update(['is_online' => true, 'location_updated_at' => now()]);
        $this->round($s, 2);

        Notification::assertSentTo($s['provider']->user, ProviderJobOfferNotification::class);
        $this->assertDatabaseHas('dispatch_attempts', ['booking_id' => $s['booking']->id, 'provider_id' => $s['provider']->id]);
    }

    public function test_when_someone_is_online_nobody_is_woken(): void
    {
        $s = $this->nobodyOnline();
        $s['provider']->update(['is_online' => true, 'location_updated_at' => now()]);

        $this->round($s, 1);

        Notification::assertSentTo($s['provider']->user, ProviderJobOfferNotification::class);
        Notification::assertNotSentTo($s['provider']->user, ProviderWakeUpNotification::class);
    }

    public function test_only_providers_who_could_take_the_job_are_woken(): void
    {
        $s = $this->nobodyOnline();
        $noSkill = $this->makeProviderIn($s['franchise'], $s['zone']);
        $noSkill->update(['is_online' => false, 'skills' => [], 'current_lat' => 1.0, 'current_lng' => 1.0]);
        [, , $otherFranchise, $otherZone] = $this->makeFranchiseTree();
        $elsewhere = $this->makeProviderIn($otherFranchise, $otherZone);
        $elsewhere->update(['is_online' => false, 'skills' => [$s['category']->id], 'current_lat' => 1.0, 'current_lng' => 1.0]);
        $notApproved = $this->makeProviderIn($s['franchise'], $s['zone']);
        $notApproved->update(['is_online' => false, 'kyc_status' => 'pending', 'skills' => [$s['category']->id], 'current_lat' => 1.0, 'current_lng' => 1.0]);

        $this->round($s, 1);

        Notification::assertSentTo($s['provider']->user, ProviderWakeUpNotification::class);
        foreach ([$noSkill, $elsewhere, $notApproved] as $p) {
            Notification::assertNotSentTo(Provider::find($p->id)->user, ProviderWakeUpNotification::class);
        }
    }

    public function test_no_alerts_at_night_in_the_city_time(): void
    {
        $s = $this->nobodyOnline();
        Setting::set(OfflineWakeUp::QUIET_FROM, '22:00');
        Setting::set(OfflineWakeUp::QUIET_UNTIL, '07:00');
        $tz = app(\App\Services\TimezoneResolver::class)->timezoneFor($s['franchise']);

        $this->travelTo(Carbon::parse('2026-11-20 23:30', $tz));
        $this->round($s, 1);
        Notification::assertNotSentTo($s['provider']->user, ProviderWakeUpNotification::class);

        $wake = app(OfflineWakeUp::class);
        $this->assertTrue($wake->isQuiet($s['franchise'], Carbon::parse('2026-11-21 06:59', $tz)));
        $this->assertFalse($wake->isQuiet($s['franchise'], Carbon::parse('2026-11-21 07:00', $tz)));
        $this->assertFalse($wake->isQuiet($s['franchise'], Carbon::parse('2026-11-21 14:00', $tz)));
    }

    public function test_switched_off_or_a_scheduled_job_wakes_nobody(): void
    {
        $s = $this->nobodyOnline();
        Setting::set(OfflineWakeUp::ENABLED, '0');
        $this->round($s, 1);
        Notification::assertNotSentTo($s['provider']->user, ProviderWakeUpNotification::class);

        Setting::set(OfflineWakeUp::ENABLED, '1');
        $later = $this->nobodyOnline();
        $later['booking']->update(['scheduled_at' => now()->addDay()]);
        $this->assertSame(0, app(OfflineWakeUp::class)->send($later['booking']->fresh()));
    }

    public function test_super_admin_sets_the_switch_and_the_night_hours(): void
    {
        Livewire::actingAs($this->makeSuperAdmin())->test(Availability::class)
            ->assertSeeHtml('data-testid="wake-offline"')
            ->set('wakeOn', false)->set('quietFrom', '21:30')->set('quietUntil', '06:30')
            ->call('saveSettings')->assertHasNoErrors();

        $this->assertFalse(OfflineWakeUp::enabled());
        $this->assertSame('21:30', OfflineWakeUp::quietFrom());
        $this->assertSame('06:30', OfflineWakeUp::quietUntil());

        Livewire::actingAs($this->makeSuperAdmin())->test(Availability::class)
            ->set('quietFrom', '25:00')->call('saveSettings')->assertHasErrors('quietFrom');
    }
}
