<?php

namespace Tests\Feature\Providers;

use App\Actions\AcceptBookingAction;
use App\Actions\AdminReassignBookingAction;
use App\Contracts\PaymentGateway;
use App\Livewire\Bookings\Show as AdminBookingShow;
use App\Livewire\Provider\WalletDues;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\DispatchAttempt;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\ProviderCommissionReceivable;
use App\Models\Setting;
use App\Models\User;
use App\Services\DispatchService;
use App\Services\PayoutService;
use App\Services\Providers\ProviderStanding;
use App\Services\WalletService;
use App\Services\WalletTopUpService;
use App\Support\ProviderContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Audit 2026-10-15 L1/L2/L3/L5/L6/L8 + the admin WhatsApp fallback (owner decisions 2026-10-16): one standing rule
 * (ProviderStanding) for offers, acceptance and admin assign; a pay-dues path; withdrawals keep the floor.
 */
class ProviderStandingTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function scenario(): array
    {
        $s = $this->makeBookingScenario();
        $s['provider'] = $this->makeEligible($s['provider'], $s['category']->id);

        return $s;
    }

    private function fund(Provider $provider, float $amount): void
    {
        app(WalletService::class)->credit($provider->user, $amount, 'Test funds', 'test:'.Str::uuid());
    }

    /** One unpaid cash-commission row on its own completed cash job (receivables are one per booking). */
    private function owe(Provider $provider, Booking $booking, float $amount, float $franchisePart = 0): ProviderCommissionReceivable
    {
        $job = $booking->replicate(['code']);
        $job->code = 'CSH-'.Str::upper(Str::random(10));
        $job->fill(['status' => 'completed', 'payment_method' => 'cash', 'provider_id' => $provider->id])->save();
        $commission = Commission::create(['booking_id' => $job->id, 'provider_commission' => 0, 'franchise_commission' => $franchisePart, 'platform_commission' => $amount - $franchisePart]);

        return ProviderCommissionReceivable::create([
            'provider_id' => $provider->id, 'booking_id' => $job->id, 'commission_id' => $commission->id,
            'platform_portion' => $amount - $franchisePart, 'franchise_portion' => $franchisePart,
            'amount_owed' => $amount, 'amount_settled' => 0, 'franchise_settled' => 0, 'status' => 'outstanding',
        ]);
    }

    private function candidateIds(Booking $booking): array
    {
        return app(DispatchService::class)->findCandidates($booking->fresh())->pluck('provider.id')->all();
    }

    private function superAdmin(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Super Admin', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'super_admin', 'status' => 'active',
        ]);
    }

    // ---------------------------------------------------------------- floor (L1/L2)

    public function test_a_provider_below_an_active_floor_gets_no_offer(): void
    {
        $s = $this->scenario();
        Setting::set('wallet.provider_min_balance_to_accept_jobs', '500');
        $this->fund($s['provider'], 200);

        $this->assertNotContains($s['provider']->id, $this->candidateIds($s['booking']));

        $this->fund($s['provider'], 300);
        $this->assertContains($s['provider']->id, $this->candidateIds($s['booking']));
    }

    public function test_the_floor_switch_turns_the_floor_off_without_losing_the_amount(): void
    {
        $s = $this->scenario();
        Setting::set('wallet.provider_min_balance_to_accept_jobs', '500');
        Setting::set('wallet.provider_min_balance_enabled', '0');

        $this->assertContains($s['provider']->id, $this->candidateIds($s['booking']));
        $this->assertNull(app(ProviderStanding::class)->floor($s['provider']));
    }

    public function test_floor_counts_dues_only_when_switched_on(): void
    {
        $s = $this->scenario();
        Setting::set('wallet.provider_min_balance_to_accept_jobs', '500');
        $this->fund($s['provider'], 600);
        $this->owe($s['provider'], $s['booking'], 200);

        $this->assertContains($s['provider']->id, $this->candidateIds($s['booking']), 'balance 600 ≥ 500');

        Setting::set('wallet.provider_floor_counts_dues', '1');
        $this->assertNotContains($s['provider']->id, $this->candidateIds($s['booking']), '600 − 200 = 400 < 500');
    }

    // ---------------------------------------------------------------- cash dues (L3)

    public function test_dues_above_the_limit_stop_offers_and_acceptance(): void
    {
        $s = $this->scenario();
        Setting::set('wallet.provider_max_cash_dues', '1000');
        $this->owe($s['provider'], $s['booking'], 1000);

        $this->assertContains($s['provider']->id, $this->candidateIds($s['booking']), 'equal to the limit is allowed');

        $this->owe($s['provider'], $s['booking'], 0.5);
        $this->assertNotContains($s['provider']->id, $this->candidateIds($s['booking']));

        DispatchAttempt::create(['booking_id' => $s['booking']->id, 'provider_id' => $s['provider']->id, 'status' => 'notified', 'notified_at' => now()]);
        try {
            app(AcceptBookingAction::class)->execute($s['booking']->id, $s['provider']);
            $this->fail('acceptance should be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Unpaid cash commission ₹1,000.50 is above the limit of ₹1,000.00', $e->getMessage());
        }
        $this->assertNull($s['booking']->fresh()->provider_id);
    }

    public function test_no_limit_set_means_dues_never_block(): void
    {
        $s = $this->scenario();
        $this->owe($s['provider'], $s['booking'], 5000);

        $this->assertContains($s['provider']->id, $this->candidateIds($s['booking']));
    }

    // ---------------------------------------------------------------- suspension (L6)

    public function test_a_suspended_account_gets_no_offer(): void
    {
        $s = $this->scenario();
        $s['provider']->user->update(['status' => 'suspended']);

        $this->assertNotContains($s['provider']->id, $this->candidateIds($s['booking']));
        $this->assertFalse(app(DispatchService::class)->providerEligibleForBooking($s['provider']->fresh(), $s['booking']));
    }

    // ---------------------------------------------------------------- admin assign (L5)

    public function test_admin_assign_refuses_an_ineligible_provider_without_a_reason(): void
    {
        $s = $this->makeBookingScenario(); // provider has no skill for this category

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Does not offer this service category');
        app(AdminReassignBookingAction::class)->execute($s['booking']->id, $s['provider']->id);
    }

    public function test_admin_assign_with_a_reason_assigns_and_logs_the_override(): void
    {
        Notification::fake();
        $s = $this->makeBookingScenario();
        $admin = $this->superAdmin();

        $booking = app(AdminReassignBookingAction::class)->execute($s['booking']->id, $s['provider']->id, '', 'Customer asked for this technician', $admin);

        $this->assertSame($s['provider']->id, $booking->provider_id);
        $log = ActivityLog::where('description', 'Admin assigned an ineligible provider (override)')->firstOrFail();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame('Customer asked for this technician', $log->properties['reason']);
        $this->assertStringContainsString('Override', $booking->statusHistory()->latest('id')->first()->note);
    }

    public function test_admin_assign_of_an_eligible_offline_provider_needs_no_reason(): void
    {
        Notification::fake();
        $s = $this->scenario();
        $s['provider']->update(['is_online' => false]);

        $booking = app(AdminReassignBookingAction::class)->execute($s['booking']->id, $s['provider']->id);

        $this->assertSame('assigned', $booking->status);
        $this->assertSame(0, ActivityLog::where('description', 'Admin assigned an ineligible provider (override)')->count());
    }

    // ---------------------------------------------------------------- pay dues (L8)

    public function test_dues_payment_amount_restores_standing_and_capture_clears_dues_oldest_first(): void
    {
        $s = $this->scenario();
        $owner = $this->superAdmin();
        $s['franchise']->update(['owner_user_id' => $owner->id]);
        Setting::set('wallet.provider_min_balance_to_accept_jobs', '500');
        $this->fund($s['provider'], 100);
        $first = $this->owe($s['provider'], $s['booking'], 300, 100);
        $second = $this->owe($s['provider'], $s['booking'], 200);

        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('identifier')->andReturn('razorpay');
        $gateway->shouldReceive('createRawOrder')->once()->withArgs(fn ($amount) => (float) $amount === 900.0)
            ->andReturn(['razorpay_order_id' => 'order_dues_1', 'key_id' => 'k', 'amount' => 90000, 'currency' => 'INR']);
        $this->app->instance(PaymentGateway::class, $gateway);

        $order = app(WalletTopUpService::class)->requestProviderDuesPayment($s['provider']->fresh());
        $payment = Payment::findOrFail($order['payment_id']);
        $this->assertEquals(900, $payment->amount); // dues 500 + floor 500 − balance 100

        $payment->update(['status' => 'captured']);
        app(WalletTopUpService::class)->creditWalletForCapturedTopUp($payment->fresh());

        $this->assertSame('settled', $first->fresh()->status);
        $this->assertSame('settled', $second->fresh()->status);
        $this->assertEquals(500, app(WalletService::class)->balance($s['provider']->user));
        $this->assertEquals(100, app(WalletService::class)->balance($owner)); // franchise share of the first row
        $this->assertSame([], app(ProviderStanding::class)->blockers($s['provider']->fresh()));

        // A second sweep moves nothing.
        app(PayoutService::class)->settleProviderDebts($s['provider']->id);
        $this->assertEquals(500, app(WalletService::class)->balance($s['provider']->user));
    }

    public function test_nothing_to_pay_is_refused(): void
    {
        $s = $this->scenario();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nothing to pay');
        app(WalletTopUpService::class)->requestProviderDuesPayment($s['provider']);
    }

    public function test_provider_can_settle_dues_from_the_wallet_on_the_wallet_page(): void
    {
        $s = $this->scenario();
        $this->fund($s['provider'], 250);
        $row = $this->owe($s['provider'], $s['booking'], 400);

        Livewire::actingAs($s['provider']->user)->test(WalletDues::class)
            ->assertSee('Unpaid cash commission')
            ->call('settleFromWallet');

        $this->assertEquals(250, (float) $row->fresh()->amount_settled);
        $this->assertSame('outstanding', $row->fresh()->status);
        $this->assertEquals(0, app(WalletService::class)->balance($s['provider']->user));
    }

    public function test_a_withdrawal_must_leave_the_active_floor(): void
    {
        $s = $this->scenario();
        Setting::set('wallet.provider_min_balance_to_accept_jobs', '500');
        $this->fund($s['provider'], 800);

        try {
            app(PayoutService::class)->request('provider', $s['provider']->id, 400);
            $this->fail('payout below the floor should be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('You can withdraw up to 300.00', $e->getMessage());
        }

        app(PayoutService::class)->request('provider', $s['provider']->id, 300);
        $this->assertEquals(500, app(WalletService::class)->balance($s['provider']->user));
    }

    // ---------------------------------------------------------------- WhatsApp / Call fallback

    public function test_offer_message_never_carries_customer_identity_or_full_address(): void
    {
        $s = $this->scenario();
        $s['address']->update(['address_line' => 'Flat 12, Secret Street', 'landmark' => 'Near Clock Tower', 'city' => 'Nellore']);
        $s['customer']->update(['name' => 'Ravi Kumar Testcustomer']);
        Setting::set('ops.whatsapp_business_number', '9014609609');

        $text = ProviderContactMessage::offer($s['booking']->fresh(), $s['provider']);

        $this->assertStringContainsString($s['booking']->code, $text);
        $this->assertStringContainsString('Near Clock Tower, Nellore', $text);
        $this->assertStringContainsString('1CallFix Operations, 9014609609', $text);
        $this->assertStringNotContainsString('Secret Street', $text);
        $this->assertStringNotContainsString($s['customer']->phone, $text);
        $this->assertStringNotContainsString('Ravi Kumar', $text);
        $this->assertSame('https://wa.me/91'.substr($s['provider']->user->phone, -10).'?text='.rawurlencode($text),
            ProviderContactMessage::whatsappUrl($s['provider']->user->phone, $text));
    }

    public function test_contact_clicks_are_logged_only_for_eligible_providers(): void
    {
        $s = $this->scenario();
        $blocked = $this->makeProviderIn($s['franchise'], $s['zone']); // no skill → not eligible
        $admin = $this->superAdmin();

        $page = Livewire::actingAs($admin)->test(AdminBookingShow::class, ['bookingId' => $s['booking']->id])
            ->assertSee('Reach a provider directly')
            ->call('logContact', $s['provider']->id, 'whatsapp')
            ->call('logContact', $blocked->id, 'whatsapp');

        $logs = ActivityLog::where('description', 'Admin sent provider a WhatsApp')->get();
        $this->assertCount(1, $logs);
        $this->assertSame($s['provider']->id, $logs->first()->properties['provider_id']);
        $page->assertSee('Contact log');
    }

    public function test_suspending_a_provider_takes_them_offline(): void
    {
        $s = $this->scenario();
        $admin = $this->superAdmin();

        Livewire::actingAs($admin)->test(\App\Livewire\Providers\Show::class, ['providerId' => $s['provider']->id])
            ->call('toggleAccountSuspended');

        $this->assertSame('suspended', $s['provider']->user->fresh()->status);
        $this->assertFalse((bool) $s['provider']->fresh()->is_online);
    }
}
