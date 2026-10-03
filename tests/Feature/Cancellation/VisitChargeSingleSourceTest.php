<?php

namespace Tests\Feature\Cancellation;

use App\Actions\CheckInArrivalAction;
use App\Actions\CustomerCancelBookingAction;
use App\Livewire\CancellationPolicy\Manage;
use App\Livewire\Customer\Orders\Show as CustomerOrderShow;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Cancellation\CancellationPolicy;
use App\Services\Cancellation\PolicySettings;
use App\Services\Cancellation\PrimeWaiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * One visit charge: `cancellation.visit_fee_value` is the ONLY place the amount lives. The cancellation charge, the
 * amount a Prime membership waives, and every customer-facing text derive from it (frozen per booking in its policy
 * snapshot). Plus the optional launch-price display (`cancellation.visit_fee_regular` + editable wording).
 */
class VisitChargeSingleSourceTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function cfg(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::set('cancellation.'.$key, (string) $value);
        }
    }

    /** A booking whose professional has verifiably arrived. Settings must be set BEFORE (they freeze onto the booking). */
    private function arrived(): array
    {
        $this->cfg(['arrival_radius_meters' => 150]);
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'provider_en_route', 'payment_status' => 'paid']);
        Payment::create(['booking_id' => $s['booking']->id, 'purpose' => 'booking', 'user_id' => $s['customer']->id, 'amount' => 500, 'gateway' => 'wallet', 'status' => 'captured']);
        $s['booking'] = app(CheckInArrivalAction::class)->execute($s['booking']->id, $s['provider'], 1.0, 1.0);

        return $s;
    }

    private function quote(Booking $b): array
    {
        return app(CustomerCancelBookingAction::class)->quote($b->fresh());
    }

    private function policy(): CancellationPolicy
    {
        return app(CancellationPolicy::class);
    }

    private function makePrime(User $customer): void
    {
        $plan = Plan::create([
            'name' => 'Prime Test', 'slug' => 'prime-test-'.Str::random(6), 'plan_family' => 'customer_membership', 'scope_type' => 'global',
            'eligible_actor_type' => 'customer', 'billing_cycle' => 'annual', 'price' => 1999, 'stacking_strategy' => 'exclusive', 'is_active' => true,
            'waives_cancellation_visit_charges' => true,
        ]);
        Subscription::create(['subscribable_type' => User::class, 'subscribable_id' => $customer->id, 'plan_id' => $plan->id, 'status' => 'active']);
    }

    private function superAdmin(): User
    {
        $u = $this->makeCustomer();
        $u->forceFill(['role' => 'super_admin'])->save();

        return $u;
    }

    // ============================== single source ==============================

    public function test_the_charge_the_prime_waived_amount_and_the_text_all_follow_the_one_setting_and_existing_bookings_keep_their_snapshot(): void
    {
        $this->cfg(['visit_fee_value' => 199]);
        $a = $this->arrived();
        $this->assertSame(199.0, $this->quote($a['booking'])['charge']);
        $this->assertSame('Visit charge ₹199', $this->quote($a['booking'])['breakdown']['display']);
        $this->assertStringContainsString('Visit and inspection charge ₹199.', $this->policy()->visitChargeText($a['booking']->fresh()));

        // The owner changes the single setting: a NEW booking follows it everywhere ...
        $this->cfg(['visit_fee_value' => 149]);
        $b = $this->arrived();
        $this->assertSame(149.0, $this->quote($b['booking'])['charge']);
        $this->assertStringContainsString('₹149', $this->policy()->visitChargeText($b['booking']->fresh()));
        $this->assertContains('Visit and inspection charge ₹149. It applies only if the professional arrives and no work is done. If the work is carried out, there is no visit charge.', $this->policy()->policyLines($b['booking']->fresh()));

        // ... while the earlier booking keeps the policy it was made under.
        $this->assertSame(199.0, $this->quote($a['booking'])['charge']);
        $this->assertStringContainsString('₹199', $this->policy()->visitChargeText($a['booking']->fresh()));
        $this->assertStringNotContainsString('₹149', $this->policy()->visitChargeText($a['booking']->fresh()));

        // A Prime member's waived amount is that same snapshot number: charge 0, "waived ₹149", never a second value.
        $this->makePrime($b['customer']);
        $prime = $this->quote($b['booking']);
        $this->assertSame(0.0, $prime['charge']);
        $this->assertSame(149.0, $prime['breakdown']['prime_waived_amount']);
        $this->assertSame('Visit charge ₹149 — waived with your Prime membership', $prime['breakdown']['display']);

        $this->cfg(['visit_fee_value' => 99]);
        $this->assertSame(149.0, $this->quote($b['booking'])['breakdown']['prime_waived_amount'], 'the waiver of an existing booking does not move with the setting');
        $c = $this->arrived();
        $this->makePrime($c['customer']);
        $this->assertSame(99.0, $this->quote($c['booking'])['breakdown']['prime_waived_amount'], 'a new booking waives the new amount');
    }

    public function test_a_non_prime_customer_is_unaffected_by_the_waiver_fields(): void
    {
        $this->cfg(['visit_fee_value' => 149]);
        $s = $this->arrived();

        $quote = $this->quote($s['booking']);

        $this->assertSame(149.0, $quote['charge']);
        $this->assertFalse($quote['breakdown']['prime_waived']);
        $this->assertSame(0.0, $quote['breakdown']['prime_waived_amount']);
    }

    public function test_no_second_visit_charge_value_exists_anywhere_in_the_code(): void
    {
        // The Prime waiver carries no money: it is a yes/no on the plan, not an amount.
        $this->assertSame(['covers'], array_map(fn ($m) => $m->getName(), (new \ReflectionClass(PrimeWaiver::class))->getMethods(\ReflectionMethod::IS_PUBLIC)));

        // Exactly one registry key holds the charge itself.
        $moneyKeys = array_keys(array_filter(PolicySettings::REGISTRY, fn ($m, $k) => str_contains($k, 'visit_fee') && $m['type'] === 'decimal' && $k !== 'cancellation.visit_fee_regular', ARRAY_FILTER_USE_BOTH));
        $this->assertSame(['cancellation.visit_fee_value'], $moneyKeys);

        // No hard-coded 199 in application or config code (only the QA demo catalog uses prices like that).
        $hits = [];
        foreach (['app', 'config'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir))) as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), 'Services'.DIRECTORY_SEPARATOR.'Qa')) {
                    continue;
                }
                if (preg_match('/(?<![\d.])199(?![\d.])/', (string) file_get_contents($file->getPathname()))) {
                    $hits[] = $file->getPathname();
                }
            }
        }
        $this->assertSame([], $hits, 'a literal 199 in code would be a second source for the visit charge');
    }

    // ============================== launch-price display ==============================

    public function test_launch_price_shows_charge_and_regular_everywhere_the_visit_charge_appears(): void
    {
        $this->cfg(['visit_fee_value' => 149, 'visit_fee_regular' => 199]);
        $s = $this->arrived();
        $b = $s['booking']->fresh();

        $this->assertSame('Visit charge ₹149 (launch price, regular ₹199)', $this->quote($b)['breakdown']['display']);
        $this->assertSame(149.0, $this->quote($b)['charge'], 'display only: the amount charged is the visit charge value');
        $this->assertSame('Visit charge ₹149 (launch price, regular ₹199). It applies only if the professional arrives and no work is done. If the work is carried out, there is no visit charge.', $this->policy()->visitChargeText($b));
        $this->assertContains($this->policy()->visitChargeText($b), $this->policy()->policyLines($b));

        // The customer's own cancel screen shows it too.
        Livewire::actingAs($s['customer'])->test(CustomerOrderShow::class, ['booking' => $b])
            ->assertSee('Visit charge ₹149 (launch price, regular ₹199). It applies only if the professional arrives and no work is done')
            ->call('openCancel')
            ->assertSee('Visit charge ₹149 (launch price, regular ₹199)');
    }

    public function test_without_a_regular_price_only_the_charge_is_shown(): void
    {
        $this->cfg(['visit_fee_value' => 149]);
        $b = $this->arrived()['booking']->fresh();

        $this->assertSame('Visit charge ₹149', $this->quote($b)['breakdown']['display']);
        $this->assertSame('Visit and inspection charge ₹149. It applies only if the professional arrives and no work is done. If the work is carried out, there is no visit charge.', $this->policy()->visitChargeText($b));
        $this->assertStringNotContainsString('regular', $this->policy()->visitChargeText($b));
    }

    public function test_a_regular_price_that_is_not_higher_or_a_percent_charge_shows_no_launch_text(): void
    {
        $this->cfg(['visit_fee_value' => 149, 'visit_fee_regular' => 149]);
        $this->assertStringNotContainsString('regular', (string) $this->policy()->visitChargeText($this->arrived()['booking']->fresh()));

        $this->cfg(['visit_fee_value' => 149, 'visit_fee_regular' => 100]);
        $this->assertStringNotContainsString('regular', (string) $this->policy()->visitChargeText($this->arrived()['booking']->fresh()));

        $this->cfg(['visit_fee_type' => 'percent', 'visit_fee_value' => 10, 'visit_fee_regular' => 20]);
        $text = $this->policy()->visitChargeText($this->arrived()['booking']->fresh());
        $this->assertStringContainsString('10% of the job price', $text);
        $this->assertStringNotContainsString('regular', $text);
    }

    public function test_the_launch_price_is_frozen_per_booking_and_an_old_snapshot_without_it_shows_none(): void
    {
        $this->cfg(['visit_fee_value' => 149]);
        $existing = $this->arrived()['booking'];

        $this->cfg(['visit_fee_regular' => 199]); // set later: must not appear on the booking made before it
        $this->assertStringNotContainsString('regular', (string) $this->policy()->visitChargeText($existing->fresh()));
        $this->assertSame(149.0, $this->quote($existing)['charge']);

        $fresh = $this->arrived()['booking']->fresh();
        $this->assertStringContainsString('regular ₹199', (string) $this->policy()->visitChargeText($fresh));

        // A booking whose frozen snapshot predates this setting entirely (no key at all) shows none either.
        $snapshot = $fresh->cancellation_policy_snapshot;
        unset($snapshot['cancellation.visit_fee_regular']);
        $fresh->forceFill(['cancellation_policy_snapshot' => $snapshot])->save();
        $this->assertStringNotContainsString('regular', (string) $this->policy()->visitChargeText($fresh->fresh()));

        // Later changes to the regular price do not touch the booking that froze it.
        $this->cfg(['visit_fee_regular' => 299]);
        $this->assertStringContainsString('regular ₹199', (string) $this->policy()->visitChargeText($this->arrivedWithRegular(199)));
    }

    private function arrivedWithRegular(int $regular): Booking
    {
        $this->cfg(['visit_fee_regular' => $regular]);
        $booking = $this->arrived()['booking']->fresh();
        $this->cfg(['visit_fee_regular' => 299]);

        return $booking->fresh();
    }

    // ============================== wording + admin screen ==============================

    public function test_the_launch_wording_is_editable_validated_and_audit_logged(): void
    {
        $admin = $this->superAdmin();
        $field = fn (string $k) => 'inputs.'.Manage::field($k);

        $component = Livewire::actingAs($admin)->test(Manage::class)
            ->set($field('cancellation.visit_fee_value'), '149')
            ->set($field('cancellation.visit_fee_regular'), '199')
            ->set($field('cancellation.visit_fee_launch_wording'), 'Launch offer: {charge} instead of {regular}');
        $component->call('save')->assertHasNoErrors();

        $this->assertSame('Launch offer: {charge} instead of {regular}', Setting::get('cancellation.visit_fee_launch_wording'));
        $this->assertSame('Launch offer: ₹149 instead of ₹199', $this->policy()->visitChargeLabel());
        $this->assertNotNull(ActivityLog::where('subject_type', 'setting')->where('properties->key', 'cancellation.visit_fee_launch_wording')->first());
        $this->assertNotNull(ActivityLog::where('subject_type', 'setting')->where('properties->key', 'cancellation.visit_fee_regular')->first());

        // Wording must keep both placeholders; nothing is saved when it does not.
        $component->set($field('cancellation.visit_fee_launch_wording'), 'Only {charge} here')->call('save')
            ->assertHasErrors($field('cancellation.visit_fee_launch_wording'));
        $this->assertSame('Launch offer: {charge} instead of {regular}', Setting::get('cancellation.visit_fee_launch_wording'));

        // Blank restores the default wording.
        $component->set($field('cancellation.visit_fee_launch_wording'), '')->call('save')->assertHasNoErrors();
        $this->assertSame('Visit charge ₹149 (launch price, regular ₹199)', $this->policy()->visitChargeLabel());
    }

    public function test_an_edited_wording_changes_text_but_never_the_amounts_of_an_existing_booking(): void
    {
        $this->cfg(['visit_fee_value' => 149, 'visit_fee_regular' => 199]);
        $b = $this->arrived()['booking']->fresh();
        $this->cfg(['visit_fee_value' => 50, 'visit_fee_launch_wording' => 'Now {charge}, usually {regular}']);

        $this->assertSame('Now ₹149, usually ₹199', $this->policy()->visitChargeLabel($b));
        $this->assertSame(149.0, $this->quote($b)['charge']);
    }

    public function test_the_regular_price_is_validated_on_the_screen(): void
    {
        $admin = $this->superAdmin();
        $field = fn (string $k) => 'inputs.'.Manage::field($k);

        Livewire::actingAs($admin)->test(Manage::class)
            ->set($field('cancellation.visit_fee_regular'), '-5')
            ->call('save')->assertHasErrors($field('cancellation.visit_fee_regular'));

        Livewire::actingAs($admin)->test(Manage::class)
            ->set($field('cancellation.visit_fee_type'), 'percent')
            ->set($field('cancellation.visit_fee_value'), '10')
            ->set($field('cancellation.visit_fee_regular'), '20')
            ->call('save')->assertHasErrors($field('cancellation.visit_fee_regular'));

        $this->assertNull(Setting::get('cancellation.visit_fee_regular'));
        $this->assertNull(PolicySettings::validate('cancellation.visit_fee_regular', '', 'flat'), 'blank = off');
    }

    public function test_the_admin_preview_shows_the_launch_price_from_the_unsaved_values(): void
    {
        $admin = $this->superAdmin();
        $field = fn (string $k) => 'inputs.'.Manage::field($k);

        Livewire::actingAs($admin)->test(Manage::class)
            ->set($field('cancellation.visit_fee_value'), '149')
            ->set($field('cancellation.visit_fee_regular'), '199')
            ->assertSee('Visit charge ₹149 (launch price, regular ₹199). It applies only if the professional arrives and no work is done');
    }

    public function test_only_a_super_admin_can_change_these_settings(): void
    {
        Livewire::actingAs($this->makeCustomer())->test(Manage::class)->assertForbidden();
    }
}
