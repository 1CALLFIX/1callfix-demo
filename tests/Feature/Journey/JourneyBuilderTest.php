<?php

namespace Tests\Feature\Journey;

use App\Support\Journey\JourneyBuilder;
use App\Support\Journey\JourneyCatalog;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * REF 1CF-JOURNEY-001 — how every span of every module reads as a journey: the happy path, pending
 * (scheduled vs immediate), cancellation (who/why/fee/refund), the hold/spares lane, disputes,
 * re-dispatch, optional steps, and that every real status of every module is covered.
 */
class JourneyBuilderTest extends TestCase
{
    private function h(string $status, ?string $note = null, int $minute = 0): object
    {
        return (object) ['status' => $status, 'note' => $note, 'changed_at' => Carbon::parse('2026-10-01 10:00:00')->addMinutes($minute)];
    }

    /** @return array<string,string> step key => state */
    private function states(array $journey): array
    {
        return collect($journey['steps'])->mapWithKeys(fn ($s) => [$s['key'] => $s['state']])->all();
    }

    // ---------------------------------------------------------------- service: happy path

    public function test_service_happy_path_reads_step_by_step(): void
    {
        $history = [$this->h('pending', null, 0), $this->h('searching_provider', null, 1), $this->h('assigned', null, 2)];
        $j = JourneyBuilder::build('service', 'assigned', $history);

        $this->assertSame(['pending' => 'done', 'searching_provider' => 'done', 'assigned' => 'current', 'provider_en_route' => 'upcoming', 'in_progress' => 'upcoming', 'completed' => 'upcoming'], $this->states($j));
        $this->assertSame('Professional assigned', $j['headline']);
        $this->assertSame('blue', $j['tone']);
        $this->assertGreaterThan(30, $j['progress']);
        $this->assertLessThan(100, $j['progress']);
        $this->assertNull($j['terminal']);
    }

    public function test_completed_job_is_one_hundred_percent_and_green(): void
    {
        $history = array_map(fn ($s, $i) => $this->h($s, null, $i), ['pending', 'searching_provider', 'assigned', 'provider_en_route', 'in_progress', 'completed'], range(0, 5));
        $j = JourneyBuilder::build('service', 'completed', $history);

        $this->assertSame(100, $j['progress']);
        $this->assertSame('green', $j['tone']);
        $this->assertSame('Completed', $j['headline']);
        $this->assertSame(array_fill_keys(array_keys($this->states($j)), 'done'), $this->states($j));
    }

    public function test_the_professional_is_named_when_known_and_generic_otherwise(): void
    {
        $history = [$this->h('assigned')];

        $named = JourneyBuilder::build('service', 'assigned', $history, ['provider_name' => 'Ravi Kumar']);
        $generic = JourneyBuilder::build('service', 'assigned', $history);

        $this->assertSame('Ravi Kumar is assigned to your job.', $named['headline'] === 'Professional assigned' ? $named['hint'] : '');
        $this->assertSame('Your professional is assigned to your job.', $generic['hint']);
    }

    // ---------------------------------------------------------------- pending (two real meanings)

    public function test_pending_for_a_scheduled_booking_waits_for_payment_and_says_so(): void
    {
        $j = JourneyBuilder::build('service', 'pending', [$this->h('pending')], [
            'scheduled_label' => 'Sun 5 Oct, 10:00 AM',
            'payment' => ['status' => 'pending', 'method' => 'online', 'amount_label' => '₹499.00'],
        ]);

        $this->assertStringContainsString('Scheduled for Sun 5 Oct, 10:00 AM', $j['hint']);
        $this->assertStringContainsString('as soon as your payment is confirmed', $j['hint']);
        $this->assertSame(['Scheduled for Sun 5 Oct, 10:00 AM', 'Payment pending'], array_column($j['chips'], 'text'));
    }

    public function test_pending_for_an_immediate_booking_is_just_received(): void
    {
        $j = JourneyBuilder::build('service', 'pending', [$this->h('pending')], ['payment' => ['status' => 'pending', 'method' => 'cash']]);

        $this->assertSame('We received your booking.', $j['hint']);
        $this->assertSame(['Pay in cash after the job'], array_column($j['chips'], 'text'));
    }

    public function test_paid_scheduled_pending_says_dispatch_is_about_to_start(): void
    {
        $j = JourneyBuilder::build('service', 'pending', [$this->h('pending')], [
            'scheduled_label' => 'Mon 6 Oct, 9:00 AM',
            'payment' => ['status' => 'paid', 'method' => 'wallet', 'amount_label' => '₹1.00'],
        ]);

        $this->assertStringContainsString("about to start looking", $j['hint']);
        $this->assertContains('Paid ₹1.00', array_column($j['chips'], 'text'));
    }

    public function test_assigned_and_en_route_mention_the_schedule(): void
    {
        $j = JourneyBuilder::build('service', 'provider_en_route', [$this->h('assigned'), $this->h('provider_en_route', null, 5)], ['scheduled_label' => 'Mon 6 Oct, 9:00 AM']);

        $assigned = collect($j['steps'])->firstWhere('key', 'assigned');
        $this->assertStringContainsString('Visit scheduled for Mon 6 Oct, 9:00 AM.', $assigned['hint']);
    }

    // ---------------------------------------------------------------- cancel

    public function test_cancel_by_the_platform_when_no_provider_found_explains_fee_and_wallet_refund(): void
    {
        $history = [$this->h('searching_provider'), $this->h('cancelled', 'Cancelled by admin: Platform dispatch failure — no provider could be found within the allotted dispatch window.', 11)];
        $j = JourneyBuilder::build('service', 'cancelled', $history, [
            'cancel' => [
                'reason' => 'Platform dispatch failure — no provider could be found within the allotted dispatch window.',
                'fee_label' => null,
                'refund_label' => '₹1.00 refunded to your 1CallFix wallet.',
            ],
            'payment' => ['status' => 'refunded', 'method' => 'online'],
        ]);

        $this->assertSame('Cancelled', $j['headline']);
        $this->assertSame('red', $j['tone']);
        $this->assertSame(
            ["We couldn't find a professional within the search window.", 'No cancellation fee.', '₹1.00 refunded to your 1CallFix wallet.'],
            $j['terminal']['details']
        );
        // Steps never reached are dropped after a cancellation.
        $this->assertSame(['pending', 'searching_provider'], array_column($j['steps'], 'key'));
    }

    public function test_customer_cancel_after_assignment_shows_the_fee(): void
    {
        $history = [$this->h('pending'), $this->h('searching_provider', null, 1), $this->h('assigned', null, 2), $this->h('cancelled', 'Cancelled by admin: Cancelled by customer from the web app (cancellation fee: 99)', 9)];
        $j = JourneyBuilder::build('service', 'cancelled', $history, ['cancel' => ['reason' => 'Cancelled by customer from the web app', 'fee_label' => '₹99.00', 'refund_label' => '₹400.00 refunded to your original payment method (usually within 3–5 working days).']]);

        $this->assertSame(['You cancelled this booking.', 'Cancellation fee: ₹99.00.', '₹400.00 refunded to your original payment method (usually within 3–5 working days).'], $j['terminal']['details']);
        $this->assertSame('done', $this->states($j)['assigned']);
    }

    public function test_cancel_reason_falls_back_to_the_history_note(): void
    {
        $j = JourneyBuilder::build('service', 'cancelled', [$this->h('assigned'), $this->h('cancelled', 'Cancelled by admin: Customer unreachable (cancellation fee: 50)', 3)]);

        $this->assertSame('Customer unreachable.', $j['terminal']['details'][0]);
    }

    public function test_dispute_is_an_amber_terminal(): void
    {
        $j = JourneyBuilder::build('service', 'disputed', [$this->h('assigned'), $this->h('in_progress', null, 5), $this->h('disputed', null, 9)]);

        $this->assertSame('Under review', $j['headline']);
        $this->assertSame('amber', $j['tone']);
        $this->assertSame('amber', $j['terminal']['tone']);
    }

    // ---------------------------------------------------------------- hold / spares lane

    private function heldForSpares(): array
    {
        return [
            $this->h('assigned', null, 0), $this->h('in_progress', null, 5),
            $this->h('on_hold', 'Hold reason: awaiting_spares — Provider is waiting for spare parts', 20),
        ];
    }

    public function test_job_held_for_spares_pauses_in_progress_and_opens_an_episode(): void
    {
        $j = JourneyBuilder::build('service', 'on_hold', $this->heldForSpares());

        $this->assertTrue($j['paused']);
        $this->assertSame('paused', $this->states($j)['in_progress']);
        $this->assertSame('Job on hold', $j['headline']);
        $this->assertSame('amber', $j['tone']);

        $episode = collect($j['steps'])->firstWhere('key', 'in_progress')['episodes'][0];
        $this->assertTrue($episode['open']);
        $this->assertSame(['Waiting for spare parts', 'Spares available', 'Work resumed'], array_column($episode['mini'], 'label'));
        $this->assertSame(['current', 'upcoming', 'upcoming'], array_column($episode['mini'], 'state'));
    }

    public function test_a_hold_that_ends_in_a_hand_over_reads_as_professional_replaced(): void
    {
        $h = [
            $this->h('assigned', null, 0), $this->h('in_progress', null, 5),
            $this->h('on_hold', 'Hold reason: provider_unresponsive — Customer reports the professional left the site', 20),
            $this->h('assigned', 'Job reassigned after the previous professional left (#1 → #2)', 30),
        ];

        $j = JourneyBuilder::build('service', 'assigned', $h);
        $ep = collect($j['steps'])->firstWhere('key', 'in_progress')['episodes'][0];

        $this->assertFalse($j['paused']);
        $this->assertFalse($ep['open']);
        $this->assertSame(['Looking into a delay', 'Professional replaced'], array_column($ep['mini'], 'label'));
        $this->assertSame(['done', 'done'], array_column($ep['mini'], 'state'));
    }

    public function test_spares_available_then_resume_then_complete_closes_the_loop(): void
    {
        $h = $this->heldForSpares();
        $h[] = $this->h('on_hold', 'Spares available', 40);

        $ready = JourneyBuilder::build('service', 'on_hold', $h);
        $this->assertSame('Spares available', $ready['headline']);
        $this->assertSame(['done', 'current', 'upcoming'], array_column(collect($ready['steps'])->firstWhere('key', 'in_progress')['episodes'][0]['mini'], 'state'));

        $h[] = $this->h('in_progress', 'Resumed from hold (was: awaiting_spares) — Spares in hand', 50);
        $resumed = JourneyBuilder::build('service', 'in_progress', $h);
        $this->assertFalse($resumed['paused']);
        $this->assertSame('current', $this->states($resumed)['in_progress']);
        $ep = collect($resumed['steps'])->firstWhere('key', 'in_progress')['episodes'][0];
        $this->assertFalse($ep['open']);
        $this->assertSame(['done', 'done', 'done'], array_column($ep['mini'], 'state'));

        $h[] = $this->h('completed', null, 70);
        $done = JourneyBuilder::build('service', 'completed', $h);
        $this->assertSame(100, $done['progress']);
        $this->assertCount(1, collect($done['steps'])->firstWhere('key', 'in_progress')['episodes'], 'the hold stays on the record');
    }

    public function test_a_hold_for_another_reason_has_no_spares_step(): void
    {
        $j = JourneyBuilder::build('service', 'on_hold', [$this->h('in_progress'), $this->h('on_hold', 'Hold reason: awaiting_customer_approval — Extra work proposed', 9)]);

        $ep = collect($j['steps'])->firstWhere('key', 'in_progress')['episodes'][0];
        $this->assertSame(['Waiting for your approval', 'Work resumed'], array_column($ep['mini'], 'label'));
    }

    public function test_approved_extra_work_reads_work_approved_not_work_resumed(): void
    {
        $h = [$this->h('in_progress'), $this->h('on_hold', 'Hold reason: awaiting_customer_approval — Extra work proposed', 9)];
        $h[] = $this->h('in_progress', 'Resumed from hold (was: awaiting_customer_approval) — Extra work approved: Pipe (₹500)', 12);
        $ep = collect(JourneyBuilder::build('service', 'in_progress', $h)['steps'])->firstWhere('key', 'in_progress')['episodes'][0];
        $this->assertSame(['Waiting for your approval', 'Work approved'], array_column($ep['mini'], 'label'));

        $h[2] = $this->h('in_progress', 'Resumed from hold (was: awaiting_customer_approval) — Extra work declined: Pipe', 12);
        $ep = collect(JourneyBuilder::build('service', 'in_progress', $h)['steps'])->firstWhere('key', 'in_progress')['episodes'][0];
        $this->assertSame('Extra work declined', $ep['mini'][1]['label']);
    }

    public function test_two_separate_holds_are_two_episodes(): void
    {
        $h = $this->heldForSpares();
        $h[] = $this->h('on_hold', 'Spares available', 30);
        $h[] = $this->h('in_progress', 'Resumed from hold (was: awaiting_spares)', 35);
        $h[] = $this->h('on_hold', 'Hold reason: awaiting_spares — second part', 60);

        $j = JourneyBuilder::build('service', 'on_hold', $h);
        $eps = collect($j['steps'])->firstWhere('key', 'in_progress')['episodes'];

        $this->assertCount(2, $eps);
        $this->assertFalse($eps[0]['open']);
        $this->assertTrue($eps[1]['open']);
    }

    // ---------------------------------------------------------------- re-dispatch, ordering, optional steps

    public function test_a_provider_unassigned_for_re_dispatch_shows_assigned_as_upcoming_with_no_time(): void
    {
        $j = JourneyBuilder::build('service', 'searching_provider', [$this->h('searching_provider'), $this->h('assigned', null, 3), $this->h('searching_provider', 'Provider unassigned', 8)]);

        $assigned = collect($j['steps'])->firstWhere('key', 'assigned');
        $this->assertSame('upcoming', $assigned['state']);
        $this->assertNull($assigned['at']);
    }

    public function test_history_loaded_newest_first_gives_the_same_journey(): void
    {
        $asc = [$this->h('pending', null, 0), $this->h('searching_provider', null, 1), $this->h('assigned', null, 2)];

        $this->assertSame(
            JourneyBuilder::build('service', 'assigned', $asc)['steps'],
            JourneyBuilder::build('service', 'assigned', array_reverse($asc))['steps']
        );
    }

    public function test_the_optional_out_for_delivery_step_only_appears_when_reached(): void
    {
        $without = JourneyBuilder::build('food', 'ready', [$this->h('pending'), $this->h('accepted', null, 1), $this->h('preparing', null, 2), $this->h('ready', null, 3)]);
        $this->assertNotContains('out_for_delivery', array_column($without['steps'], 'key'));

        $with = JourneyBuilder::build('food', 'out_for_delivery', [$this->h('pending'), $this->h('accepted', null, 1), $this->h('preparing', null, 2), $this->h('ready', null, 3), $this->h('out_for_delivery', null, 4)]);
        $this->assertContains('out_for_delivery', array_column($with['steps'], 'key'));
        $this->assertSame('current', $this->states($with)['out_for_delivery']);
    }

    public function test_marketplace_modules_map_to_the_right_journey(): void
    {
        $this->assertSame('food', JourneyCatalog::journeyForMarketplaceModule('food'));
        foreach (['grocery', 'pharmacy', 'ecommerce', null] as $module) {
            $this->assertSame('retail', JourneyCatalog::journeyForMarketplaceModule($module));
        }
    }

    public function test_unknown_journey_returns_null_and_toapi_is_json_safe(): void
    {
        $this->assertNull(JourneyBuilder::build('nope', 'pending', []));

        $api = JourneyBuilder::toApi(JourneyBuilder::build('service', 'on_hold', $this->heldForSpares()));
        $this->assertIsString(collect($api['steps'])->firstWhere('key', 'in_progress')['at']);
        $this->assertJson(json_encode($api));
    }

    // ---------------------------------------------------------------- every module, every status

    /** @return array<string, array{0:string,1:array<int,string>}> journey => real statuses of that module's state machine */
    public static function moduleStatuses(): array
    {
        return [
            'service' => ['service', ['pending', 'searching_provider', 'assigned', 'provider_en_route', 'in_progress', 'on_hold', 'completed', 'cancelled', 'disputed']],
            'parcel' => ['parcel', ['pending', 'searching_worker', 'assigned', 'worker_en_route_pickup', 'picked_up', 'en_route_dropoff', 'delivered', 'cancelled', 'disputed']],
            'taxi' => ['taxi', ['requested', 'searching_driver', 'assigned', 'driver_en_route', 'trip_started', 'trip_completed', 'cancelled', 'disputed']],
            'food' => ['food', ['pending', 'accepted', 'preparing', 'ready', 'completed', 'cancelled']],
            'retail' => ['retail', ['pending', 'accepted', 'preparing', 'ready', 'completed', 'cancelled']],
            'hotel' => ['hotel', ['pending', 'confirmed', 'checked_in', 'checked_out', 'completed', 'cancelled']],
            'rental' => ['rental', ['pending', 'confirmed', 'picked_up', 'active', 'returned', 'completed', 'cancelled']],
            'property' => ['property', ['pending', 'confirmed', 'checked_in', 'completed', 'cancelled']],
        ];
    }

    #[DataProvider('moduleStatuses')]
    public function test_every_real_status_of_every_module_has_a_place_in_its_journey(string $journey, array $statuses): void
    {
        $def = JourneyCatalog::for($journey);
        $known = array_merge(array_keys($def['steps']), array_keys($def['terminals']), $def['holdable'] ? ['on_hold'] : []);

        foreach ($statuses as $status) {
            $this->assertContains($status, $known, "{$journey}: status '{$status}' is not covered by the journey");

            $j = JourneyBuilder::build($journey, $status, [$this->h($status)]);
            $this->assertNotNull($j, "{$journey}/{$status}");
            $this->assertNotSame('', $j['headline'], "{$journey}/{$status} headline");
        }
    }

    #[DataProvider('moduleStatuses')]
    public function test_walking_a_module_forward_marks_one_current_step_and_never_goes_backwards(string $journey, array $statuses): void
    {
        $def = JourneyCatalog::for($journey);
        $keys = array_keys($def['steps']);
        $required = array_values(array_filter($keys, fn ($k) => ! ($def['steps'][$k][2] ?? false)));
        $history = [];
        $lastProgress = -1;

        foreach ($required as $i => $key) {
            $history[] = $this->h($key, null, $i);
            $j = JourneyBuilder::build($journey, $key, $history);

            $states = array_column($j['steps'], 'state');
            $this->assertLessThanOrEqual(1, count(array_filter($states, fn ($s) => in_array($s, ['current', 'paused'], true))), "{$journey}/{$key}: at most one current step");
            $this->assertGreaterThanOrEqual($lastProgress, $j['progress'], "{$journey}/{$key}: progress never decreases");
            $lastProgress = $j['progress'];
        }

        $this->assertSame(100, $lastProgress, "{$journey}: the final step ends at 100%");
    }
}
