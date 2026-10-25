<?php

namespace Tests\Feature\Dispatch;

use App\Jobs\BundleConsolidationJob;
use App\Jobs\ConsolidationOfferTimeoutJob;
use App\Jobs\MarketplaceDispatchJob;
use App\Jobs\ParcelDispatchJob;
use App\Jobs\ServiceMatchingJob;
use App\Jobs\TaxiDispatchJob;
use App\Notifications\Channels\PushChannel;
use App\Notifications\ProviderJobOfferNotification;
use App\Notifications\RiderParcelOfferNotification;
use App\Services\DispatchService;
use App\Support\DispatchQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Job offers on their own queue (DISPATCH_QUEUE). Unset must change nothing: jobs and the offer push keep no
 * explicit queue, so they land on the connection's own queue exactly as before. Set, every dispatch round
 * (including each job's own re-dispatch) and the offer PUSH go to it, while offer mail stays where it was.
 */
class DispatchQueueTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    /** @return list<object> one of every dispatch job */
    private function dispatchJobs(): array
    {
        return [
            new ServiceMatchingJob(1),
            new BundleConsolidationJob(1),
            new ConsolidationOfferTimeoutJob(1, 1),
            new ParcelDispatchJob(1),
            new TaxiDispatchJob(1),
            new MarketplaceDispatchJob(1),
        ];
    }

    public function test_unset_leaves_every_dispatch_job_and_the_offer_push_on_the_default_queue(): void
    {
        config(['queue.dispatch_queue' => null]);

        $this->assertNull(DispatchQueue::name());
        foreach ($this->dispatchJobs() as $job) {
            $this->assertNull($job->queue, $job::class.' must keep no explicit queue when unset');
        }

        $scenario = $this->makeBookingScenario();
        $push = (new ProviderJobOfferNotification($scenario['booking'], [PushChannel::class]))->viaQueues();
        $this->assertSame([PushChannel::class => null], $push);
    }

    public function test_a_blank_setting_counts_as_unset(): void
    {
        config(['queue.dispatch_queue' => '  ']);

        $this->assertNull(DispatchQueue::name());
        $this->assertNull((new ServiceMatchingJob(1))->queue);
    }

    public function test_set_puts_every_dispatch_job_on_the_dispatch_queue(): void
    {
        config(['queue.dispatch_queue' => 'dispatch']);

        foreach ($this->dispatchJobs() as $job) {
            $this->assertSame('dispatch', $job->queue, $job::class);
        }
    }

    public function test_a_matching_round_re_queues_itself_on_the_dispatch_queue(): void
    {
        config(['queue.dispatch_queue' => 'dispatch']);
        Queue::fake();
        $scenario = $this->makeBookingScenario();

        // No eligible provider in this scenario: the round finds nobody and re-queues round 2.
        (new ServiceMatchingJob($scenario['booking']->id))->handle(app(DispatchService::class));

        Queue::assertPushedOn('dispatch', ServiceMatchingJob::class, fn ($job) => $job->round === 2);
    }

    public function test_the_offer_push_goes_to_the_dispatch_queue_and_offer_mail_does_not(): void
    {
        config(['queue.dispatch_queue' => 'dispatch']);
        Queue::fake();
        $scenario = $this->makeBookingScenario();

        $scenario['provider']->user->notify(new ProviderJobOfferNotification($scenario['booking'], [PushChannel::class, 'mail']));

        Queue::assertPushedOn('dispatch', SendQueuedNotifications::class, fn ($job) => $job->channels === [PushChannel::class]);
        Queue::assertPushed(SendQueuedNotifications::class, fn ($job) => $job->channels === ['mail'] && $job->queue === null);
    }

    public function test_the_rider_parcel_offer_push_goes_to_the_dispatch_queue(): void
    {
        config(['queue.dispatch_queue' => 'dispatch']);

        $this->assertSame([PushChannel::class => 'dispatch'], (new RiderParcelOfferNotification(new \App\Models\ParcelOrder))->viaQueues());
    }

    /**
     * Rounds pick candidates and create offers outside the booking lock, so they rely on running one at a time
     * (as on the old single worker). The supervisor file must keep exactly one dispatch process and keep
     * `dispatch` off the default worker, and keep the group name the deploy runbook restarts.
     */
    public function test_the_supervisor_file_keeps_one_dispatch_worker_and_the_runbook_group_name(): void
    {
        $conf = file_get_contents(base_path('deploy/supervisor/onecallfix-worker.conf'));
        $sections = [];
        foreach (preg_split('/^\[/m', $conf) as $chunk) {
            if (preg_match('/^([^\]]+)\]/', $chunk, $m)) {
                $sections[$m[1]] = $chunk;
            }
        }

        $this->assertMatchesRegularExpression('/^programs=onecallfix-default,onecallfix-dispatch$/m', $sections['group:onecallfix-worker'] ?? '');
        $this->assertMatchesRegularExpression('/^numprocs=1$/m', $sections['program:onecallfix-dispatch'] ?? '');
        $this->assertMatchesRegularExpression('/^command=.*queue:work database --queue=dispatch /m', $sections['program:onecallfix-dispatch']);
        $this->assertDoesNotMatchRegularExpression('/^command=.*--queue=/m', $sections['program:onecallfix-default'] ?? '');
    }
}
