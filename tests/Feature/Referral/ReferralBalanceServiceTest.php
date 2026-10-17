<?php

namespace Tests\Feature\Referral;

use App\Models\ReferralBalanceTransaction;
use App\Models\Setting;
use App\Services\ReferralBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/** Owner decision 2026-10-17 — the separate referral balance: credit, oldest-expiry-first spending, clawback, expiry. */
class ReferralBalanceServiceTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function svc(): ReferralBalanceService
    {
        return app(ReferralBalanceService::class);
    }

    public function test_credit_is_idempotent_on_its_ref(): void
    {
        $u = $this->makeCustomer();
        $this->svc()->credit($u, 50, 'reward', 'r:1');
        $this->svc()->credit($u, 50, 'reward', 'r:1');

        $this->assertEqualsWithDelta(50.0, $this->svc()->available($u), 0.001);
        $this->assertSame(1, ReferralBalanceTransaction::where('ref', 'r:1')->count());
    }

    public function test_spending_uses_the_grant_that_expires_soonest_first(): void
    {
        $u = $this->makeCustomer();
        $never = $this->svc()->credit($u, 100, 'a', 'g:never');
        $soon = $this->svc()->credit($u, 100, 'b', 'g:soon', null, now()->addDays(5));

        $this->svc()->debit($u, 120, 'booking part', 'spend:1');

        $this->assertEqualsWithDelta(0.0, (float) $soon->fresh()->remaining, 0.001);
        $this->assertEqualsWithDelta(80.0, (float) $never->fresh()->remaining, 0.001);
        $this->assertEqualsWithDelta(80.0, $this->svc()->available($u), 0.001);
    }

    public function test_debit_refuses_more_than_available_and_moves_nothing(): void
    {
        $u = $this->makeCustomer();
        $this->svc()->credit($u, 40, 'a', 'g:1');

        try {
            $this->svc()->debit($u, 50, 'too much', 'spend:x');
            $this->fail('expected a refusal');
        } catch (\RuntimeException) {
        }
        $this->assertEqualsWithDelta(40.0, $this->svc()->available($u), 0.001);
    }

    public function test_debit_up_to_takes_what_is_there_and_never_goes_negative(): void
    {
        $u = $this->makeCustomer();
        $this->svc()->credit($u, 30, 'a', 'g:1');

        $taken = $this->svc()->debitUpTo($u, 50, 'clawback', 'claw:1');

        $this->assertEqualsWithDelta(30.0, (float) $taken->amount, 0.001);
        $this->assertSame(0.0, $this->svc()->available($u));
        $this->assertNull($this->svc()->debitUpTo($u, 10, 'clawback', 'claw:2'), 'nothing left to take');
    }

    public function test_expiry_setting_dates_new_grants_and_expire_due_removes_them_once(): void
    {
        Setting::set('referral.balance_expiry_days', '30');
        $u = $this->makeCustomer();
        $grant = $this->svc()->credit($u, 50, 'a', 'g:1');
        $this->assertNotNull($grant->expires_at);

        $this->travel(31)->days();
        $this->assertSame(0.0, $this->svc()->available($u), 'an expired grant cannot be spent');
        $this->assertSame(1, $this->svc()->expireDue());
        $this->assertSame(0, $this->svc()->expireDue(), 'running again changes nothing');
        $this->assertSame(1, ReferralBalanceTransaction::where('ref', "referral-balance:expire:{$grant->id}")->count());
    }

    public function test_blank_expiry_means_the_grant_never_expires(): void
    {
        $u = $this->makeCustomer();
        $grant = $this->svc()->credit($u, 50, 'a', 'g:1');

        $this->assertNull($grant->expires_at);
        $this->travel(1000)->days();
        $this->assertEqualsWithDelta(50.0, $this->svc()->available($u), 0.001);
    }
}
