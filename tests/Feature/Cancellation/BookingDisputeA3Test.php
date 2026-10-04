<?php

namespace Tests\Feature\Cancellation;

use App\Contracts\PaymentGateway;
use App\Models\ActivityLog;
use App\Models\BookingDispute;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\ProviderDisputeDebt;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\AdminOpsAlertNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\AdminOpsAlertService;
use App\Services\BookingDisputeService;
use App\Services\PayoutService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * A3 — dispute refund destination (original method / wallet), who bears the refund (provider / company / split) with the
 * provider's share recovered through the ledger, the escalation alerts, and the migration rollback guards.
 */
class BookingDisputeA3Test extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    /** A completed ₹500 booking paid as $kind: 'online' (Razorpay), 'wallet', or 'cash'. */
    private function paid(string $kind): array
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'completed', 'payment_status' => 'paid', 'price_final' => 500, 'payment_method' => $kind === 'cash' ? 'cash' : 'online']);

        if ($kind !== 'cash') {
            $s['payment'] = Payment::create([
                'booking_id' => $s['booking']->id, 'purpose' => 'booking', 'amount' => 500,
                'gateway' => $kind === 'wallet' ? 'wallet' : 'razorpay',
                'gateway_payment_id' => $kind === 'online' ? 'pay_'.Str::random(8) : null,
                'gateway_order_id' => 'order_'.Str::random(8), 'status' => 'captured',
            ]);
        }

        $s['booking'] = $s['booking']->fresh();

        return $s;
    }

    private function svc(): BookingDisputeService
    {
        return app(BookingDisputeService::class);
    }

    private function holder(string $scope = 'global', ?int $id = null): User
    {
        return $this->makeUserWithPermission(BookingDisputeService::PERMISSION, $scope, $id);
    }

    private function limits(string $franchise = '1000', string $hq = '5000'): void
    {
        Setting::set(BookingDisputeService::FRANCHISE_LIMIT_KEY, $franchise);
        Setting::set(BookingDisputeService::HQ_LIMIT_KEY, $hq);
    }

    private function dispute(array $s): BookingDispute
    {
        return $this->svc()->raise($s['booking']->id, $s['customer']->id, 'He charged far too much for the work');
    }

    /** Resolve with a refund and run the request, returning the fresh dispute. */
    private function refund(array $s, float $amount, string $bearer, ?float $providerShare = null, ?string $destination = null, ?string $walletNote = null): BookingDispute
    {
        $this->limits();
        $d = $this->dispute($s);
        $admin = $this->holder();
        $r = $this->svc()->resolve($d, $admin, 'refund', 'Overcharged', $amount, $bearer, $providerShare, $destination, $walletNote);
        $this->assertTrue($r['ok'], $r['message']);
        $r = $this->svc()->requestRefund($d, $admin, 'Approved');
        $this->assertTrue($r['ok'], $r['message']);

        return $d->fresh();
    }

    private function gateway(string $paymentId, float $amount): void
    {
        $mock = Mockery::mock(PaymentGateway::class);
        $mock->shouldReceive('refund')->once()->withArgs(fn ($id, $amt) => $id === $paymentId && abs($amt - $amount) < 0.001)->andReturn(['id' => 'rfnd_'.Str::random(5)]);
        $this->app->instance(PaymentGateway::class, $mock);
    }

    private function noGateway(): void
    {
        $mock = Mockery::mock(PaymentGateway::class);
        $mock->shouldNotReceive('refund');
        $this->app->instance(PaymentGateway::class, $mock);
    }

    private function walletRefundCount(BookingDispute $d): int
    {
        return WalletTransaction::where('ref', "booking:{$d->booking_id}:wallet-refund:dispute-{$d->id}")->count();
    }

    // ------------------------------------------------------------------ migration guards

    public function test_both_down_migrations_refuse_while_dispute_data_exists(): void
    {
        $s = $this->paid('wallet');
        $this->dispute($s);

        foreach (['2026_10_04_200000_a3_dispute_refund_destination_bearer_and_rollback_guard', '2026_10_04_120000_add_interim_amount_and_booking_disputes'] as $file) {
            $migration = require database_path("migrations/{$file}.php");
            try {
                $migration->down();
                $this->fail("{$file} down() must refuse while disputes exist");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Cannot roll back', $e->getMessage());
                $this->assertStringContainsString('1 dispute(s)', $e->getMessage());
            }
        }
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('booking_disputes'), 'nothing was dropped');
    }

    public function test_down_refuses_when_only_a_declared_interim_amount_exists_and_runs_when_there_is_no_data(): void
    {
        $s = $this->paid('wallet');
        $s['booking']->update(['interim_amount' => 120]);

        $migration = require database_path('migrations/2026_10_04_200000_a3_dispute_refund_destination_bearer_and_rollback_guard.php');
        try {
            $migration->down();
            $this->fail('must refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 booking(s) with a declared work amount', $e->getMessage());
        }

        $s['booking']->update(['interim_amount' => null]);
        $migration->down(); // empty: allowed
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('provider_dispute_debts'));
        $migration->up();   // restore for the rest of the test run
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('provider_dispute_debts'));
    }

    // ------------------------------------------------------------------ destination

    public function test_an_online_payment_is_refunded_to_the_original_method_by_default_and_only_once(): void
    {
        $s = $this->paid('online');
        $this->gateway($s['payment']->gateway_payment_id, 200);

        $d = $this->refund($s, 200, 'company');

        $this->assertSame('original', $d->refund_destination);
        $this->assertSame('refunded', $d->refund_status);
        $this->assertNotNull($d->refund_gateway_id);
        $this->assertSame(0, $this->walletRefundCount($d), 'no wallet credit for an original-method refund');
        $this->assertSame(200.0, (float) $s['payment']->fresh()->refunded_amount);

        $this->assertFalse($this->svc()->requestRefund($d, $this->holder(), 'again')['ok'], 'idempotent: already refunded');
        $this->assertFalse($this->svc()->retry($d, $this->holder())['ok']);
    }

    public function test_a_gateway_failure_is_recorded_and_the_retry_refunds_exactly_once(): void
    {
        $s = $this->paid('online');
        $this->limits();
        $d = $this->dispute($s);
        $admin = $this->holder();
        $this->svc()->resolve($d, $admin, 'refund', 'Overcharged', 150, 'company');

        $fail = Mockery::mock(PaymentGateway::class);
        $fail->shouldReceive('refund')->once()->andThrow(new \RuntimeException('gateway down'));
        $this->app->instance(PaymentGateway::class, $fail);
        $this->assertFalse($this->svc()->requestRefund($d, $admin, 'go')['ok']);
        $this->assertSame('failed', $d->fresh()->refund_status);
        $this->assertSame(0.0, (float) $s['payment']->fresh()->refunded_amount);

        $this->gateway($s['payment']->gateway_payment_id, 150);
        $this->assertTrue($this->svc()->retry($d->fresh(), $admin)['ok']);
        $this->assertSame('refunded', $d->fresh()->refund_status);
        $this->assertSame(150.0, (float) $s['payment']->fresh()->refunded_amount);
    }

    public function test_wallet_and_cash_payments_refund_to_the_wallet_only_and_never_touch_the_gateway(): void
    {
        $this->noGateway();

        foreach (['wallet', 'cash'] as $kind) {
            $s = $this->paid($kind);
            $d = $this->refund($s, 120, 'company');

            $this->assertSame('wallet', $d->refund_destination, $kind);
            $this->assertSame(1, $this->walletRefundCount($d), $kind);
            $this->assertSame(120.0, (float) app(WalletService::class)->balance($s['customer']), $kind);
            $this->assertFalse($this->svc()->requestRefund($d, $this->holder(), 'again')['ok'], "{$kind}: idempotent");
            $this->assertSame(1, $this->walletRefundCount($d), "{$kind}: never a second credit");
        }
    }

    public function test_a_cash_booking_can_be_disputed_and_the_original_method_is_refused_for_it(): void
    {
        $s = $this->paid('cash');
        $d = $this->dispute($s);
        $this->assertSame('500.00', $d->amount_paid);

        $r = $this->svc()->resolve($d, $this->holder(), 'refund', 'x', 50, 'company', null, 'original');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Only an online payment', $r['message']);
    }

    public function test_wallet_instead_of_original_for_an_online_payment_needs_a_customer_agreed_note_which_is_recorded(): void
    {
        $this->noGateway();
        $s = $this->paid('online');
        $d = $this->dispute($s);
        $admin = $this->holder();

        $r = $this->svc()->resolve($d, $admin, 'refund', 'n', 100, 'company', null, 'wallet');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('customer agrees', $r['message']);

        $this->limits();
        $this->assertTrue($this->svc()->resolve($d, $admin, 'refund', 'n', 100, 'company', null, 'wallet', 'Customer agreed on the phone')['ok']);
        $this->assertSame('Customer agreed on the phone', $d->fresh()->refund_wallet_choice_note);
        $this->assertTrue($this->svc()->requestRefund($d, $admin, 'go')['ok']);
        $this->assertSame(1, $this->walletRefundCount($d));
        $this->assertNotNull(ActivityLog::where('subject_type', BookingDispute::class)->where('subject_id', $d->id)->where('properties->wallet_choice_note', 'Customer agreed on the phone')->first(), 'audit-logged');
    }

    // ------------------------------------------------------------------ who bears it

    public function test_the_admin_must_choose_who_bears_the_refund_and_there_is_no_default(): void
    {
        $s = $this->paid('wallet');
        $d = $this->dispute($s);

        $r = $this->svc()->resolve($d, $this->holder(), 'refund', 'n', 100);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Choose who bears', $r['message']);
        $this->assertSame('open', $d->fresh()->status);
    }

    public function test_provider_share_is_debited_through_the_ledger_and_the_company_share_is_not(): void
    {
        $s = $this->paid('wallet');
        app(WalletService::class)->credit($s['provider']->user, 1000, 'seed', 'seed:provider');

        $d = $this->refund($s, 300, 'split', 100.01);

        $this->assertSame('100.01', $d->provider_share);
        $this->assertSame('199.99', $d->company_share);
        $this->assertSame(300.0, round((float) $d->provider_share + (float) $d->company_share, 2), 'the split adds up exactly');
        $this->assertSame('100.01', $d->provider_recovered);

        $tx = WalletTransaction::where('ref', "booking:{$d->booking_id}:dispute-share:{$d->id}")->get();
        $this->assertCount(1, $tx, 'one ledger debit');
        $this->assertSame(899.99, (float) app(WalletService::class)->balance($s['provider']->user), '1000 - 100.01 only; the company share is not taken');
        $this->assertSame(0, ProviderDisputeDebt::count());
        $this->assertSame(300.0, (float) app(WalletService::class)->balance($s['customer']));
    }

    public function test_a_company_borne_refund_never_touches_the_provider(): void
    {
        $s = $this->paid('wallet');
        app(WalletService::class)->credit($s['provider']->user, 500, 'seed', 'seed:provider');

        $d = $this->refund($s, 200, 'company');

        $this->assertSame('0.00', $d->provider_share);
        $this->assertSame('200.00', $d->company_share);
        $this->assertSame(500.0, (float) app(WalletService::class)->balance($s['provider']->user));
        $this->assertSame(0, WalletTransaction::where('ref', 'like', '%:dispute-share:%')->count());
    }

    public function test_a_provider_borne_refund_with_a_short_wallet_takes_what_it_can_and_records_the_rest_as_debt_then_sweeps_it_at_payout(): void
    {
        $s = $this->paid('wallet');
        app(WalletService::class)->credit($s['provider']->user, 60, 'seed', 'seed:provider');

        $d = $this->refund($s, 200, 'provider');

        $this->assertSame('200.00', $d->provider_share);
        $this->assertSame('60.00', $d->provider_recovered);
        $debt = ProviderDisputeDebt::firstOrFail();
        $this->assertSame('140.00', $debt->amount_owed);
        $this->assertSame('outstanding', $debt->status);
        $this->assertSame(0.0, (float) app(WalletService::class)->balance($s['provider']->user), 'never negative');

        // The provider earns again; a payout request sweeps the debt through the ledger first.
        app(WalletService::class)->credit($s['provider']->user, 500, 'job earning', 'booking:999:provider-earning');
        $this->assertSame(140.0, app(PayoutService::class)->outstandingDisputeDebt($s['provider']->id));

        $swept = new \ReflectionMethod(PayoutService::class, 'settleDisputeDebts');
        $swept->setAccessible(true);
        $swept->invoke(app(PayoutService::class), $s['provider']->id);

        $this->assertSame('settled', $debt->fresh()->status);
        $this->assertSame('140.00', $debt->fresh()->amount_settled);
        $this->assertSame(360.0, (float) app(WalletService::class)->balance($s['provider']->user));
        $this->assertSame(1, WalletTransaction::where('ref', 'like', "dispute-debt:{$debt->id}:settle:%")->count());

        $swept->invoke(app(PayoutService::class), $s['provider']->id); // idempotent: nothing left
        $this->assertSame(360.0, (float) app(WalletService::class)->balance($s['provider']->user));
    }

    public function test_a_split_must_leave_both_sides_a_positive_share(): void
    {
        $s = $this->paid('wallet');
        $d = $this->dispute($s);

        foreach ([null, 0.0, 200.0, 250.0] as $share) {
            $r = $this->svc()->resolve($d, $this->holder(), 'refund', 'n', 200, 'split', $share);
            $this->assertFalse($r['ok'], 'share '.var_export($share, true));
        }
    }

    public function test_the_provider_sees_the_share_with_the_dispute_reference_on_their_earnings_page(): void
    {
        $s = $this->paid('wallet');
        app(WalletService::class)->credit($s['provider']->user, 1000, 'seed', 'seed:provider');
        $d = $this->refund($s, 200, 'provider');

        \Livewire\Livewire::actingAs($s['provider']->user)->test(\App\Livewire\Provider\Earnings::class)
            ->assertSee("Dispute #{$d->id}")
            ->assertSee($s['booking']->code)
            ->assertSee('200.00');
    }

    public function test_the_admin_queue_form_records_bearer_destination_and_note(): void
    {
        $s = $this->paid('online');
        $d = $this->dispute($s);
        $admin = $this->holder();

        \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\BookingDisputes\Index::class)
            ->call('startAction', $d->id, 'resolve')
            ->assertSet('destination', 'original')
            ->set('outcome', 'refund')->set('refundAmount', '200')->set('bearer', 'split')->set('providerShare', '80')
            ->set('reason', 'Overcharged')
            ->call('submitAction')
            ->assertSee('Split');

        $d = $d->fresh();
        $this->assertSame('split', $d->bearer);
        $this->assertSame('80.00', $d->provider_share);
        $this->assertSame('120.00', $d->company_share);
        $this->assertSame('original', $d->refund_destination);
        $this->assertSame('awaiting_request', $d->refund_status);
    }

    // ------------------------------------------------------------------ escalation alerts

    private function alertAdmin(User $u): User
    {
        $u->forceFill(['email' => 'admin-'.Str::random(8).'@example.test', 'push_ops_alerts' => true, 'fcm_token' => 'tok-'.Str::random(6)])->save();

        return $u;
    }

    public function test_escalation_sends_push_and_email_to_the_next_level_once_per_level(): void
    {
        Setting::set('notifications.channels', 'mail,push');
        Notification::fake();

        $s = $this->paid('wallet');
        $this->limits('1000', '5000');
        Setting::set(BookingDisputeService::ESCALATE_HOURS_KEY, '2');
        $d = $this->dispute($s);
        $franchise = $this->alertAdmin($this->holder('franchise', $s['franchise']->id));
        $hq = $this->alertAdmin($this->holder('global'));
        $super = $this->alertAdmin($this->makeSuperAdmin());
        $this->svc()->resolve($d, $franchise, 'refund', 'Overcharged', 100, 'company');

        Carbon::setTestNow(now()->addHours(3));
        $this->assertSame(1, $this->svc()->escalateOverdue());

        $viaMail = fn ($n, $channels) => $n->eventKey() === 'admin.ops_dispute_refund_escalation' && in_array('mail', $channels, true);
        $viaPush = fn ($n, $channels) => $n->eventKey() === 'admin.ops_dispute_refund_escalation' && ! in_array('mail', $channels, true) && $channels !== [];
        Notification::assertSentTo($hq, AdminOpsAlertNotification::class, $viaMail);
        Notification::assertSentTo($hq, AdminOpsAlertNotification::class, $viaPush);
        Notification::assertNotSentTo($franchise, AdminOpsAlertNotification::class);
        Notification::assertNotSentTo($super, AdminOpsAlertNotification::class);
        Notification::assertSentToTimes($hq, AdminOpsAlertNotification::class, 2); // one email + one push

        $this->assertSame(0, $this->svc()->escalateOverdue(), 'same age: no duplicate');
        Notification::assertSentToTimes($hq, AdminOpsAlertNotification::class, 2);

        Carbon::setTestNow(now()->addHours(2));
        $this->assertSame(1, $this->svc()->escalateOverdue());
        Notification::assertSentTo($super, AdminOpsAlertNotification::class, $viaMail);
        Notification::assertSentTo($super, AdminOpsAlertNotification::class, $viaPush);
        Notification::assertSentToTimes($hq, AdminOpsAlertNotification::class, 2);
    }

    public function test_the_dispute_alert_type_is_on_the_alert_email_switches_and_can_be_turned_off(): void
    {
        $this->assertArrayHasKey('dispute_refund_escalation', AdminOpsAlertService::EMAIL_TYPES);

        Setting::set('notifications.channels', 'mail,push');
        Notification::fake();
        Setting::set('alerts.email.dispute_refund_escalation', '0');

        $s = $this->paid('wallet');
        $this->limits('1000', '5000');
        Setting::set(BookingDisputeService::ESCALATE_HOURS_KEY, '2');
        $d = $this->dispute($s);
        $hq = $this->alertAdmin($this->holder('global'));
        $this->svc()->resolve($d, $this->holder('franchise', $s['franchise']->id), 'refund', 'n', 100, 'company');

        Carbon::setTestNow(now()->addHours(3));
        $this->svc()->escalateOverdue();

        Notification::assertSentToTimes($hq, AdminOpsAlertNotification::class, 1); // push only; the email is switched off
    }
}
