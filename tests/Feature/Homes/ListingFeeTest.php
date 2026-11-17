<?php

namespace Tests\Feature\Homes;

use App\Actions\RegisterHomeOwnerAction;
use App\Livewire\Homes\Manage as HomesManage;
use App\Livewire\Venue\HomeEditor;
use App\Models\Franchise;
use App\Models\HouseListing;
use App\Models\ListingFee;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Services\Homes\ListingFeeService;
use App\Services\ModuleActivationService;
use App\Services\WalletService;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/** Owner 2026-11-17: a property owner pays a listing fee (online or wallet) to go live, and may pay to be shown first. */
class ListingFeeTest extends TestCase
{
    use RefreshDatabase;
    use LiveCity;
    use RbacTestHelpers;

    private Franchise $franchise;
    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $city = $this->liveCity('Nellore');
        $this->franchise = Franchise::where('city_id', $city->id)->firstOrFail();
        $this->zone = Zone::where('franchise_id', $this->franchise->id)->firstOrFail();
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $this->franchise->id, true);
    }

    /** Submit a full home for review and have the team approve it. */
    private function approvedHome(): HouseListing
    {
        Storage::fake('public');
        $home = app(RegisterHomeOwnerAction::class)->execute(
            zone: $this->zone, existing: null, registration: ['address' => '12-3 Gandhi Road, Nellore', 'lat' => '14.44', 'lng' => '79.98'],
            name: 'Ravi', nationalPhone: '9'.fake()->numerify('#########'), plainPassword: 'secret-pass-1',
        );
        Livewire::actingAs($home->provider->user)->test(HomeEditor::class, ['listingId' => $home->id])
            ->set('homeType', 'Flat / apartment')->set('size', '2 BHK')->set('furnishing', 'Semi-furnished')
            ->set('tenants', ['Family'])->set('rent', '12000')->set('deposit', '24000')->set('zoneId', (string) $this->zone->id)
            ->set('addressLine', '12-3 Gandhi Road, Nellore')->set('landmark', 'Near RTC bus stand')
            ->set('amenities', ['24-hour water', 'Ceiling fans', 'Lift'])->set('ruleList', ['No smoking'])
            ->set('cover', UploadedFile::fake()->image('cover.jpg', 1600, 1067))
            ->set('newPhotos', [UploadedFile::fake()->image('a.jpg', 1600, 1067), UploadedFile::fake()->image('b.jpg', 1600, 1067), UploadedFile::fake()->image('c.jpg', 1600, 1067)])
            ->call('submit')->assertHasNoErrors();
        Livewire::actingAs($this->makeSuperAdmin())->test(HomesManage::class)->call('approve', $home->id);

        return $home->refresh();
    }

    private function fund(User $owner, int $rupees): void
    {
        app(WalletService::class)->credit($owner, (float) $rupees, 'test top-up', 'test:lf:'.Str::random(6));
    }

    public function test_free_by_default_approval_puts_the_property_live(): void
    {
        $home = $this->approvedHome();

        $this->assertTrue($home->isLive());
        $this->assertFalse(ListingFeeService::required($home));
    }

    public function test_with_a_fee_approval_alone_does_not_put_it_live_and_paying_from_the_wallet_does(): void
    {
        Setting::set('homes.listing_fee', '199');
        $home = $this->approvedHome();
        $owner = $home->provider->user;
        $this->assertFalse($home->isLive(), 'approved but waiting for the fee');

        $this->fund($owner, 500);
        Livewire::actingAs($owner)->test(HomeEditor::class, ['listingId' => $home->id])
            ->assertSee('Pay from wallet')->call('payFee', 'listing', 'wallet')->assertHasNoErrors();

        $home->refresh();
        $this->assertTrue($home->isLive());
        $this->assertEqualsWithDelta(60, now()->diffInDays($home->expires_at), 1);
        $this->assertSame(301.0, (float) app(WalletService::class)->balance($owner));
        $fee = ListingFee::firstOrFail();
        $this->assertSame(['paid', 'wallet', 'listing'], [$fee->status, $fee->method, $fee->kind]);
    }

    public function test_a_wallet_with_too_little_pays_nothing_and_the_property_stays_hidden(): void
    {
        Setting::set('homes.listing_fee', '199');
        $home = $this->approvedHome();
        $owner = $home->provider->user;
        $this->fund($owner, 100);

        Livewire::actingAs($owner)->test(HomeEditor::class, ['listingId' => $home->id])
            ->call('payFee', 'listing', 'wallet')->assertHasErrors('home');

        $this->assertFalse($home->refresh()->isLive());
        $this->assertSame(100.0, (float) app(WalletService::class)->balance($owner));
        $this->assertSame(0, ListingFee::count());
    }

    public function test_tapping_still_available_does_not_skip_the_fee(): void
    {
        Setting::set('homes.listing_fee', '199');
        $home = $this->approvedHome();

        Livewire::actingAs($home->provider->user)->test(HomeEditor::class, ['listingId' => $home->id])
            ->call('stillAvailable')->assertHasErrors('home');

        $this->assertFalse($home->refresh()->isLive());
    }

    public function test_an_online_payment_goes_live_only_for_the_exact_amount_and_only_once(): void
    {
        Setting::set('homes.listing_fee', '199');
        $home = $this->approvedHome();
        $fee = ListingFee::create([
            'listing_type' => 'house', 'listing_id' => $home->id, 'user_id' => $home->provider->user_id, 'franchise_id' => $home->franchise_id,
            'kind' => 'listing', 'amount' => 199, 'days' => 60, 'method' => 'online', 'status' => 'pending',
            'gateway' => 'razorpay', 'gateway_order_id' => 'order_LF1',
        ]);
        $service = app(ListingFeeService::class);

        $this->assertNull($service->handleCaptured('order_unknown', 'pay_x', 19900), 'not a listing fee order: the caller carries on');

        $this->assertSame('amount_mismatch', $service->handleCaptured('order_LF1', 'pay_1', 100)['outcome']);
        $this->assertFalse($home->refresh()->isLive());
        $this->assertSame('pending', $fee->refresh()->status);

        $this->assertSame('captured', $service->handleCaptured('order_LF1', 'pay_1', 19900)['outcome']);
        $until = $home->refresh()->expires_at;
        $this->assertTrue($home->isLive());

        $this->assertSame('already_processed', $service->handleCaptured('order_LF1', 'pay_1', 19900)['outcome']);
        $this->assertTrue($home->refresh()->expires_at->equalTo($until), 'a repeated webhook adds no days');
    }

    public function test_featuring_needs_a_live_property_and_ends_when_it_runs_out(): void
    {
        Setting::set('homes.featured_fee', '99');
        $home = $this->approvedHome();
        $owner = $home->provider->user;
        $this->fund($owner, 300);
        $service = app(ListingFeeService::class);

        $home->update(['rented_at' => now()]);
        try {
            $service->payFromWallet($owner, $home->refresh(), 'featured');
            $this->fail('A hidden property cannot be featured.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('live', $e->getMessage());
        }

        $home->update(['rented_at' => null]);
        $service->payFromWallet($owner, $home->refresh(), 'featured');
        $this->assertTrue($home->refresh()->is_featured);
        $this->assertEqualsWithDelta(15, now()->diffInDays($home->featured_until), 1);

        $home->update(['featured_until' => now()->subMinute()]);
        $this->assertSame(1, ListingFeeService::endLapsedFeatures());
        $this->assertFalse($home->refresh()->is_featured);
    }

    public function test_only_the_owner_can_pay_for_a_property(): void
    {
        Setting::set('homes.listing_fee', '199');
        $home = $this->approvedHome();
        $stranger = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Other', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);
        $this->fund($stranger, 500);

        $this->expectException(\RuntimeException::class);
        app(ListingFeeService::class)->payFromWallet($stranger, $home, 'listing');
    }
}
