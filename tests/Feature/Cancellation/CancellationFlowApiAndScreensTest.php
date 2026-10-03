<?php

namespace Tests\Feature\Cancellation;

use App\Livewire\Customer\Orders\Show as CustomerOrderShow;
use App\Livewire\Provider\Jobs\Show as ProviderJobShow;
use App\Models\Booking;
use App\Models\BookingQuote;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-CANCEL-POLICY-001 — the API endpoints (what the Flutter apps call), the provider and customer screens (which call the
 * very same Actions), and the customer-facing visit-charge sentence. Fee values are set by each test; none is assumed.
 */
class CancellationFlowApiAndScreensTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function enRoute(array $cfg = []): array
    {
        foreach (array_merge(['visit_fee_value' => 149, 'arrival_radius_meters' => 150], $cfg) as $k => $v) {
            Setting::set('cancellation.'.$k, (string) $v);
        }
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'provider_en_route', 'payment_status' => 'paid']);
        Payment::create(['booking_id' => $s['booking']->id, 'purpose' => 'booking', 'user_id' => $s['customer']->id, 'amount' => 500, 'gateway' => 'wallet', 'status' => 'captured']);
        $s['booking'] = $s['booking']->fresh();

        return $s;
    }

    // ------------------------------------------------------------------ API

    public function test_arrive_endpoint_verifies_the_radius_and_the_other_provider_gets_403(): void
    {
        $s = $this->enRoute();
        $api = fn () => $this->actingAs($s['provider']->user, 'sanctum');

        $api()->postJson("/api/bookings/{$s['booking']->id}/arrive", ['lat' => 1.05, 'lng' => 1.0])
            ->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'Move within 150 m'));

        $api()->postJson("/api/bookings/{$s['booking']->id}/arrive", ['lat' => 1.0, 'lng' => 1.0])
            ->assertOk()->assertJsonPath('message', 'Arrival verified.');

        $this->assertNotNull($s['booking']->fresh()->arrival_verified_at);

        $other = $this->makeProviderIn($s['franchise'], $s['zone']);
        $this->actingAs($other->user, 'sanctum')->postJson("/api/bookings/{$s['booking']->id}/arrive", ['lat' => 1.0, 'lng' => 1.0])->assertStatus(403);
        $this->actingAs($s['provider']->user, 'sanctum')->postJson("/api/bookings/{$s['booking']->id}/arrive", ['lat' => 999, 'lng' => 1.0])->assertStatus(422);
    }

    public function test_quote_call_attempt_and_provider_cancel_endpoints_use_the_same_rules(): void
    {
        $s = $this->enRoute(['no_show_wait_minutes' => 0, 'no_show_call_attempts' => 1]);
        $api = fn () => $this->actingAs($s['provider']->user, 'sanctum');

        $api()->postJson("/api/bookings/{$s['booking']->id}/quote", ['amount' => 900])->assertStatus(409); // not arrived yet
        $api()->postJson("/api/bookings/{$s['booking']->id}/provider-cancel", ['reason' => 'customer_unreachable'])
            ->assertStatus(409)->assertJsonPath('code', 'no_arrival');
        $api()->postJson("/api/bookings/{$s['booking']->id}/provider-cancel", ['reason' => 'nonsense'])->assertStatus(422);

        $api()->postJson("/api/bookings/{$s['booking']->id}/arrive", ['lat' => 1.0, 'lng' => 1.0])->assertOk();
        $api()->postJson("/api/bookings/{$s['booking']->id}/quote", ['amount' => 900])->assertOk()->assertJsonPath('quote.status', 'sent');
        $api()->postJson("/api/bookings/{$s['booking']->id}/call-attempt")->assertOk()->assertJsonPath('attempts', 1);

        $quote = BookingQuote::firstOrFail();

        // only the booking's own customer can answer
        $this->actingAs($this->makeCustomer(), 'sanctum')->postJson("/api/booking-quotes/{$quote->id}/respond", ['accept' => false])->assertStatus(404);
        $this->actingAs($s['customer'], 'sanctum')->postJson("/api/booking-quotes/{$quote->id}/respond", ['accept' => false])->assertOk()->assertJsonPath('quote.status', 'rejected');
        $this->actingAs($s['customer'], 'sanctum')->postJson("/api/booking-quotes/{$quote->id}/respond", ['accept' => true])->assertStatus(409); // final

        $api()->postJson("/api/bookings/{$s['booking']->id}/provider-cancel", ['reason' => 'quote_rejected'])
            ->assertOk()->assertJsonPath('charge', 149)->assertJsonPath('booking.status', 'cancelled');
    }

    public function test_the_policy_endpoint_returns_the_text_from_settings(): void
    {
        Setting::set('cancellation.visit_fee_value', '149');
        $s = $this->makeAssignedBookingScenario();

        $this->actingAs($s['customer'], 'sanctum')->getJson('/api/cancellation/policy')
            ->assertOk()
            ->assertJsonPath('visit_charge_text', 'Visit and inspection charge ₹149. It applies only if the professional arrives and no work is done. If the work is carried out, there is no visit charge.')
            ->assertJsonStructure(['lines']);
    }

    // ------------------------------------------------------------------ customer-facing text (booking, checkout, tracking)

    public function test_the_visit_charge_sentence_comes_from_settings_and_is_absent_while_unset(): void
    {
        $this->assertStringNotContainsString('Visit and inspection charge', Blade::render('<x-cancellation-policy :lines="[]" />'));

        Setting::set('cancellation.visit_fee_value', '149');
        $html = Blade::render('<x-cancellation-policy :lines="[]" />');
        $this->assertStringContainsString('Visit and inspection charge ₹149. It applies only if the professional arrives and no work is done. If the work is carried out, there is no visit charge.', $html);

        Setting::set('cancellation.visit_fee_value', '200');
        $this->assertStringContainsString('₹200', Blade::render('<x-cancellation-policy :lines="[]" />'));
    }

    public function test_the_tracking_page_shows_the_visit_charge_from_the_bookings_snapshot_and_the_pending_quote(): void
    {
        $s = $this->enRoute();
        Setting::set('cancellation.visit_fee_value', '999'); // changed after booking: the page must still show the booking's own value
        BookingQuote::create(['booking_id' => $s['booking']->id, 'provider_id' => $s['provider']->id, 'amount' => 900, 'status' => 'sent', 'sent_at' => now()]);

        $c = Livewire::actingAs($s['customer'])->test(CustomerOrderShow::class, ['booking' => $s['booking']])
            ->assertSee('Visit and inspection charge ₹149. It applies only if the professional arrives and no work is done. If the work is carried out, there is no visit charge.')
            ->assertDontSee('₹999')
            ->assertSee('Quote from your professional');

        $c->call('respondToQuote', BookingQuote::firstOrFail()->id, false)->assertSee('Quote declined.');
        $this->assertSame('rejected', BookingQuote::firstOrFail()->status);
    }

    public function test_the_customer_page_offers_the_invoice_and_credit_note_after_a_charged_cancellation(): void
    {
        $s = $this->enRoute(['en_route_fee' => 80]);
        $action = app(\App\Actions\CustomerCancelBookingAction::class);
        $action->execute($s['booking']->id, $s['customer']->id, 'x', $action->quote($s['booking']->fresh())['token']);

        Livewire::actingAs($s['customer'])->test(CustomerOrderShow::class, ['booking' => $s['booking']->id])
            ->assertSee('Download cancellation invoice')
            ->assertSee('Download credit note');
    }

    // ------------------------------------------------------------------ provider screen (mobile-first, same actions)

    public function test_the_provider_screen_checks_in_quotes_and_cancels_through_the_same_actions(): void
    {
        $s = $this->enRoute();
        $c = Livewire::actingAs($s['provider']->user)->test(ProviderJobShow::class, ['booking' => $s['booking']->id]);

        $c->assertSee("I've arrived", false)->assertSee('Cancel — my own reasons');

        $c->call('arrive', 1.5, 1.0)->assertSee('Move within 150 m');
        $this->assertNull($s['booking']->fresh()->arrival_verified_at);

        $c->call('arrive', 1.0, 1.0)->assertSee('Arrival verified');
        $this->assertNotNull($s['booking']->fresh()->arrival_verified_at);

        $c->assertSee('Quote the customer')->assertSee('Cancel — quote not accepted')->assertDontSee('Cancel — my own reasons');
        $c->set('quoteAmount', '900')->call('sendQuote')->assertSee('Quote sent to the customer.');
        $this->assertSame(1, BookingQuote::count());

        // provider cancelling before the customer answered is refused with the policy message
        $c->call('cancelJob', 'quote_rejected')->assertSee('has not rejected the quote yet');
        $this->assertSame('provider_en_route', $s['booking']->fresh()->status);
    }

    public function test_the_provider_can_cancel_for_own_reasons_before_arrival_from_the_screen(): void
    {
        $s = $this->enRoute();

        Livewire::actingAs($s['provider']->user)->test(ProviderJobShow::class, ['booking' => $s['booking']->id])
            ->call('cancelJob', 'own_reason')
            ->assertRedirect(route('provider.jobs.index'));

        $this->assertSame('cancelled', $s['booking']->fresh()->status);
        $this->assertSame('provider', $s['booking']->fresh()->cancelled_by_role);
    }
}
