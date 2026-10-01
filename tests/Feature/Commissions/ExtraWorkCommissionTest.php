<?php

namespace Tests\Feature\Commissions;

use App\Actions\CompleteBookingAction;
use App\Models\BookingExtraItem;
use App\Models\Commission;
use App\Models\ProviderCommissionReceivable;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-EXTRAWORK-001 — approved extra work on a prepaid booking is paid to the provider on site, so the
 * provider wallet is credited only on what the platform collected and the platform/franchise share of the
 * extra becomes a receivable. Fixture: platform 5%, franchise 10% on a ₹500 booking.
 */
class ExtraWorkCommissionTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function extra(int $bookingId, float $amount, string $status, int $providerId): void
    {
        BookingExtraItem::create(['booking_id' => $bookingId, 'description' => 'Gas refill', 'amount' => $amount, 'status' => $status, 'added_by_provider_id' => $providerId]);
    }

    public function test_prepaid_booking_with_approved_extra_credits_only_the_collected_share_and_records_a_receivable(): void
    {
        $s = $this->makeAssignedBookingScenario(); // online, ₹500
        $this->extra($s['booking']->id, 100, 'approved', $s['provider']->id);

        app(CompleteBookingAction::class)->execute($s['booking']->id, $s['provider'], '5678');

        $c = Commission::where('booking_id', $s['booking']->id)->firstOrFail();
        $this->assertEquals(30.00, (float) $c->platform_commission);
        $this->assertEquals(60.00, (float) $c->franchise_commission);
        $this->assertEquals(510.00, (float) $c->provider_commission);

        // Same wallet credit as with no extra: ₹425. The ₹85 provider share of the extra was taken on site.
        $this->assertEquals(425.00, app(WalletService::class)->balance($s['provider']->user));

        $r = ProviderCommissionReceivable::where('booking_id', $s['booking']->id)->firstOrFail();
        $this->assertEquals(5.00, (float) $r->platform_portion);
        $this->assertEquals(10.00, (float) $r->franchise_portion);
        $this->assertEquals(15.00, (float) $r->amount_owed);
        $this->assertSame('outstanding', $r->status);
    }

    public function test_rejected_or_pending_extras_change_nothing(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $this->extra($s['booking']->id, 100, 'rejected', $s['provider']->id);

        app(CompleteBookingAction::class)->execute($s['booking']->id, $s['provider'], '5678');

        $this->assertSame(0, ProviderCommissionReceivable::where('booking_id', $s['booking']->id)->count());
        $this->assertEquals(425.00, app(WalletService::class)->balance($s['provider']->user));
    }

    public function test_cash_booking_with_extra_still_makes_exactly_one_receivable_for_the_whole_total(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['payment_method' => 'cash']);
        $this->extra($s['booking']->id, 100, 'approved', $s['provider']->id);

        app(CompleteBookingAction::class)->execute($s['booking']->id, $s['provider'], '5678');

        $r = ProviderCommissionReceivable::where('booking_id', $s['booking']->id)->firstOrFail();
        $this->assertEquals(90.00, (float) $r->amount_owed); // 30 + 60 on ₹600
        $this->assertEquals(0.0, app(WalletService::class)->balance($s['provider']->user));
    }
}
