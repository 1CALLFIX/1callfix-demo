<?php

namespace Tests\Feature\Cancellation;

use App\Livewire\Customer\Orders\Show as CustomerOrderShow;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\BookingDispute;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\BookingDisputeService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * A2 — post-payment pricing dispute: customer raises it, an admin resolves it by hand, and any refund runs through the
 * manual-money approval model (permission, scope, limits, maker-checker, audit, idempotent) and is credited to the wallet.
 */
class BookingDisputeTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private const PERM = BookingDisputeService::PERMISSION;

    /** A completed, paid ₹500 booking. */
    private function paidBooking(): array
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'completed', 'payment_status' => 'paid']);
        Payment::create(['booking_id' => $s['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'gateway_order_id' => 'order_'.uniqid(), 'status' => 'captured']);
        $s['booking'] = $s['booking']->fresh();

        return $s;
    }

    private function raise(array $s, string $reason = 'He charged far too much for ten minutes of work'): BookingDispute
    {
        return app(BookingDisputeService::class)->raise($s['booking']->id, $s['customer']->id, $reason);
    }

    private function limits(?string $franchise, ?string $hq, ?string $dualAbove = null): void
    {
        foreach ([BookingDisputeService::FRANCHISE_LIMIT_KEY => $franchise, BookingDisputeService::HQ_LIMIT_KEY => $hq, BookingDisputeService::DUAL_APPROVAL_KEY => $dualAbove] as $k => $v) {
            $v === null ? Setting::clear($k, 'global', null) : Setting::set($k, $v);
        }
    }

    private function holder(string $scope, ?int $scopeId = null): User
    {
        return $this->makeUserWithPermission(self::PERM, $scope, $scopeId);
    }

    private function superAdmin(): User
    {
        return User::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Root', 'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'super_admin', 'status' => 'active']);
    }

    private function credits(BookingDispute $d): int
    {
        return WalletTransaction::where('ref', "booking:{$d->booking_id}:wallet-refund:dispute-{$d->id}")->count();
    }

    // ------------------------------------------------------------------ raising

    public function test_a_paid_closed_booking_can_be_disputed_and_it_creates_an_admin_item(): void
    {
        $s = $this->paidBooking();
        $d = $this->raise($s);

        $this->assertSame('open', $d->status);
        $this->assertSame('500.00', $d->amount_paid);
        $this->assertSame($s['franchise']->id, $d->franchise_id);
        $this->assertNotNull(ActivityLog::where('subject_type', BookingDispute::class)->where('subject_id', $d->id)->first(), 'audit-logged');
    }

    public function test_a_reason_is_required_and_the_booking_must_be_paid_closed_mine_and_not_already_disputed(): void
    {
        $s = $this->paidBooking();
        $svc = app(BookingDisputeService::class);

        try {
            $svc->raise($s['booking']->id, $s['customer']->id, '  ');
            $this->fail('reason required');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reason is required', $e->getMessage());
        }

        $this->raise($s);
        try {
            $this->raise($s);
            $this->fail('one open dispute per booking');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already have a dispute open', $e->getMessage());
        }

        $other = $this->paidBooking();
        try {
            $svc->raise($other['booking']->id, $s['customer']->id, 'not my booking at all');
            $this->fail('ownership');
        } catch (\RuntimeException $e) {
            $this->assertSame('This booking is not yours.', $e->getMessage());
        }

        $unpaid = $this->makeAssignedBookingScenario();
        $this->expectException(\RuntimeException::class);
        $svc->raise($unpaid['booking']->id, $unpaid['customer']->id, 'not even paid yet');
    }

    public function test_the_customer_order_page_raises_the_dispute(): void
    {
        $s = $this->paidBooking();

        Livewire::actingAs($s['customer'])->test(CustomerOrderShow::class, ['booking' => $s['booking']])
            ->assertSee('Think the price is wrong?')
            ->set('pricingDisputeReason', 'Overpriced for what was done')
            ->call('raisePricingDispute')
            ->assertSee('You raised a dispute');

        $this->assertSame(1, BookingDispute::where('booking_id', $s['booking']->id)->count());
    }

    // ------------------------------------------------------------------ resolving

    public function test_admin_resolution_is_recorded_with_note_and_moves_no_money(): void
    {
        $s = $this->paidBooking();
        $d = $this->raise($s);
        $admin = $this->superAdmin();
        $svc = app(BookingDisputeService::class);

        $this->assertFalse($svc->resolve($d, $admin, 'no_change', ' ')['ok'], 'a note is required');

        $this->assertTrue($svc->resolve($d, $admin, 'no_change', 'Spoke to both; price matches the quote')['ok']);
        $d = $d->fresh();
        $this->assertSame('resolved', $d->status);
        $this->assertSame('no_change', $d->outcome);
        $this->assertSame($admin->id, $d->resolved_by_id);
        $this->assertNull($d->refund_status);
        $this->assertSame(0, WalletTransaction::where('ref', 'like', '%:wallet-refund:dispute-%')->count());
        $this->assertFalse($svc->resolve($d, $admin, 'no_change', 'again')['ok'], 'already resolved');
    }

    public function test_only_a_permitted_user_can_resolve_and_a_franchise_holder_is_scoped_to_their_franchise(): void
    {
        $s = $this->paidBooking();
        $d = $this->raise($s);
        $svc = app(BookingDisputeService::class);

        $this->assertFalse($svc->resolve($d, $this->makeCustomer(), 'no_change', 'nope')['ok']);

        $other = $this->paidBooking();
        $outsider = $this->holder('franchise', $other['franchise']->id);
        $this->assertFalse($svc->resolve($d, $outsider, 'no_change', 'wrong franchise')['ok']);
        $this->assertNull($svc->availableAction($d, $outsider));

        $insider = $this->holder('franchise', $s['franchise']->id);
        $this->assertTrue($svc->resolve($d, $insider, 'no_change', 'own franchise')['ok']);
    }

    // ------------------------------------------------------------------ refund: approval model only

    public function test_a_refund_decision_moves_no_money_until_requested_and_approved(): void
    {
        $this->limits('100', '1000');
        $s = $this->paidBooking();
        $d = $this->raise($s);
        $admin = $this->superAdmin();
        $svc = app(BookingDisputeService::class);

        $this->assertFalse($svc->resolve($d, $admin, 'refund', 'refund too much', 501)['ok'], 'cannot exceed what was paid');
        $this->assertTrue($svc->resolve($d, $admin, 'refund', 'Overcharged by half', 250)['ok']);

        $d = $d->fresh();
        $this->assertSame('awaiting_request', $d->refund_status);
        $this->assertSame(0, $this->credits($d), 'deciding is not paying');
    }

    public function test_a_request_within_limit_with_no_second_approval_credits_the_wallet_once_and_is_idempotent(): void
    {
        $this->limits('300', '1000');
        $s = $this->paidBooking();
        $d = $this->raise($s);
        $holder = $this->holder('franchise', $s['franchise']->id);
        $svc = app(BookingDisputeService::class);
        $svc->resolve($d, $holder, 'refund', 'Overcharged', 250);

        $this->assertFalse($svc->requestRefund($d, $holder, ' ')['ok'], 'reason required');
        $this->assertTrue($svc->requestRefund($d, $holder, 'Approved by franchise manager')['ok']);

        $this->assertSame(1, $this->credits($d));
        $this->assertSame(250.0, (float) app(WalletService::class)->balance($s['customer']));
        $this->assertSame('refunded', $d->fresh()->refund_status);

        $this->assertFalse($svc->requestRefund($d, $holder, 'again')['ok'], 'idempotent: already refunded');
        $this->assertFalse($svc->retry($d, $holder)['ok']);
        $this->assertSame(1, $this->credits($d), 'never a second credit');
    }

    public function test_above_the_franchise_limit_a_different_hq_user_must_approve(): void
    {
        $this->limits('100', '1000');
        $s = $this->paidBooking();
        $d = $this->raise($s);
        $franchiseUser = $this->holder('franchise', $s['franchise']->id);
        $hq = $this->holder('global');
        $svc = app(BookingDisputeService::class);
        $svc->resolve($d, $franchiseUser, 'refund', 'Overcharged', 250);

        $this->assertTrue($svc->requestRefund($d, $franchiseUser, 'Please approve')['ok']);
        $this->assertSame('awaiting_approval', $d->fresh()->refund_status);
        $this->assertSame(0, $this->credits($d));

        $this->assertFalse($svc->approveRefund($d, $franchiseUser, 'self')['ok'], 'the requester cannot approve their own request');
        $this->assertNull($svc->availableAction($d->fresh(), $franchiseUser));

        $this->assertTrue($svc->approveRefund($d, $hq, 'Checked the invoice')['ok']);
        $this->assertSame(1, $this->credits($d));
        $this->assertSame($hq->id, $d->fresh()->refund_approved_by_id);
    }

    public function test_maker_checker_above_threshold_blocks_self_approval_even_when_within_limit(): void
    {
        $this->limits('1000', '1000', '100');
        $s = $this->paidBooking();
        $d = $this->raise($s);
        $a = $this->holder('global');
        $b = $this->holder('global');
        $svc = app(BookingDisputeService::class);
        $svc->resolve($d, $a, 'refund', 'Overcharged', 250);

        $this->assertTrue($svc->requestRefund($d, $a, 'request')['ok']);
        $this->assertSame('awaiting_approval', $d->fresh()->refund_status, 'above the threshold: never auto-executes');
        $this->assertFalse($svc->approveRefund($d, $a, 'self')['ok']);
        $this->assertTrue($svc->approveRefund($d, $b, 'second pair of eyes')['ok']);
        $this->assertSame(1, $this->credits($d));
    }

    public function test_null_limits_mean_nobody_below_super_admin_can_approve_and_super_admin_can(): void
    {
        $this->limits(null, null);
        $s = $this->paidBooking();
        $d = $this->raise($s);
        $hq = $this->holder('global');
        $svc = app(BookingDisputeService::class);
        $svc->resolve($d, $hq, 'refund', 'Overcharged', 50);

        $this->assertTrue($svc->requestRefund($d, $hq, 'request')['ok']);
        $this->assertSame('awaiting_approval', $d->fresh()->refund_status);
        $this->assertSame(0, $this->credits($d));

        $other = $this->holder('global');
        $this->assertFalse($svc->approveRefund($d, $other, 'no limit configured')['ok']);
        $this->assertTrue($svc->approveRefund($d, $this->superAdmin(), 'owner approves')['ok']);
        $this->assertSame(1, $this->credits($d));
    }

    public function test_rejecting_sends_it_back_and_every_step_is_audit_logged(): void
    {
        $this->limits('100', '1000');
        $s = $this->paidBooking();
        $d = $this->raise($s);
        $f = $this->holder('franchise', $s['franchise']->id);
        $hq = $this->holder('global');
        $svc = app(BookingDisputeService::class);
        $svc->resolve($d, $f, 'refund', 'Overcharged', 250);
        $svc->requestRefund($d, $f, 'request');

        $this->assertTrue($svc->rejectRefund($d, $hq, 'Amount too high')['ok']);
        $d = $d->fresh();
        $this->assertSame('awaiting_request', $d->refund_status);
        $this->assertNull($d->refund_requested_by_id);
        $this->assertSame(0, $this->credits($d));

        $this->assertGreaterThanOrEqual(3, ActivityLog::where('subject_type', BookingDispute::class)->where('subject_id', $d->id)->count());
    }

    public function test_escalation_raises_the_level_once_per_interval_and_is_off_while_unset(): void
    {
        $this->limits('100', '1000');
        $s = $this->paidBooking();
        $d = $this->raise($s);
        $f = $this->holder('franchise', $s['franchise']->id);
        $svc = app(BookingDisputeService::class);
        $svc->resolve($d, $f, 'refund', 'Overcharged', 50);   // within franchise limit -> handler level 1
        $d->update(['resolved_at' => now()->subHours(30)]);

        $this->assertSame(0, $svc->escalateOverdue(), 'off while unset');

        Setting::set(BookingDisputeService::ESCALATE_HOURS_KEY, '24');
        $this->assertSame(1, $svc->escalateOverdue());
        $this->assertSame(2, $d->fresh()->escalation_level);
        $this->assertSame(0, $svc->escalateOverdue(), 'once per level');
    }
}
