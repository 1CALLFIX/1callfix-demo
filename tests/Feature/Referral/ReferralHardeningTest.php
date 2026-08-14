<?php

namespace Tests\Feature\Referral;

use App\Livewire\Loyalty\Index as LoyaltyIndex;
use App\Models\Referral;
use App\Models\Setting;
use App\Services\ReferralService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Full-day mission Phase 3 (Referral Engine hardening). Deliberately does
 * NOT touch the existing, tested pending->rewarded qualification flow (see
 * ReferralServiceTest, still green and unmodified) or invent cross-actor
 * qualification rules -- KNOWN_RISKS_AND_DECISIONS.md item 2 remains
 * genuinely open. This covers the real, safe additions: opt-in expiry and
 * admin-driven fraud flagging with wallet clawback.
 */
class ReferralHardeningTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    // ============================== Expiry ==============================

    public function test_referrals_never_expire_by_default(): void
    {
        $referrer = $this->makeCustomer();
        $newUser = $this->makeCustomer();
        $newUser->update(['referred_by' => $referrer->id]);

        $referral = app(ReferralService::class)->createFromSignup($newUser->fresh());

        $this->assertNull($referral->expires_at, 'expires_at must stay null unless an admin opts in via referral.pending_expiry_days.');
    }

    public function test_a_configured_expiry_window_is_applied_at_creation(): void
    {
        Setting::set('referral.pending_expiry_days', '30');
        $referrer = $this->makeCustomer();
        $newUser = $this->makeCustomer();
        $newUser->update(['referred_by' => $referrer->id]);

        $referral = app(ReferralService::class)->createFromSignup($newUser->fresh());

        $this->assertNotNull($referral->expires_at);
        $this->assertTrue($referral->expires_at->isSameDay(now()->addDays(30)));
    }

    public function test_expirePendingReferrals_marks_only_past_due_pending_referrals(): void
    {
        $expired = Referral::create(['referrer_id' => $this->makeCustomer()->id, 'referred_id' => $this->makeCustomer()->id, 'status' => 'pending', 'expires_at' => now()->subDay()]);
        $notYet = Referral::create(['referrer_id' => $this->makeCustomer()->id, 'referred_id' => $this->makeCustomer()->id, 'status' => 'pending', 'expires_at' => now()->addDay()]);
        $noExpiry = Referral::create(['referrer_id' => $this->makeCustomer()->id, 'referred_id' => $this->makeCustomer()->id, 'status' => 'pending', 'expires_at' => null]);

        $count = app(ReferralService::class)->expirePendingReferrals();

        $this->assertSame(1, $count);
        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertSame('pending', $notYet->fresh()->status);
        $this->assertSame('pending', $noExpiry->fresh()->status);
    }

    public function test_expiring_never_touches_an_already_rewarded_referral(): void
    {
        $rewarded = Referral::create(['referrer_id' => $this->makeCustomer()->id, 'referred_id' => $this->makeCustomer()->id, 'status' => 'rewarded', 'reward_amount' => 50, 'expires_at' => now()->subDay()]);

        app(ReferralService::class)->expirePendingReferrals();

        $this->assertSame('rewarded', $rewarded->fresh()->status, 'expirePendingReferrals() only ever touches status=pending, never a completed reward.');
    }

    public function test_qualification_still_works_normally_when_expiry_is_configured_but_not_yet_reached(): void
    {
        Setting::set('referral.pending_expiry_days', '30');
        $referrer = $this->makeCustomer();
        ['booking' => $booking, 'customer' => $referred] = $this->makeBookingScenario('completed');
        $referred->update(['referred_by' => $referrer->id]);
        app(ReferralService::class)->createFromSignup($referred->fresh());

        $referral = app(ReferralService::class)->qualifyFromCompletedBooking($booking);

        $this->assertSame('rewarded', $referral->status, 'A real, still-open expiry window must never block a legitimate on-time qualification.');
    }

    // ============================== Fraud flagging ==============================

    public function test_flagging_a_pending_referral_as_fraud_does_not_touch_the_wallet(): void
    {
        $referral = Referral::create(['referrer_id' => $this->makeCustomer()->id, 'referred_id' => $this->makeCustomer()->id, 'status' => 'pending']);
        $admin = $this->makeSuperAdmin();

        $flagged = app(ReferralService::class)->flagAsFraud($referral, $admin, 'Suspicious device pattern');

        $this->assertSame('fraud_flagged', $flagged->status);
        $this->assertSame($admin->id, $flagged->fraud_flagged_by);
        $this->assertSame('Suspicious device pattern', $flagged->fraud_notes);
        $this->assertNull($flagged->reversed_at, 'A referral that was never rewarded has nothing to claw back.');
    }

    public function test_flagging_a_rewarded_referral_claws_back_the_wallet_credit(): void
    {
        $referrer = $this->makeCustomer();
        app(WalletService::class)->credit($referrer, 50, 'referral reward', 'test:seed');
        $referral = Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $this->makeCustomer()->id, 'status' => 'rewarded', 'reward_amount' => 50]);
        $admin = $this->makeSuperAdmin();

        app(ReferralService::class)->flagAsFraud($referral, $admin, 'Confirmed reward farming');

        $this->assertSame(0.0, app(WalletService::class)->balance($referrer));
        $this->assertNotNull($referral->fresh()->reversed_at);
    }

    public function test_flagging_a_rewarded_referral_whose_balance_was_already_spent_does_not_crash_and_records_the_failure(): void
    {
        $referrer = $this->makeCustomer();
        // Never actually credited (simulating the reward having already been spent elsewhere) -- balance is 0.
        $referral = Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $this->makeCustomer()->id, 'status' => 'rewarded', 'reward_amount' => 50]);
        $admin = $this->makeSuperAdmin();

        $flagged = app(ReferralService::class)->flagAsFraud($referral, $admin, 'Late fraud detection');

        $this->assertSame('fraud_flagged', $flagged->status);
        $this->assertNotNull($flagged->reversed_at);
        $this->assertStringContainsString('failed', $flagged->reversal_note);
        $this->assertSame(0.0, app(WalletService::class)->balance($referrer), 'Must never go negative.');
    }

    public function test_a_points_type_reward_has_nothing_to_claw_back_from_the_wallet(): void
    {
        $referrer = $this->makeCustomer();
        $referral = Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $this->makeCustomer()->id, 'status' => 'rewarded', 'reward_amount' => 0]);
        $admin = $this->makeSuperAdmin();

        $flagged = app(ReferralService::class)->flagAsFraud($referral, $admin, 'test');

        $this->assertSame('fraud_flagged', $flagged->status);
        $this->assertSame(0.0, app(WalletService::class)->balance($referrer));
    }

    // ============================== Admin authorization ==============================

    public function test_flag_fraud_denied_without_loyalty_manage_permission(): void
    {
        $referral = Referral::create(['referrer_id' => $this->makeCustomer()->id, 'referred_id' => $this->makeCustomer()->id, 'status' => 'pending']);
        $actor = $this->makeUserWithPermission('loyalty.view', 'global');

        Livewire::actingAs($actor)->test(LoyaltyIndex::class)
            ->set('activeTab', 'referrals')
            ->set('flaggingReferralId', $referral->id)
            ->set('fraudNotes', 'test')
            ->call('flagFraud')
            ->assertSet('flashType', 'error');

        $this->assertSame('pending', $referral->fresh()->status);
    }

    public function test_flag_fraud_denied_outside_the_referrers_own_zone(): void
    {
        [, , , $myZone] = $this->makeFranchiseTree();
        [, , $otherFranchise, $otherZone] = $this->makeFranchiseTree();
        $referrer = $this->makeCustomer();
        $referrer->update(['zone_id' => $otherZone->id, 'franchise_id' => $otherFranchise->id]);
        $referral = Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $this->makeCustomer()->id, 'status' => 'pending']);

        $actor = $this->makeUserWithPermission('loyalty.manage', 'zone', $myZone->id);
        $this->grantPermission($actor, 'loyalty.view', 'zone', $myZone->id);

        Livewire::actingAs($actor)->test(LoyaltyIndex::class)
            ->set('activeTab', 'referrals')
            ->set('flaggingReferralId', $referral->id)
            ->set('fraudNotes', 'test')
            ->call('flagFraud')
            ->assertSet('flashType', 'error');

        $this->assertSame('pending', $referral->fresh()->status);
    }

    public function test_flag_fraud_allowed_within_the_referrers_own_zone(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $referrer = $this->makeCustomer();
        $referrer->update(['zone_id' => $zone->id, 'franchise_id' => $franchise->id]);
        $referral = Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $this->makeCustomer()->id, 'status' => 'pending']);

        $actor = $this->makeUserWithPermission('loyalty.manage', 'zone', $zone->id);
        $this->grantPermission($actor, 'loyalty.view', 'zone', $zone->id);

        Livewire::actingAs($actor)->test(LoyaltyIndex::class)
            ->set('activeTab', 'referrals')
            ->set('flaggingReferralId', $referral->id)
            ->set('fraudNotes', 'Confirmed fraud')
            ->call('flagFraud')
            ->assertSet('flashType', 'success');

        $this->assertSame('fraud_flagged', $referral->fresh()->status);
    }

    public function test_flag_fraud_requires_notes(): void
    {
        $referral = Referral::create(['referrer_id' => $this->makeCustomer()->id, 'referred_id' => $this->makeCustomer()->id, 'status' => 'pending']);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(LoyaltyIndex::class)
            ->set('activeTab', 'referrals')
            ->set('flaggingReferralId', $referral->id)
            ->set('fraudNotes', '')
            ->call('flagFraud')
            ->assertHasErrors(['fraudNotes']);

        $this->assertSame('pending', $referral->fresh()->status);
    }
}
