<?php

namespace Tests\Feature\Payments;

use App\Actions\CustomerCancelBookingAction;
use App\Actions\DisputeInterimDeclarationAction;
use App\Actions\PlaceBookingOnHoldAction;
use App\Actions\ResumeBookingAction;
use App\Livewire\Bookings\Show as BookingShow;
use App\Livewire\ChargeWaivers\Index as WaiverQueue;
use App\Models\Booking;
use App\Models\BookingCancellationRequest;
use App\Models\ChargeWaiver;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Services\ChargeWaiverService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * F1/F2 (function check 2026-10-17): waiving a customer charge was gated only by bookings.reassign / bookings.cancel,
 * with no limit and no second person. It now runs through the manual money approval model (CLAUDE.md).
 */
class ChargeWaiverApprovalTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('cancellation.interim_cap_percent', '50');
        Setting::set('cancellation.visit_fee_type', 'flat');
        Setting::set('cancellation.visit_fee_value', '50');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(string $roleSlug, array $permissions, string $scopeType, ?int $scopeId): User
    {
        $user = User::create(['uuid' => (string) Str::uuid(), 'name' => ucfirst($roleSlug), 'phone' => '8'.fake()->unique()->numerify('#########'), 'role' => 'operator', 'status' => 'active']);
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug, 'is_system' => false]);
        $role->permissions()->syncWithoutDetaching(Permission::whereIn('slug', $permissions)->pluck('id'));
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $scopeType, 'scope_id' => $scopeId]);

        return $user;
    }

    private function superAdmin(): User
    {
        return User::create(['uuid' => (string) Str::uuid(), 'name' => 'Super', 'phone' => '8'.fake()->unique()->numerify('#########'), 'role' => 'super_admin', 'status' => 'active']);
    }

    /** A job on hold for 11 days whose customer cancellation is waiting for an admin (charge 100: work done). */
    private function pendingCancellationRequest(): array
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'in_progress', 'start_otp_verified_at' => now()]);
        Carbon::setTestNow(now()->subDays(11));
        app(PlaceBookingOnHoldAction::class)->execute($s['booking']->id, 'awaiting_spares', 'Waiting for the part', [
            'work_amount' => 100, 'sourced_by' => 'provider', 'expected_at' => now()->addDays(2)->toDateString(),
        ]);
        Carbon::setTestNow();

        $quote = app(CustomerCancelBookingAction::class)->quote($s['booking']->fresh());
        app(CustomerCancelBookingAction::class)->execute($s['booking']->id, $s['customer']->id, 'Changed my mind', $quote['token']);

        return $s + ['request' => BookingCancellationRequest::firstOrFail()];
    }

    public function test_an_operator_without_the_permission_cannot_waive_a_cancellation_charge(): void
    {
        $s = $this->pendingCancellationRequest();
        $operator = $this->staff('ops', ['bookings.view', 'bookings.reassign', 'bookings.cancel'], 'franchise', $s['franchise']->id);

        Livewire::actingAs($operator)->test(BookingShow::class, ['bookingId' => $s['booking']->id])
            ->set('waiveReason', 'Goodwill')
            ->call('waiveCancellationCharge', $s['request']->id)
            ->assertSet('flashType', 'error');

        $this->assertSame('awaiting_admin', $s['request']->fresh()->status);
        $this->assertSame(0, ChargeWaiver::count());
    }

    public function test_within_own_limit_it_applies_at_once_and_is_recorded(): void
    {
        Setting::set(ChargeWaiverService::FRANCHISE_LIMIT_KEY, '500');
        $s = $this->pendingCancellationRequest();
        $finance = $this->staff('franchise_finance', ['bookings.view', 'bookings.waive_charge'], 'franchise', $s['franchise']->id);

        Livewire::actingAs($finance)->test(BookingShow::class, ['bookingId' => $s['booking']->id])
            ->set('waiveReason', 'Goodwill after call')
            ->call('waiveCancellationCharge', $s['request']->id)
            ->assertSet('flashType', 'success');

        $this->assertSame('waived', $s['request']->fresh()->status);
        $this->assertSame('cancelled', $s['booking']->fresh()->status);
        $w = ChargeWaiver::firstOrFail();
        $this->assertSame(ChargeWaiver::APPLIED, $w->status);
        $this->assertSame($finance->id, $w->approved_by_id);
    }

    public function test_above_the_limit_it_waits_for_a_different_approver(): void
    {
        Setting::set(ChargeWaiverService::FRANCHISE_LIMIT_KEY, '50');
        Setting::set(ChargeWaiverService::HQ_LIMIT_KEY, '1000');
        $s = $this->pendingCancellationRequest();
        $franchiseFinance = $this->staff('franchise_finance', ['bookings.view', 'bookings.waive_charge'], 'franchise', $s['franchise']->id);
        $hqFinance = $this->staff('hq_finance', ['bookings.view', 'bookings.waive_charge'], 'global', null);

        Livewire::actingAs($franchiseFinance)->test(BookingShow::class, ['bookingId' => $s['booking']->id])
            ->set('waiveReason', 'Customer was unhappy')
            ->call('waiveCancellationCharge', $s['request']->id);

        $w = ChargeWaiver::firstOrFail();
        $this->assertSame(ChargeWaiver::AWAITING, $w->status);
        $this->assertSame('awaiting_admin', $s['request']->fresh()->status, 'nothing changes before approval');

        // the requester cannot approve their own request
        $this->assertFalse(app(ChargeWaiverService::class)->approve($w, $franchiseFinance, 'self')['ok']);

        Livewire::actingAs($hqFinance)->test(WaiverQueue::class)
            ->call('startAction', $w->id, 'approve')
            ->set('reason', 'Checked the call log')
            ->call('submitAction')
            ->assertSet('flashType', 'success');

        $this->assertSame(ChargeWaiver::APPLIED, $w->fresh()->status);
        $this->assertSame('waived', $s['request']->fresh()->status);
    }

    public function test_above_the_second_approval_amount_even_super_admin_needs_another_person(): void
    {
        Setting::set(ChargeWaiverService::DUAL_APPROVAL_KEY, '10');
        $s = $this->pendingCancellationRequest();
        $first = $this->superAdmin();

        $result = app(ChargeWaiverService::class)->request($s['booking'], 'cancellation_request', (float) $s['request']->total_charge, $first, 'Goodwill', $s['request']->id);

        $this->assertTrue($result['ok']);
        $this->assertSame(ChargeWaiver::AWAITING, $result['waiver']->status);
        $this->assertTrue(app(ChargeWaiverService::class)->approve($result['waiver'], $this->superAdmin(), 'Agreed')['ok']);
        $this->assertSame('waived', $s['request']->fresh()->status);
    }

    public function test_a_franchise_holder_never_sees_another_franchises_waivers(): void
    {
        $s = $this->pendingCancellationRequest();
        [, , $otherFranchise] = $this->makeFranchiseTree();
        $outsider = $this->staff('other_finance', ['bookings.view', 'bookings.waive_charge'], 'franchise', $otherFranchise->id);
        $w = app(ChargeWaiverService::class)->request($s['booking'], 'cancellation_request', 100, $this->superAdmin(), 'Goodwill', $s['request']->id);
        Setting::set(ChargeWaiverService::DUAL_APPROVAL_KEY, '1');

        $this->assertFalse(app(ChargeWaiverService::class)->canWaive($outsider, $s['booking']));
        $this->assertSame(0, app(ChargeWaiverService::class)->visibleTo(ChargeWaiver::query(), $outsider)->count());
    }

    public function test_admin_cancel_with_waive_ticked_keeps_the_fee_until_approved_then_returns_it(): void
    {
        Setting::set(ChargeWaiverService::HQ_LIMIT_KEY, '1000');
        ['booking' => $booking, 'customer' => $customer, 'franchise' => $franchise] = $this->makeAssignedBookingScenario();
        $booking->update(['payment_method' => 'wallet', 'price_quoted' => 500]);
        Payment::create(['booking_id' => $booking->id, 'user_id' => $customer->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'wallet', 'status' => 'captured']);
        Setting::set('cancellation.free_minutes', '0');
        Setting::set('cancellation.fee_type', 'flat');
        Setting::set('cancellation.fee_value', '100');

        $operator = $this->staff('ops_cancel', ['bookings.view', 'bookings.cancel', 'bookings.waive_charge'], 'franchise', $franchise->id); // no franchise limit set

        Livewire::actingAs($operator)->test(BookingShow::class, ['bookingId' => $booking->id])
            ->set('cancelReason', 'Customer called to cancel')
            ->set('waiveFee', true)
            ->call('cancel')
            ->assertSet('flashType', 'success');

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $fee = (float) $booking->cancellation_fee;
        $this->assertEqualsWithDelta(100, $fee, 0.001, 'no franchise limit is set, so the operator cannot waive at once: the fee is kept');
        $w = ChargeWaiver::where('kind', 'cancel_fee')->firstOrFail();
        $this->assertSame(ChargeWaiver::AWAITING, $w->status);
        $walletBefore = app(WalletService::class)->balance($customer);

        $this->assertTrue(app(ChargeWaiverService::class)->approve($w, $this->staff('hq', ['bookings.waive_charge'], 'global', null), 'Our fault')['ok']);

        $this->assertEquals(0, $booking->fresh()->cancellation_fee);
        $this->assertEqualsWithDelta($walletBefore + $fee, app(WalletService::class)->balance($customer), 0.001);

        // idempotent: approving again is refused, the customer is not credited twice
        $this->assertFalse(app(ChargeWaiverService::class)->approve($w->fresh(), $this->superAdmin(), 'again')['ok']);
        $this->assertEqualsWithDelta($walletBefore + $fee, app(WalletService::class)->balance($customer), 0.001);
    }

    public function test_admin_cancel_waive_without_the_permission_is_refused_and_nothing_is_cancelled(): void
    {
        Setting::set('cancellation.free_minutes', '0');
        Setting::set('cancellation.fee_type', 'flat');
        Setting::set('cancellation.fee_value', '100');
        ['booking' => $booking, 'franchise' => $franchise] = $this->makeAssignedBookingScenario();
        $booking->update(['price_quoted' => 500]);
        $operator = $this->staff('ops_only', ['bookings.view', 'bookings.cancel'], 'franchise', $franchise->id);

        Livewire::actingAs($operator)->test(BookingShow::class, ['bookingId' => $booking->id])
            ->set('cancelReason', 'Customer called')
            ->set('waiveFee', true)
            ->call('cancel')
            ->assertSet('flashType', 'error');

        $this->assertNotSame('cancelled', $booking->fresh()->status);
    }

    public function test_lowering_a_disputed_work_amount_is_a_waiver(): void
    {
        ['booking' => $booking, 'customer' => $customer, 'franchise' => $franchise] = $this->makeAssignedBookingScenario();
        $booking->update(['status' => 'in_progress', 'start_otp_verified_at' => now()]);
        Carbon::setTestNow(now()->subDays(11));
        app(PlaceBookingOnHoldAction::class)->execute($booking->id, 'awaiting_spares', 'Part', ['work_amount' => 200, 'sourced_by' => 'provider', 'expected_at' => now()->addDays(2)->toDateString()]);
        Carbon::setTestNow();
        app(ResumeBookingAction::class)->execute($booking->id);
        app(PlaceBookingOnHoldAction::class)->execute($booking->id, 'awaiting_spares', 'Part', ['work_amount' => 200, 'sourced_by' => 'provider', 'expected_at' => now()->addDays(11)->toDateString()]);
        app(DisputeInterimDeclarationAction::class)->execute($booking->id, $customer->id, 'Not that much work');
        $before = (float) $booking->fresh()->interim_amount;

        $operator = $this->staff('ops_dispute', ['bookings.view', 'bookings.reassign'], 'franchise', $franchise->id);
        Livewire::actingAs($operator)->test(BookingShow::class, ['bookingId' => $booking->id])
            ->set('disputeResolution', 'Photos show less')
            ->set('disputeAmount', (string) ($before - 50))
            ->call('resolveDispute')
            ->assertSet('flashType', 'error');
        $this->assertSame('open', $booking->fresh()->interim_dispute_status);

        // keeping the declared amount is not a waiver: the operator may close it
        Livewire::actingAs($operator)->test(BookingShow::class, ['bookingId' => $booking->id])
            ->set('disputeResolution', 'Figures are right')
            ->call('resolveDispute')
            ->assertSet('flashType', 'success');
        $this->assertSame('resolved', $booking->fresh()->interim_dispute_status);
    }

    public function test_overdue_waivers_escalate_once_per_level(): void
    {
        Setting::set(ChargeWaiverService::FRANCHISE_LIMIT_KEY, '500');
        Setting::set(ChargeWaiverService::HQ_LIMIT_KEY, '1000');
        Setting::set(ChargeWaiverService::DUAL_APPROVAL_KEY, '1');
        Setting::set(ChargeWaiverService::ESCALATE_HOURS_KEY, '4');
        $s = $this->pendingCancellationRequest();
        $w = app(ChargeWaiverService::class)->request($s['booking'], 'cancellation_request', 100, $this->superAdmin(), 'Goodwill', $s['request']->id)['waiver'];

        $this->assertSame(0, app(ChargeWaiverService::class)->escalateOverdue());
        Carbon::setTestNow(now()->addHours(5));
        $this->assertSame(1, app(ChargeWaiverService::class)->escalateOverdue());
        $this->assertSame(0, app(ChargeWaiverService::class)->escalateOverdue());
        $this->assertSame(2, $w->fresh()->escalation_level);
    }
}
