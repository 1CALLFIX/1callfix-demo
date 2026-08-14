<?php

namespace Tests\Feature\PerformanceCampaigns;

use App\Livewire\PerformanceCampaigns\Manage as PerformanceCampaignsManage;
use App\Models\Badge;
use App\Models\Booking;
use App\Models\PerformanceCampaign;
use App\Models\PerformanceCampaignParticipant;
use App\Models\WalletTransaction;
use App\Services\PerformanceCampaignService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Performance/Growth Campaign Engine (full-day EOD mission Phase 1). NOT
 * the notification broadcast engine (CampaignService/NotificationCampaign)
 * — this is a real incentive engine measuring actual completed-booking data
 * and paying through the existing Wallet/Loyalty/Badge rails. No invented
 * target/reward values anywhere in this suite — every threshold/reward
 * used below is a test fixture, not a business default.
 */
class PerformanceCampaignEngineTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function makeCampaign(array $overrides = []): PerformanceCampaign
    {
        return PerformanceCampaign::create(array_merge([
            'name' => 'Test Campaign', 'audience_type' => 'provider',
            'metric_key' => 'bookings_completed_count', 'scope_type' => 'global',
            'qualification_mode' => 'threshold', 'target_value' => 2,
            'reward_type' => 'wallet_credit', 'reward_value' => 100,
            'status' => 'draft',
        ], $overrides));
    }

    private function completeBooking(Booking $booking, float $price, \DateTimeInterface $completedAt): Booking
    {
        $booking->update(['status' => 'completed', 'price_final' => $price, 'completed_at' => $completedAt]);

        return $booking->fresh();
    }

    // ============================== Lifecycle ==============================

    public function test_draft_schedules_into_scheduled(): void
    {
        $campaign = $this->makeCampaign(['starts_at' => now(), 'ends_at' => now()->addDays(7)]);

        $result = app(PerformanceCampaignService::class)->schedule($campaign);

        $this->assertSame('scheduled', $result->status);
    }

    public function test_schedule_rejects_missing_dates(): void
    {
        $campaign = $this->makeCampaign();

        $this->expectException(\RuntimeException::class);
        app(PerformanceCampaignService::class)->schedule($campaign);
    }

    public function test_schedule_rejects_end_before_start(): void
    {
        $campaign = $this->makeCampaign(['starts_at' => now()->addDay(), 'ends_at' => now()]);

        $this->expectException(\RuntimeException::class);
        app(PerformanceCampaignService::class)->schedule($campaign);
    }

    public function test_full_lifecycle_through_review_and_approval(): void
    {
        $campaign = $this->makeCampaign(['starts_at' => now(), 'ends_at' => now()->addDays(7)]);
        $service = app(PerformanceCampaignService::class);

        $campaign = $service->schedule($campaign);
        $campaign = $service->activate($campaign);
        $this->assertSame('active', $campaign->status);

        $campaign = $service->pause($campaign);
        $this->assertSame('paused', $campaign->status);

        $campaign = $service->resume($campaign);
        $this->assertSame('active', $campaign->status);

        $campaign = $service->complete($campaign);
        $this->assertSame('completed', $campaign->status);

        $campaign = $service->submitForReview($campaign);
        $this->assertSame('under_review', $campaign->status);

        $campaign = $service->approve($campaign);
        $this->assertSame('approved', $campaign->status);
    }

    public function test_illegal_transition_throws(): void
    {
        $campaign = $this->makeCampaign();

        $this->expectException(\RuntimeException::class);
        app(PerformanceCampaignService::class)->approve($campaign);
    }

    public function test_under_review_can_reopen_to_active(): void
    {
        $campaign = $this->makeCampaign(['status' => 'under_review']);

        $result = app(PerformanceCampaignService::class)->reopen($campaign);

        $this->assertSame('active', $result->status);
    }

    public function test_cancel_from_draft(): void
    {
        $campaign = $this->makeCampaign();

        $result = app(PerformanceCampaignService::class)->cancel($campaign);

        $this->assertSame('cancelled', $result->status);
    }

    public function test_cancelled_is_terminal(): void
    {
        $campaign = $this->makeCampaign(['status' => 'cancelled']);

        $this->expectException(\RuntimeException::class);
        app(PerformanceCampaignService::class)->activate($campaign);
    }

    // ============================== Progress / metrics ==============================

    public function test_refresh_progress_counts_completed_bookings_for_provider(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $provider = $this->makeProviderIn($franchise, $zone);

        for ($i = 0; $i < 3; $i++) {
            $booking = Booking::create([
                'code' => 'PC-'.$i, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
                'customer_id' => $customer->id, 'provider_id' => $provider->id, 'service_id' => $service->id,
                'address_id' => $address->id, 'status' => 'assigned', 'price_quoted' => 500,
                'payment_status' => 'pending', 'payment_method' => 'online',
            ]);
            $this->completeBooking($booking, 500, now());
        }

        $campaign = $this->makeCampaign(['audience_type' => 'provider', 'metric_key' => 'bookings_completed_count']);
        app(PerformanceCampaignService::class)->refreshProgress($campaign);

        $participant = PerformanceCampaignParticipant::where('performance_campaign_id', $campaign->id)
            ->where('participant_type', \App\Models\Provider::class)->where('participant_id', $provider->id)->first();

        $this->assertNotNull($participant);
        $this->assertSame(3.0, (float) $participant->metric_value);
    }

    public function test_refresh_progress_sums_revenue_generated(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $provider = $this->makeProviderIn($franchise, $zone);

        $b1 = Booking::create(['code' => 'PC-R1', 'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id, 'provider_id' => $provider->id, 'service_id' => $service->id, 'address_id' => $address->id, 'status' => 'assigned', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online']);
        $b2 = Booking::create(['code' => 'PC-R2', 'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id, 'provider_id' => $provider->id, 'service_id' => $service->id, 'address_id' => $address->id, 'status' => 'assigned', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online']);
        $this->completeBooking($b1, 300, now());
        $this->completeBooking($b2, 450, now());

        $campaign = $this->makeCampaign(['audience_type' => 'provider', 'metric_key' => 'revenue_generated', 'target_value' => 500]);
        app(PerformanceCampaignService::class)->refreshProgress($campaign);

        $participant = PerformanceCampaignParticipant::where('performance_campaign_id', $campaign->id)->where('participant_id', $provider->id)->first();
        $this->assertSame(750.0, (float) $participant->metric_value);
    }

    public function test_refresh_progress_excludes_non_completed_bookings(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $provider = $this->makeProviderIn($franchise, $zone);

        // Cancelled booking must never contribute — this is the
        // "cancelled/refunded transaction abuse" prevention, enforced by
        // the metric query itself (status = 'completed' only).
        Booking::create(['code' => 'PC-C1', 'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id, 'provider_id' => $provider->id, 'service_id' => $service->id, 'address_id' => $address->id, 'status' => 'cancelled', 'price_quoted' => 500, 'price_final' => 500, 'payment_status' => 'refunded', 'payment_method' => 'online']);

        $campaign = $this->makeCampaign(['audience_type' => 'provider']);
        app(PerformanceCampaignService::class)->refreshProgress($campaign);

        $participant = PerformanceCampaignParticipant::where('performance_campaign_id', $campaign->id)->where('participant_id', $provider->id)->first();
        $this->assertSame(0.0, (float) $participant->metric_value);
    }

    public function test_refresh_progress_excludes_bookings_outside_window(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $provider = $this->makeProviderIn($franchise, $zone);

        $old = Booking::create(['code' => 'PC-OLD', 'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id, 'provider_id' => $provider->id, 'service_id' => $service->id, 'address_id' => $address->id, 'status' => 'assigned', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online']);
        $this->completeBooking($old, 500, now()->subDays(60));

        $campaign = $this->makeCampaign(['audience_type' => 'provider', 'starts_at' => now()->subDays(7), 'ends_at' => now()]);
        app(PerformanceCampaignService::class)->refreshProgress($campaign);

        $participant = PerformanceCampaignParticipant::where('performance_campaign_id', $campaign->id)->where('participant_id', $provider->id)->first();
        $this->assertSame(0.0, (float) $participant->metric_value);
    }

    public function test_refresh_progress_excludes_out_of_scope_actors(): void
    {
        [, , $franchiseA, $zoneA] = $this->makeFranchiseTree();
        [, , $franchiseB, $zoneB] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $providerInA = $this->makeProviderIn($franchiseA, $zoneA);
        $providerInB = $this->makeProviderIn($franchiseB, $zoneB);
        $addressA = $this->makeAddress($customer, $franchiseA, $zoneA);
        $addressB = $this->makeAddress($customer, $franchiseB, $zoneB);

        $bookingA = Booking::create(['code' => 'PC-A', 'franchise_id' => $franchiseA->id, 'zone_id' => $zoneA->id, 'customer_id' => $customer->id, 'provider_id' => $providerInA->id, 'service_id' => $service->id, 'address_id' => $addressA->id, 'status' => 'assigned', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online']);
        $bookingB = Booking::create(['code' => 'PC-B', 'franchise_id' => $franchiseB->id, 'zone_id' => $zoneB->id, 'customer_id' => $customer->id, 'provider_id' => $providerInB->id, 'service_id' => $service->id, 'address_id' => $addressB->id, 'status' => 'assigned', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online']);
        $this->completeBooking($bookingA, 500, now());
        $this->completeBooking($bookingB, 500, now());

        // Scoped to franchise A only — provider B must never get a participant row at all.
        $campaign = $this->makeCampaign(['audience_type' => 'provider', 'scope_type' => 'franchise', 'scope_id' => $franchiseA->id]);
        app(PerformanceCampaignService::class)->refreshProgress($campaign);

        $this->assertNotNull(PerformanceCampaignParticipant::where('performance_campaign_id', $campaign->id)->where('participant_id', $providerInA->id)->first());
        $this->assertNull(PerformanceCampaignParticipant::where('performance_campaign_id', $campaign->id)->where('participant_id', $providerInB->id)->first());
    }

    public function test_refresh_progress_upserts_not_duplicates(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $campaign = $this->makeCampaign(['audience_type' => 'provider']);

        $service = app(PerformanceCampaignService::class);
        $service->refreshProgress($campaign);
        $service->refreshProgress($campaign);

        $count = PerformanceCampaignParticipant::where('performance_campaign_id', $campaign->id)->where('participant_id', $provider->id)->count();
        $this->assertSame(1, $count);
    }

    public function test_unsupported_metric_key_throws(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->makeProviderIn($franchise, $zone);
        $campaign = $this->makeCampaign(['audience_type' => 'provider', 'metric_key' => 'made_up_metric']);

        $this->expectException(\InvalidArgumentException::class);
        app(PerformanceCampaignService::class)->refreshProgress($campaign);
    }

    // ============================== Qualification / ranking ==============================

    public function test_threshold_qualification_marks_qualified_only(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $high = $this->makeProviderIn($franchise, $zone);
        $low = $this->makeProviderIn($franchise, $zone);

        foreach (range(1, 3) as $i) {
            $b = Booking::create(['code' => 'PC-H'.$i, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id, 'provider_id' => $high->id, 'service_id' => $service->id, 'address_id' => $address->id, 'status' => 'assigned', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online']);
            $this->completeBooking($b, 500, now());
        }
        $bLow = Booking::create(['code' => 'PC-L1', 'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id, 'provider_id' => $low->id, 'service_id' => $service->id, 'address_id' => $address->id, 'status' => 'assigned', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online']);
        $this->completeBooking($bLow, 500, now());

        $campaign = $this->makeCampaign(['audience_type' => 'provider', 'qualification_mode' => 'threshold', 'target_value' => 2]);
        app(PerformanceCampaignService::class)->refreshProgress($campaign);

        $highP = PerformanceCampaignParticipant::where('performance_campaign_id', $campaign->id)->where('participant_id', $high->id)->first();
        $lowP = PerformanceCampaignParticipant::where('performance_campaign_id', $campaign->id)->where('participant_id', $low->id)->first();

        $this->assertTrue($highP->qualified);
        $this->assertFalse($lowP->qualified);
    }

    public function test_top_n_qualification_handles_ties(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $providers = [$this->makeProviderIn($franchise, $zone), $this->makeProviderIn($franchise, $zone), $this->makeProviderIn($franchise, $zone)];

        // All three tie at exactly 1 completed booking each.
        foreach ($providers as $i => $p) {
            $b = Booking::create(['code' => 'PC-T'.$i, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id, 'provider_id' => $p->id, 'service_id' => $service->id, 'address_id' => $address->id, 'status' => 'assigned', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online']);
            $this->completeBooking($b, 500, now());
        }

        $campaign = $this->makeCampaign(['audience_type' => 'provider', 'qualification_mode' => 'top_n', 'target_value' => null, 'top_n' => 1]);
        app(PerformanceCampaignService::class)->refreshProgress($campaign);

        // Top-1 with a 3-way tie: every tied participant qualifies (standard competition ranking), not an arbitrary single pick.
        $qualifiedCount = PerformanceCampaignParticipant::where('performance_campaign_id', $campaign->id)->where('qualified', true)->count();
        $this->assertSame(3, $qualifiedCount);
    }

    // ============================== Disbursement ==============================

    public function test_disburse_requires_approved_status(): void
    {
        $campaign = $this->makeCampaign(['status' => 'draft']);

        $this->expectException(\RuntimeException::class);
        app(PerformanceCampaignService::class)->disburse($campaign);
    }

    public function test_disburse_credits_wallet_for_qualified_participant(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);

        $campaign = $this->makeCampaign(['audience_type' => 'provider', 'reward_type' => 'wallet_credit', 'reward_value' => 250, 'status' => 'approved']);
        PerformanceCampaignParticipant::create([
            'performance_campaign_id' => $campaign->id, 'participant_type' => \App\Models\Provider::class,
            'participant_id' => $provider->id, 'metric_value' => 5, 'rank' => 1, 'qualified' => true, 'reward_status' => 'pending',
        ]);

        app(PerformanceCampaignService::class)->disburse($campaign);

        $balance = app(WalletService::class)->balance($provider->user);
        $this->assertSame(250.0, $balance);

        $participant = PerformanceCampaignParticipant::where('performance_campaign_id', $campaign->id)->first();
        $this->assertSame('paid', $participant->reward_status);
        $this->assertNotNull($participant->reward_ref);
    }

    public function test_disburse_awards_loyalty_points(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);

        $campaign = $this->makeCampaign(['audience_type' => 'provider', 'reward_type' => 'loyalty_points', 'reward_value' => 50, 'status' => 'approved']);
        PerformanceCampaignParticipant::create([
            'performance_campaign_id' => $campaign->id, 'participant_type' => \App\Models\Provider::class,
            'participant_id' => $provider->id, 'metric_value' => 5, 'rank' => 1, 'qualified' => true, 'reward_status' => 'pending',
        ]);

        app(PerformanceCampaignService::class)->disburse($campaign);

        $balance = app(\App\Services\LoyaltyService::class)->balance($provider->user);
        $this->assertSame(50, $balance);
    }

    public function test_disburse_assigns_badge_reward(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $badge = Badge::create(['key' => 'top_performer', 'label' => 'Top Performer', 'mode' => 'manual', 'priority' => 10, 'is_active' => true]);

        $campaign = $this->makeCampaign(['audience_type' => 'provider', 'reward_type' => 'badge', 'reward_value' => null, 'badge_id' => $badge->id, 'status' => 'approved']);
        PerformanceCampaignParticipant::create([
            'performance_campaign_id' => $campaign->id, 'participant_type' => \App\Models\Provider::class,
            'participant_id' => $provider->id, 'metric_value' => 5, 'rank' => 1, 'qualified' => true, 'reward_status' => 'pending',
        ]);

        app(PerformanceCampaignService::class)->disburse($campaign);

        $this->assertTrue(\App\Models\BadgeAssignment::where('badge_id', $badge->id)->where('badgeable_type', \App\Models\Provider::class)->where('badgeable_id', $provider->id)->exists());
    }

    public function test_disburse_never_pays_unqualified_participants(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);

        $campaign = $this->makeCampaign(['audience_type' => 'provider', 'reward_type' => 'wallet_credit', 'reward_value' => 250, 'status' => 'approved']);
        PerformanceCampaignParticipant::create([
            'performance_campaign_id' => $campaign->id, 'participant_type' => \App\Models\Provider::class,
            'participant_id' => $provider->id, 'metric_value' => 0, 'rank' => null, 'qualified' => false, 'reward_status' => 'not_applicable',
        ]);

        app(PerformanceCampaignService::class)->disburse($campaign);

        $this->assertSame(0.0, app(WalletService::class)->balance($provider->user));
    }

    public function test_disburse_moves_campaign_to_rewarded(): void
    {
        $campaign = $this->makeCampaign(['status' => 'approved']);

        $result = app(PerformanceCampaignService::class)->disburse($campaign);

        $this->assertSame('rewarded', $result->status);
    }

    public function test_disburse_is_idempotent_never_double_pays(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);

        $campaign = $this->makeCampaign(['audience_type' => 'provider', 'reward_type' => 'wallet_credit', 'reward_value' => 250, 'status' => 'approved']);
        PerformanceCampaignParticipant::create([
            'performance_campaign_id' => $campaign->id, 'participant_type' => \App\Models\Provider::class,
            'participant_id' => $provider->id, 'metric_value' => 5, 'rank' => 1, 'qualified' => true, 'reward_status' => 'pending',
        ]);

        $service = app(PerformanceCampaignService::class);
        $service->disburse($campaign);

        // Campaign is now 'rewarded' — a second disburse() call must refuse outright (not 'approved' anymore).
        $this->expectException(\RuntimeException::class);
        $service->disburse($campaign->fresh());

        $this->assertSame(1, WalletTransaction::where('ref', "perf_campaign:{$campaign->id}:participant:".PerformanceCampaignParticipant::first()->id)->count());
    }

    public function test_franchise_audience_reward_credits_owner_wallet(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $owner = $this->makeCustomer(); // any real User works as an owner for this fixture
        $franchise->update(['owner_user_id' => $owner->id]);

        $campaign = $this->makeCampaign(['audience_type' => 'franchise', 'reward_type' => 'wallet_credit', 'reward_value' => 500, 'status' => 'approved']);
        PerformanceCampaignParticipant::create([
            'performance_campaign_id' => $campaign->id, 'participant_type' => \App\Models\Franchise::class,
            'participant_id' => $franchise->id, 'metric_value' => 5, 'rank' => 1, 'qualified' => true, 'reward_status' => 'pending',
        ]);

        app(PerformanceCampaignService::class)->disburse($campaign);

        $this->assertSame(500.0, app(WalletService::class)->balance($owner));
    }

    // ============================== Admin authorization ==============================

    public function test_mount_requires_view_permission(): void
    {
        $user = $this->makeUserWithNoPermissions();
        $this->actingAs($user);

        Livewire::test(PerformanceCampaignsManage::class)->assertForbidden();
    }

    public function test_mount_allows_holder_of_view_permission(): void
    {
        $user = $this->makeUserWithPermission('performance_campaigns.view', 'global');
        $this->actingAs($user);

        Livewire::test(PerformanceCampaignsManage::class)->assertOk();
    }

    public function test_create_rejected_without_manage_permission(): void
    {
        $user = $this->makeUserWithPermission('performance_campaigns.view', 'global');
        $this->actingAs($user);

        Livewire::test(PerformanceCampaignsManage::class)
            ->set('name', 'New Campaign')->set('rewardValue', '100')->set('targetValue', '2')
            ->call('create');

        $this->assertSame(0, PerformanceCampaign::count());
    }

    public function test_create_succeeds_with_manage_permission(): void
    {
        $user = $this->makeUserWithPermission('performance_campaigns.view', 'global');
        $this->grantPermission($user, 'performance_campaigns.manage', 'global');
        $this->actingAs($user);

        Livewire::test(PerformanceCampaignsManage::class)
            ->set('name', 'New Campaign')->set('rewardValue', '100')->set('targetValue', '2')
            ->call('create');

        $this->assertSame(1, PerformanceCampaign::count());
    }

    public function test_create_out_of_scope_franchise_rejected(): void
    {
        [, , $franchiseA] = $this->makeFranchiseTree();
        [, , $franchiseB] = $this->makeFranchiseTree();

        $user = $this->makeUserWithPermission('performance_campaigns.view', 'global');
        $this->grantPermission($user, 'performance_campaigns.manage', 'franchise', $franchiseA->id);
        $this->actingAs($user);

        Livewire::test(PerformanceCampaignsManage::class)
            ->set('name', 'Franchise B Campaign')->set('rewardValue', '100')->set('targetValue', '2')
            ->set('scopeType', 'franchise')->set('scopeFranchiseId', $franchiseB->id)
            ->call('create');

        $this->assertSame(0, PerformanceCampaign::count());
    }

    public function test_approve_requires_approve_permission_not_just_manage(): void
    {
        $user = $this->makeUserWithPermission('performance_campaigns.view', 'global');
        $this->grantPermission($user, 'performance_campaigns.manage', 'global');
        $this->actingAs($user);

        $campaign = $this->makeCampaign(['status' => 'under_review']);

        Livewire::test(PerformanceCampaignsManage::class)->call('approve', $campaign->id);

        $this->assertSame('under_review', $campaign->fresh()->status);
    }

    public function test_approve_succeeds_with_approve_permission(): void
    {
        $user = $this->makeUserWithPermission('performance_campaigns.view', 'global');
        $this->grantPermission($user, 'performance_campaigns.approve', 'global');
        $this->actingAs($user);

        $campaign = $this->makeCampaign(['status' => 'under_review']);

        Livewire::test(PerformanceCampaignsManage::class)->call('approve', $campaign->id);

        $this->assertSame('approved', $campaign->fresh()->status);
    }

    public function test_disburse_requires_approve_permission(): void
    {
        $user = $this->makeUserWithPermission('performance_campaigns.view', 'global');
        $this->grantPermission($user, 'performance_campaigns.manage', 'global');
        $this->actingAs($user);

        $campaign = $this->makeCampaign(['status' => 'approved']);

        Livewire::test(PerformanceCampaignsManage::class)->call('disburse', $campaign->id);

        $this->assertSame('approved', $campaign->fresh()->status);
    }

    public function test_create_validation_requires_target_value_for_threshold_mode(): void
    {
        $user = $this->makeUserWithPermission('performance_campaigns.view', 'global');
        $this->grantPermission($user, 'performance_campaigns.manage', 'global');
        $this->actingAs($user);

        Livewire::test(PerformanceCampaignsManage::class)
            ->set('name', 'No Target')->set('rewardValue', '100')->set('qualificationMode', 'threshold')->set('targetValue', '')
            ->call('create')
            ->assertHasErrors(['targetValue']);

        $this->assertSame(0, PerformanceCampaign::count());
    }
}
