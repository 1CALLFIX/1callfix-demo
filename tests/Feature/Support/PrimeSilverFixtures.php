<?php

namespace Tests\Feature\Support;

use App\Actions\CreateBookingAction;
use App\Models\Address;
use App\Models\Booking;
use App\Models\Plan;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Plans\PlanService;
use App\Services\Plans\SubscriptionService;
use Database\Seeders\PrimeSilverPlanSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Builds the real Prime Silver plan (through the real seeder), a realistic
 * catalog, the catalog mapping an admin would make in /admin/plans, and an
 * activated member — so every membership test drives the SAME production code
 * (SubscriptionService, CreateBookingAction, MembershipBenefitService) with
 * nothing mocked.
 *
 * The Razorpay gateway is always faked here, so no test can reach the network.
 */
trait PrimeSilverFixtures
{
    use BookingFixtureHelpers;

    protected function fakeRazorpay(): void
    {
        config([
            'services.razorpay.key_id' => 'rzp_test_fakekeyid123',
            'services.razorpay.key_secret' => 'fake-test-key-secret-never-real',
            'services.razorpay.webhook_secret' => 'fake-test-webhook-secret-never-real',
        ]);

        Http::fake([
            'api.razorpay.com/v1/orders' => fn () => Http::response(
                ['id' => 'order_'.Str::random(12), 'amount' => 199900, 'currency' => 'INR'],
                200
            ),
        ]);
    }

    protected function postRazorpayWebhook(array $payload)
    {
        return $this->postJson(
            '/api/webhooks/razorpay',
            $payload,
            ['X-Razorpay-Signature' => hash_hmac('sha256', json_encode($payload), config('services.razorpay.webhook_secret'))]
        );
    }

    protected function capturedWebhook(string $orderId, string $paymentId = 'pay_test_1'): array
    {
        return ['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['order_id' => $orderId, 'id' => $paymentId]]]];
    }

    protected function failedWebhook(string $orderId): array
    {
        return ['event' => 'payment.failed', 'payload' => ['payment' => ['entity' => ['order_id' => $orderId]]]];
    }

    protected function seedPrimeSilver(): Plan
    {
        $this->seed(PrimeSilverPlanSeeder::class);

        return Plan::where('slug', PrimeSilverPlanSeeder::SLUG)->with('entitlements.targets')->firstOrFail();
    }

    /**
     * A catalog shaped like the real one: categories, and services inside them,
     * including services that are deliberately OUT of scope.
     *
     * @return array<string, mixed>
     */
    protected function makePrimeCatalog(): array
    {
        $cats = [];
        foreach (['ac' => 'AC', 'appliance' => 'Appliance', 'electrical' => 'Electrical', 'plumbing' => 'Plumbing', 'carpenter' => 'Carpenter', 'painting' => 'Painting'] as $key => $name) {
            $cats[$key] = ServiceCategory::create([
                'module' => 'service', 'name' => $name, 'slug' => Str::slug($name).'-'.Str::random(5),
                'image' => 'categories/x.png', 'sort_order' => 1, 'is_active' => true,
            ]);
        }

        $make = fn (ServiceCategory $cat, string $name, float $price, ?float $visit) => Service::create([
            'category_id' => $cat->id, 'name' => $name, 'slug' => Str::slug($name).'-'.Str::random(5),
            'base_price' => $price, 'visiting_charge' => $visit, 'price_type' => 'fixed', 'duration_estimate_mins' => 60,
            'is_active' => true, 'location_required' => true, 'age_restriction' => false, 'sort_order' => 1,
        ]);

        return [
            'cats' => $cats,
            'ac_jet' => $make($cats['ac'], 'AC Jet Pump Service', 1800, 250),     // covered by the AC benefit; 1,800 > the 1,500 cap; its own visit charge (250) is ABOVE the flat 199
            'ac_gas' => $make($cats['ac'], 'AC Gas Charging', 2500, 250),         // OUT of scope
            'appliance' => $make($cats['appliance'], 'Appliance General Service', 600, 150),
            'electrical' => $make($cats['electrical'], 'Electrical General Service', 500, 100),
            'rewiring' => $make($cats['electrical'], 'Electrical Rewiring', 800, null),  // out of the credit's scope; no visiting charge of its own -> the flat 199 applies
            'plumbing' => $make($cats['plumbing'], 'Plumbing General Service', 500, 100),
            'carpenter' => $make($cats['carpenter'], 'Carpenter General Service', 500, 100),
            'painting' => $make($cats['painting'], 'Wall Painting', 3000, 300),   // not a covered category at all
        ];
    }

    /** Exactly what an admin does in /admin/plans → Entitlements → Eligible catalog targets. */
    protected function mapPrimeTargets(Plan $plan, array $catalog): void
    {
        $svc = app(PlanService::class);
        $by = fn (string $label) => $plan->entitlements->firstWhere('label', $label);

        $ac = $by('Premium AC Jet Pump Service');
        $svc->addTarget($ac, 'category', $catalog['cats']['ac']->id);
        $svc->addTarget($ac, 'service', $catalog['ac_gas']->id, null, true);

        $svc->addTarget($by('Appliance General Service'), 'category', $catalog['cats']['appliance']->id);

        $credit = $by('Home Service Credit');
        $svc->addTarget($credit, 'category', $catalog['cats']['electrical']->id, 'electrical');
        $svc->addTarget($credit, 'service', $catalog['rewiring']->id, null, true);
        $svc->addTarget($credit, 'category', $catalog['cats']['plumbing']->id, 'plumbing');
        $svc->addTarget($credit, 'category', $catalog['cats']['carpenter']->id, 'carpenter');

        $visits = $by('Free Service Visit (waives visit/inspection fee only)');
        foreach (['ac', 'appliance', 'electrical', 'plumbing', 'carpenter'] as $cat) {
            $svc->addTarget($visits, 'category', $catalog['cats'][$cat]->id);
        }
        $svc->addTarget($visits, 'service', $catalog['ac_gas']->id, null, true);

        $plan->load('entitlements.targets');
    }

    /** Buys and activates Prime Silver the way production does (pending_payment → activate on captured payment). */
    protected function activatePrime(User $customer, Plan $plan, Address $address): Subscription
    {
        $this->fakeRazorpay();

        $result = app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan, $address->id);
        $subscription = Subscription::findOrFail($result['subscription_id']);

        return app(SubscriptionService::class)->activate($subscription);
    }

    /**
     * A complete, active Prime Silver member with the catalog mapped.
     *
     * @return array{customer: User, address: Address, franchise: mixed, zone: mixed, plan: Plan, subscription: Subscription, catalog: array}
     */
    protected function primeMember(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $catalog = $this->makePrimeCatalog();
        $plan = $this->seedPrimeSilver();
        $this->mapPrimeTargets($plan, $catalog);

        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $subscription = $this->activatePrime($customer, $plan, $address);

        return compact('customer', 'address', 'franchise', 'zone', 'plan', 'subscription', 'catalog');
    }

    /** Places a cash booking through the real CreateBookingAction. */
    protected function bookService(User $customer, Address $address, Service $service): Booking
    {
        return app(CreateBookingAction::class)->execute([
            'franchise_id' => $address->franchise_id,
            'zone_id' => $address->zone_id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'address_id' => $address->id,
            'payment_method' => 'cash',
        ]);
    }

    protected function balanceOf(Subscription $subscription, string $label): \App\Models\EntitlementBalance
    {
        $entitlement = $subscription->plan->entitlements()->where('label', $label)->firstOrFail();

        return \App\Models\EntitlementBalance::where('subscription_id', $subscription->id)
            ->where('plan_entitlement_id', $entitlement->id)->where('status', 'current')->firstOrFail();
    }
}
