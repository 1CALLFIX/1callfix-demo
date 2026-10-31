<?php

namespace Tests\Feature\Parcel;

use App\Actions\CreateParcelOrderAction;
use App\Exceptions\ParcelQuoteException;
use App\Livewire\Customer\Parcel\Create;
use App\Livewire\Customer\Parcel\Show;
use App\Livewire\ParcelClaims\Index as ClaimsQueue;
use App\Models\Commission;
use App\Models\FieldWorkerCashDue;
use App\Models\ParcelOrder;
use App\Models\ParcelProtectionClaim;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\Parcel\ParcelProtectionClaimService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Parcel Stage 2 — protection (owner decision 2026-10-31): optional add-on, fee = % of the declared value (min/max,
 * max cover; unset = off), never shared with the rider; claims with photos; the payout ONLY through the manual money
 * approval model (permission, scope, limits, maker-checker, escalation, reason, audit, idempotent).
 */
class ParcelProtectionTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
    }

    private function world(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);
        $rider = $this->makeParcelRiderIn($franchise, $zone);

        return compact('franchise', 'zone', 'customer', 'pickup', 'dropoff', 'rider');
    }

    private function enableProtection(): void
    {
        Setting::set('parcel.protection_fee_percent', '2');
        Setting::set('parcel.protection_max_cover', '10000');
        Setting::set('parcel.protection_min_fee', '10');
        Setting::set('parcel.protection_max_fee', '150');
    }

    private function create(array $w, array $extra = []): ParcelOrder
    {
        return app(CreateParcelOrderAction::class)->execute(array_merge([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
            'recipient_phone' => '9876543210', 'payment_method' => 'cash', 'restricted_items_confirmed' => true,
        ], $extra));
    }

    /** A protected parcel, delivered by the rider. */
    private function delivered(array $w, string $method = 'cash', float $value = 3000): ParcelOrder
    {
        $order = $this->create($w, ['declared_value' => $value, 'payment_method' => $method]);
        $order->update(['status' => 'delivered', 'assigned_worker_id' => $w['rider']->id, 'picked_up_at' => now(), 'delivered_at' => now(),
            'payment_status' => 'paid', 'price_final' => $order->price_quoted]);

        return $order->fresh();
    }

    private function claim(array $w, ParcelOrder $order, float $amount = 2000): ParcelProtectionClaim
    {
        return app(ParcelProtectionClaimService::class)->file($order, $w['customer'], 'damaged', 'The screen is cracked.', $amount, [UploadedFile::fake()->image('crack.jpg')]);
    }

    private function franchisePayer(array $w, string $permission = ParcelProtectionClaimService::PAYOUT): User
    {
        $user = $this->makeCustomer();
        $user->forceFill(['role' => 'franchise_owner'])->save();
        $role = Role::create(['name' => 'Claims '.Str::random(4), 'slug' => 'claims-'.Str::lower(Str::random(6))]);
        $role->permissions()->attach(Permission::where('slug', $permission)->value('id'));
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => 'franchise', 'scope_id' => $w['franchise']->id]);

        return $user->fresh();
    }

    // ═══════════════════════ booking ═══════════════════════

    public function test_protection_is_refused_while_its_settings_are_unset(): void
    {
        $w = $this->world();

        $this->expectException(ParcelQuoteException::class);
        $this->expectExceptionMessage('Parcel protection is not available here.');
        $this->create($w, ['declared_value' => 1000]);
    }

    public function test_the_fee_is_a_percentage_of_the_value_between_the_min_and_max(): void
    {
        $w = $this->world();
        $this->enableProtection();

        $this->assertEquals(60, $this->create($w, ['declared_value' => 3000])->protection_fee, '2% of 3000');
        $this->assertEquals(10, $this->create($w, ['declared_value' => 100])->protection_fee, 'min fee');
        $this->assertEquals(150, $this->create($w, ['declared_value' => 10000])->protection_fee, 'max fee');

        $plain = $this->create($w);
        $order = $this->create($w, ['declared_value' => 3000]);
        $this->assertEquals((float) $plain->price_quoted + 60, (float) $order->price_quoted);
        $this->assertSame('Parcel protection (value 3000)', collect($order->price_breakdown)->last()['label']);
        $this->assertNull($plain->protection_fee);
    }

    public function test_the_value_cannot_exceed_the_highest_cover(): void
    {
        $w = $this->world();
        $this->enableProtection();

        $this->expectExceptionMessage('Declare a value between 1 and 10000.');
        $this->create($w, ['declared_value' => 20000]);
    }

    public function test_the_protection_fee_is_never_shared_with_the_rider_and_a_cash_rider_owes_it(): void
    {
        $w = $this->world();
        $this->enableProtection();
        $plain = $this->create($w);
        $plain->update(['status' => 'delivered', 'assigned_worker_id' => $w['rider']->id, 'picked_up_at' => now(), 'delivered_at' => now(), 'payment_status' => 'paid', 'price_final' => $plain->price_quoted]);
        $protected = $this->delivered($w, 'cash');

        $plainCommission = app(CommissionService::class)->applyForParcelOrder($plain->fresh());
        $commission = app(CommissionService::class)->applyForParcelOrder($protected);

        $this->assertEquals((float) $plainCommission->provider_commission, (float) $commission->provider_commission, 'rider share ignores the protection fee');
        $this->assertEquals((float) $plainCommission->franchise_commission, (float) $commission->franchise_commission, 'franchise share ignores it too');
        $this->assertEqualsWithDelta((float) $plainCommission->platform_commission + 60, (float) $commission->platform_commission, 0.01, 'HQ keeps the fee');
        $due = FieldWorkerCashDue::where('parcel_order_id', $protected->id)->sole();
        $this->assertEqualsWithDelta((float) $commission->platform_commission + (float) $commission->franchise_commission, (float) $due->amount_owed, 0.01, 'the rider holds the fee in cash and owes it back');

        app(CommissionService::class)->applyForParcelOrder($protected->fresh());
        $this->assertEquals($commission->platform_commission, Commission::where('parcel_order_id', $protected->id)->sole()->platform_commission, 'idempotent');
    }

    public function test_the_customer_page_adds_protection(): void
    {
        $w = $this->world();
        $this->enableProtection();

        Livewire::actingAs($w['customer'])->test(Create::class)
            ->set('pickupMode', 'saved')->set('pickupAddressId', $w['pickup']->id)
            ->set('dropMode', 'saved')->set('dropAddressId', $w['dropoff']->id)
            ->set('recipientPhone', '9876543210')
            ->assertSee('Protect this parcel')
            ->set('protect', true)->set('declaredValue', '3000')
            ->assertSee('Parcel protection (value 3000)')
            ->set('paymentMethod', 'cash')->set('restrictedConfirmed', true)
            ->call('submit')->assertHasNoErrors();

        $this->assertEquals(3000, ParcelOrder::sole()->declared_value);
    }

    // ═══════════════════════ claims ═══════════════════════

    public function test_only_a_protected_delivered_parcel_can_be_claimed_once_within_the_window(): void
    {
        $w = $this->world();
        $this->enableProtection();
        $service = app(ParcelProtectionClaimService::class);

        $unprotected = $this->create($w);
        $this->assertSame('This parcel was not protected.', $service->cannotClaim($unprotected, $w['customer']->id));

        $open = $this->create($w, ['declared_value' => 3000]);
        $this->assertStringContainsString('once the parcel has been delivered', $service->cannotClaim($open, $w['customer']->id));

        $order = $this->delivered($w);
        $this->assertNull($service->cannotClaim($order, $w['customer']->id));
        $this->assertSame('This is not your parcel.', $service->cannotClaim($order, $this->makeCustomer()->id));

        $this->claim($w, $order);
        $this->assertSame('A claim is already open for this parcel.', $service->cannotClaim($order, $w['customer']->id));

        $late = $this->delivered($w);
        $this->travel(4)->days();
        $this->assertSame('Claims must be made within 3 days.', $service->cannotClaim($late->fresh(), $w['customer']->id));
    }

    public function test_a_claim_needs_a_photo_for_damage_and_at_most_the_declared_value(): void
    {
        $w = $this->world();
        $this->enableProtection();
        $order = $this->delivered($w, value: 3000);
        $service = app(ParcelProtectionClaimService::class);

        try {
            $service->file($order, $w['customer'], 'damaged', 'Broken', 100, []);
            $this->fail('Damage needs a photo.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Add at least one photo of the damage.', $e->getMessage());
        }

        try {
            $service->file($order, $w['customer'], 'lost', 'Never arrived', 5000, []);
            $this->fail('A claim cannot exceed the declared value.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('at most the declared value', $e->getMessage());
        }

        $claim = $service->file($order, $w['customer'], 'lost', 'Never arrived', 3000, []);
        $this->assertSame('requested', $claim->status);
    }

    public function test_the_customer_files_a_claim_from_the_tracking_page(): void
    {
        $w = $this->world();
        $this->enableProtection();
        $order = $this->delivered($w);

        Livewire::actingAs($w['customer'])->test(Show::class, ['parcelOrder' => $order])
            ->assertSee('Report it lost or damaged')
            ->set('claiming', true)->set('claimReason', 'damaged')->set('claimDescription', 'Cracked screen')
            ->set('claimAmount', '1500')->set('claimPhotos', [UploadedFile::fake()->image('a.jpg')])
            ->call('fileClaim')->assertHasNoErrors()
            ->assertSee('being checked');

        $claim = ParcelProtectionClaim::sole();
        Storage::disk('local')->assertExists($claim->photos[0]);
    }

    public function test_a_payout_is_never_automatic_and_lands_in_the_wallet_once(): void
    {
        $w = $this->world();
        $this->enableProtection();
        $claim = $this->claim($w, $this->delivered($w));
        $service = app(ParcelProtectionClaimService::class);
        $admin = $this->makeSuperAdmin();
        $before = app(WalletService::class)->balance($w['customer']);

        $this->assertTrue($service->decide($claim, $admin, true, 'Photo shows the crack.', 1800)['ok']);
        $this->assertEquals($before, app(WalletService::class)->balance($w['customer']), 'accepting moves no money');
        $this->assertSame('awaiting_request', $claim->fresh()->payout_status);

        $this->assertFalse($service->requestPayout($claim->fresh(), $admin, '')['ok'], 'reason required');
        $this->assertTrue($service->requestPayout($claim->fresh(), $admin, 'Damaged in transit')['ok']);
        $this->assertFalse($service->requestPayout($claim->fresh(), $admin, 'Again')['ok'], 'idempotent');

        $this->assertEquals($before + 1800, app(WalletService::class)->balance($w['customer']));
        $this->assertSame('paid', $claim->fresh()->status);
    }

    public function test_the_payout_cannot_exceed_the_declared_value(): void
    {
        $w = $this->world();
        $this->enableProtection();
        $claim = $this->claim($w, $this->delivered($w, value: 3000));

        $result = app(ParcelProtectionClaimService::class)->decide($claim, $this->makeSuperAdmin(), true, 'ok', 4000);
        $this->assertFalse($result['ok']);
    }

    public function test_limits_scope_and_maker_checker(): void
    {
        $w = $this->world();
        $this->enableProtection();
        $claim = $this->claim($w, $this->delivered($w));
        $service = app(ParcelProtectionClaimService::class);
        $service->decide($claim, $this->makeSuperAdmin(), true, 'ok');

        $maker = $this->franchisePayer($w);
        $checker = $this->franchisePayer($w);
        $outsider = $this->franchisePayer($this->world());

        $this->assertTrue($service->requestPayout($claim->fresh(), $maker, 'Cracked')['ok']);
        $this->assertSame('awaiting_approval', $claim->fresh()->payout_status, 'no franchise limit set: the franchise level cannot approve');
        $this->assertFalse($service->approvePayout($claim->fresh(), $checker, 'ok')['ok']);

        Setting::set(ParcelProtectionClaimService::FRANCHISE_LIMIT_KEY, '5000');
        $this->assertFalse($service->approvePayout($claim->fresh(), $maker, 'self')['ok'], 'never your own request');
        $this->assertFalse($service->approvePayout($claim->fresh(), $outsider, 'ok')['ok'], 'another franchise');
        $this->assertTrue($service->approvePayout($claim->fresh(), $checker, 'Checked the photo')['ok']);
        $this->assertSame('paid', $claim->fresh()->status);
    }

    public function test_dual_approval_above_the_threshold(): void
    {
        $w = $this->world();
        $this->enableProtection();
        $claim = $this->claim($w, $this->delivered($w));
        $service = app(ParcelProtectionClaimService::class);
        $admin = $this->makeSuperAdmin();
        $service->decide($claim, $admin, true, 'ok');
        Setting::set(ParcelProtectionClaimService::DUAL_APPROVAL_KEY, '1000');

        $this->assertTrue($service->requestPayout($claim->fresh(), $admin, 'Cracked')['ok']);
        $this->assertSame('awaiting_approval', $claim->fresh()->payout_status, 'above the threshold: a second user must approve, even a Super Admin');
        $this->assertTrue($service->approvePayout($claim->fresh(), $this->makeSuperAdmin(), 'Second check')['ok']);
    }

    public function test_the_queue_needs_the_permission_and_escalation_runs_only_when_configured(): void
    {
        $w = $this->world();
        $this->enableProtection();
        $claim = $this->claim($w, $this->delivered($w));
        $this->actingAs($this->makeCustomer())->get(route('admin.parcel-claims.index'))->assertForbidden();
        Livewire::actingAs($this->makeSuperAdmin())->test(ClaimsQueue::class)->assertSee('#'.$claim->id);
        $this->actingAs($this->makeCustomer())->get(route('admin.parcel-claims.photo', [$claim->id, 0]))->assertForbidden();
        $this->actingAs($this->makeSuperAdmin())->get(route('admin.parcel-claims.photo', [$claim->id, 0]))->assertOk();

        app(ParcelProtectionClaimService::class)->decide($claim, $this->makeSuperAdmin(), true, 'ok');
        $this->travel(10)->hours();
        $this->artisan('refunds:escalate-parcel-claims')->assertSuccessful();
        $this->assertSame(0, $claim->fresh()->escalation_level, 'off while unset');

        Setting::set(ParcelProtectionClaimService::FRANCHISE_LIMIT_KEY, '5000');
        Setting::set(ParcelProtectionClaimService::ESCALATE_HOURS_KEY, '4');
        $this->artisan('refunds:escalate-parcel-claims')->assertSuccessful();
        $this->assertGreaterThan(0, $claim->fresh()->escalation_level);
    }

    public function test_the_api_files_a_claim(): void
    {
        $w = $this->world();
        $this->enableProtection();
        $order = $this->delivered($w);

        $this->actingAs($w['customer'], 'sanctum')->postJson("/api/parcel-orders/{$order->id}/protection-claims", [
            'reason' => 'lost', 'description' => 'It never arrived', 'amount' => 2500,
        ])->assertCreated()->assertJsonPath('data.status', 'requested');

        $this->actingAs($this->makeCustomer(), 'sanctum')->postJson("/api/parcel-orders/{$order->id}/protection-claims", [
            'reason' => 'lost', 'description' => 'Not mine', 'amount' => 10,
        ])->assertNotFound();
    }
}
