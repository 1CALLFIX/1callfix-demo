<?php

namespace Tests\Feature\Referral;

use App\Livewire\Customer\Earnings\Referrals;
use App\Models\LoyaltyPoint;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\EarningsSettingsFixtureValues;
use Tests\TestCase;

/**
 * REF 1CF-PROMPT-20260925-EARN4 — Stage 4: the customer Referrals tab and
 * GET /api/referrals/mine.
 */
class ReferralShareAndApiTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        EarningsSettingsFixtureValues::referral();
        foreach (['earnings.enabled', 'earnings.referral_tab'] as $key) {
            Setting::set($key, '1');
        }
        config(['app.url' => 'https://app.1callfix.test']);
    }

    private function customerWithCode(string $code, string $name = 'Customer'): User
    {
        $u = $this->makeCustomer();
        $u->forceFill(['referral_code' => $code, 'name' => $name])->save();

        return $u->fresh();
    }

    private function refer(User $referrer, string $friendName, string $status, array $extra = []): Referral
    {
        $friend = $this->makeCustomer();
        $friend->forceFill(['name' => $friendName])->save();

        return Referral::create(array_merge(['referrer_id' => $referrer->id, 'referred_id' => $friend->id, 'status' => $status], $extra));
    }

    public function test_tab_shows_code_share_link_whatsapp_and_copy(): void
    {
        $a = $this->customerWithCode('AAA1');

        Livewire::actingAs($a)->test(Referrals::class)
            ->assertSee('AAA1')
            ->assertSee('https://app.1callfix.test/signup?ref=AAA1')
            ->assertSee('https://wa.me/?text=', false)
            ->assertSee('Copy')
            ->assertSee('Share on WhatsApp');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function statusLabels(): array
    {
        return [
            'pending' => ['pending', 'Waiting for their first booking'],
            'held_review' => ['held_review', 'Under review'],
            'rewarded' => ['rewarded', 'Rewarded'],
            'expired' => ['expired', 'Expired'],
            'fraud_flagged' => ['fraud_flagged', 'Reversed'],
            'rejected' => ['rejected', 'Not eligible'],
        ];
    }

    #[DataProvider('statusLabels')]
    public function test_statuses_read_in_plain_language_on_tab_and_api(string $status, string $label): void
    {
        $a = $this->customerWithCode('LBL1');
        $this->refer($a, 'Friendly Person', $status);

        Livewire::actingAs($a)->test(Referrals::class)->assertSee($label);
        if ($status === 'held_review') {
            Livewire::actingAs($a)->test(Referrals::class)->assertDontSee('held_review');
        }

        $this->actingAs($a, 'sanctum')->getJson('/api/referrals/mine')
            ->assertOk()
            ->assertJsonPath('referrals.0.status', $status)
            ->assertJsonPath('referrals.0.status_label', $label);
    }

    public function test_amounts_credited_wallet_and_points(): void
    {
        $a = $this->customerWithCode('AMT1');
        $this->refer($a, 'Wally Wallet', 'rewarded', ['reward_amount' => 50]);
        $p = $this->refer($a, 'Pat Points', 'rewarded', ['reward_amount' => 0]);
        LoyaltyPoint::create(['user_id' => $a->id, 'points' => 120, 'reason' => 'referral_reward', 'ref' => "referral:{$p->id}:points-reward"]);

        Livewire::actingAs($a)->test(Referrals::class)->assertSee('+₹50.00')->assertSee('+120 pts');

        $json = $this->actingAs($a, 'sanctum')->getJson('/api/referrals/mine')->assertOk()->json('referrals');
        $byFriend = collect($json)->keyBy('friend');
        $this->assertEquals(50, $byFriend['Wally']['reward_amount']);
        $this->assertSame(120, $byFriend['Pat']['reward_points']);
    }

    public function test_api_returns_code_share_link_rules_and_own_referrals_only(): void
    {
        $a = $this->customerWithCode('OWN1');
        $b = $this->customerWithCode('OTH1');
        $this->refer($a, 'Alicefriend Person', 'pending');
        $this->refer($b, 'Bobfriend Person', 'rewarded', ['reward_amount' => 50]);

        $res = $this->actingAs($a, 'sanctum')->getJson('/api/referrals/mine')->assertOk();

        $res->assertJsonPath('code', 'OWN1')
            ->assertJsonPath('share_link', 'https://app.1callfix.test/signup?ref=OWN1')
            ->assertJsonPath('rules.reward_type', 'wallet')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('referrals.0.friend', 'Alicefriend');
        $this->assertStringNotContainsString('Bobfriend', $res->getContent());
        $this->assertStringNotContainsString('OTH1', $res->getContent());
    }

    public function test_tab_shows_own_referrals_only(): void
    {
        $a = $this->customerWithCode('TABA');
        $b = $this->customerWithCode('TABB');
        $this->refer($a, 'Alicefriend Person', 'pending');
        $this->refer($b, 'Bobfriend Person', 'pending');

        Livewire::actingAs($a)->test(Referrals::class)
            ->assertSee('Alicefriend')->assertDontSee('Bobfriend')->assertDontSee('TABB');
    }

    public function test_personal_data_never_leaves_to_the_customer(): void
    {
        $a = $this->customerWithCode('PII1');
        $this->refer($a, 'Private Person', 'held_review', ['signup_ip' => '198.51.100.7', 'signup_device_id' => 'dev-secret', 'hold_reason' => 'same_signup_ip']);

        $body = $this->actingAs($a, 'sanctum')->getJson('/api/referrals/mine')->assertOk()->getContent();
        foreach (['198.51.100.7', 'dev-secret', 'same_signup_ip', 'Person'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        Livewire::actingAs($a)->test(Referrals::class)
            ->assertDontSee('198.51.100.7')->assertDontSee('dev-secret')->assertDontSee('same_signup_ip');
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function offSwitches(): array
    {
        return [
            'program off' => ['referral.enabled', '0'], 'program unset' => ['referral.enabled', null],
            'tab off' => ['earnings.referral_tab', '0'], 'tab unset' => ['earnings.referral_tab', null],
            'master off' => ['earnings.enabled', '0'],
        ];
    }

    #[DataProvider('offSwitches')]
    public function test_switches_off_hide_both_tab_and_api(string $key, ?string $value): void
    {
        $a = $this->customerWithCode('OFF1');
        $value === null ? Setting::clear($key, 'global', null) : Setting::set($key, $value);

        $this->actingAs($a)->get(route('customer.earnings.referrals'))->assertNotFound();
        $this->actingAs($a, 'sanctum')->getJson('/api/referrals/mine')->assertNotFound();
        $this->actingAs($a)->get(route('customer.earnings.wallet'))->assertDontSee(route('customer.earnings.referrals'));
    }

    public function test_api_requires_auth(): void
    {
        $this->getJson('/api/referrals/mine')->assertUnauthorized();
    }
}
