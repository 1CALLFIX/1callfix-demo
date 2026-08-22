<?php

namespace Tests\Feature\Export;

use App\Livewire\Bookings\Index as BookingsIndex;
use App\Livewire\Categories\Manage as CategoriesManage;
use App\Livewire\Customers\Index as CustomersIndex;
use App\Livewire\Payments\Index as PaymentsIndex;
use App\Livewire\Products\Manage as ProductsManage;
use App\Livewire\Providers\Index as ProvidersIndex;
use App\Livewire\Services\Manage as ServicesManage;
use App\Livewire\Subcategories\Manage as SubcategoriesManage;
use App\Livewire\WalletLedger\Index as WalletLedgerIndex;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Models\Store;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Export Everywhere session, Part 1 — "this is the most important
 * requirement in this entire prompt." One test per entity, the same way
 * items 47-50 from the earlier RBAC audit were caught and fixed by
 * checking EVERY screen individually rather than trusting one
 * representative case to generalize.
 *
 * Bookings/Customers/Providers/Payments/Products/WalletLedger are real
 * franchise-scoped data — each test proves a franchise-scoped export never
 * contains another franchise's row. Categories/Subcategories/Services are
 * genuinely GLOBAL catalog (no franchise/zone column exists on those
 * tables at all — confirmed in each screen's own save()/baseQuery()
 * docblock) — their tests instead confirm the CSV export doesn't
 * accidentally start hiding rows a franchise-scoped catalog admin should
 * still see (there is nothing to leak, but also nothing to wrongly hide).
 */
class CsvExportScopeTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function csvContent($testable): string
    {
        return base64_decode(data_get($testable->effects, 'download.content'));
    }

    // ---------------------------------------------------------------- Bookings

    public function test_bookings_export_never_includes_another_franchises_row(): void
    {
        $own = $this->makeBookingScenario('completed');
        $other = $this->makeBookingScenario('completed');
        $viewer = $this->makeUserWithPermission('bookings.view', 'franchise', $own['franchise']->id);

        $csv = $this->csvContent(
            Livewire::actingAs($viewer)->test(BookingsIndex::class)->call('exportBookingsCsv')
        );

        $this->assertStringContainsString($own['booking']->code, $csv);
        $this->assertStringNotContainsString($other['booking']->code, $csv);
    }

    public function test_bookings_export_shows_everything_for_a_global_grant(): void
    {
        $a = $this->makeBookingScenario('completed');
        $b = $this->makeBookingScenario('completed');
        $viewer = $this->makeUserWithPermission('bookings.view', 'global');

        $csv = $this->csvContent(
            Livewire::actingAs($viewer)->test(BookingsIndex::class)->call('exportBookingsCsv')
        );

        $this->assertStringContainsString($a['booking']->code, $csv);
        $this->assertStringContainsString($b['booking']->code, $csv);
    }

    // ---------------------------------------------------------------- Customers

    public function test_customers_export_never_includes_another_franchises_row(): void
    {
        [, , $ownFranchise] = $this->makeFranchiseTree();
        [, , $otherFranchise] = $this->makeFranchiseTree();
        $ownCustomer = $this->makeCustomer();
        $ownCustomer->forceFill(['franchise_id' => $ownFranchise->id])->save();
        $otherCustomer = $this->makeCustomer();
        $otherCustomer->forceFill(['franchise_id' => $otherFranchise->id])->save();

        $viewer = $this->makeUserWithPermission('customers.view', 'franchise', $ownFranchise->id);

        $csv = $this->csvContent(
            Livewire::actingAs($viewer)->test(CustomersIndex::class)->call('exportCustomersCsv')
        );

        $this->assertStringContainsString($ownCustomer->phone, $csv);
        $this->assertStringNotContainsString($otherCustomer->phone, $csv);
    }

    // ---------------------------------------------------------------- Providers

    public function test_providers_export_never_includes_another_franchises_row(): void
    {
        [, , $ownFranchise, $ownZone] = $this->makeFranchiseTree();
        [, , $otherFranchise, $otherZone] = $this->makeFranchiseTree();
        $ownProvider = $this->makeProviderIn($ownFranchise, $ownZone);
        $otherProvider = $this->makeProviderIn($otherFranchise, $otherZone);

        $viewer = $this->makeUserWithPermission('providers.view', 'franchise', $ownFranchise->id);

        // Providers\Index defaults statusFilter to 'pending'; the fixture
        // providers are kyc_status='approved' — clear the filter so this
        // test exercises SCOPE, not the (separately correct) status filter.
        $csv = $this->csvContent(
            Livewire::actingAs($viewer)->test(ProvidersIndex::class)
                ->set('statusFilter', '')
                ->call('exportProvidersCsv')
        );

        $this->assertStringContainsString($ownProvider->user->phone, $csv);
        $this->assertStringNotContainsString($otherProvider->user->phone, $csv);
    }

    // ---------------------------------------------------------------- Payments

    public function test_payments_export_never_includes_another_franchises_row(): void
    {
        $own = $this->makeBookingScenario('completed');
        $other = $this->makeBookingScenario('completed');
        $ownPayment = Payment::create(['booking_id' => $own['booking']->id, 'amount' => 500, 'gateway' => 'razorpay', 'gateway_order_id' => 'order_own_123', 'status' => 'captured']);
        $otherPayment = Payment::create(['booking_id' => $other['booking']->id, 'amount' => 700, 'gateway' => 'razorpay', 'gateway_order_id' => 'order_other_456', 'status' => 'captured']);

        $viewer = $this->makeUserWithPermission('payments.view', 'franchise', $own['franchise']->id);

        $csv = $this->csvContent(
            Livewire::actingAs($viewer)->test(PaymentsIndex::class)->call('exportPaymentsCsv')
        );

        $this->assertStringContainsString('order_own_123', $csv);
        $this->assertStringNotContainsString('order_other_456', $csv);
    }

    // ---------------------------------------------------------------- Wallet Ledger

    public function test_wallet_ledger_export_never_includes_another_franchises_row(): void
    {
        [, , $ownFranchise] = $this->makeFranchiseTree();
        [, , $otherFranchise] = $this->makeFranchiseTree();
        $ownCustomer = $this->makeCustomer();
        $ownCustomer->forceFill(['franchise_id' => $ownFranchise->id])->save();
        $otherCustomer = $this->makeCustomer();
        $otherCustomer->forceFill(['franchise_id' => $otherFranchise->id])->save();

        app(WalletService::class)->credit($ownCustomer, 100, 'own franchise credit', 'REF-OWN');
        app(WalletService::class)->credit($otherCustomer, 200, 'other franchise credit', 'REF-OTHER');

        $viewer = $this->makeUserWithPermission('wallets.view', 'franchise', $ownFranchise->id);

        $csv = $this->csvContent(
            Livewire::actingAs($viewer)->test(WalletLedgerIndex::class)->call('exportWalletLedgerCsv')
        );

        $this->assertStringContainsString('REF-OWN', $csv);
        $this->assertStringNotContainsString('REF-OTHER', $csv);
    }

    // ---------------------------------------------------------------- Products

    private function store($franchise, $zone): Store
    {
        $provider = $this->makeProviderIn($franchise, $zone);

        return Store::create([
            'provider_id' => $provider->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'module' => 'commerce', 'name' => 'Store', 'slug' => 'store-'.uniqid(),
            'address_line' => 'Addr', 'lat' => 1.0, 'lng' => 1.0,
        ]);
    }

    public function test_products_export_never_includes_another_franchises_row(): void
    {
        [, , $ownFranchise, $ownZone] = $this->makeFranchiseTree();
        [, , $otherFranchise, $otherZone] = $this->makeFranchiseTree();
        $ownStore = $this->store($ownFranchise, $ownZone);
        $otherStore = $this->store($otherFranchise, $otherZone);
        $ownProduct = Product::create(['store_id' => $ownStore->id, 'name' => 'Own Product', 'price' => 50, 'is_active' => true, 'is_approved' => true]);
        $otherProduct = Product::create(['store_id' => $otherStore->id, 'name' => 'Other Product', 'price' => 60, 'is_active' => true, 'is_approved' => true]);

        $viewer = $this->makeUserWithPermission('products.manage', 'franchise', $ownFranchise->id);

        $csv = $this->csvContent(
            Livewire::actingAs($viewer)->test(ProductsManage::class)->call('exportProductsCsv')
        );

        $this->assertStringContainsString($ownProduct->name, $csv);
        $this->assertStringNotContainsString($otherProduct->name, $csv);
    }

    // ---------------------------------------------------------------- Global catalog (Categories/Subcategories/Services)
    // No franchise/zone column exists on these tables — a franchise-scoped
    // grant still sees the WHOLE global catalog, same as the on-screen
    // list already does. Confirms the CSV export doesn't invent scoping
    // that isn't there (which would silently hide real catalog rows from
    // a franchise-scoped catalog admin).

    public function test_categories_export_is_global_not_franchise_filtered(): void
    {
        ServiceCategory::create(['name' => 'Cat A', 'slug' => 'cat-a', 'module' => 'service', 'is_active' => true]);
        [, , $franchise] = $this->makeFranchiseTree();
        $viewer = $this->makeUserWithPermission('categories.manage', 'franchise', $franchise->id);

        $csv = $this->csvContent(
            Livewire::actingAs($viewer)->test(CategoriesManage::class)->call('exportCategoriesCsv')
        );

        $this->assertStringContainsString('Cat A', $csv);
    }

    public function test_subcategories_export_is_global_not_franchise_filtered(): void
    {
        $category = ServiceCategory::create(['name' => 'Cat', 'slug' => 'cat', 'module' => 'service', 'is_active' => true]);
        ServiceSubcategory::create(['category_id' => $category->id, 'name' => 'Sub A', 'slug' => 'sub-a', 'is_active' => true]);
        [, , $franchise] = $this->makeFranchiseTree();
        $viewer = $this->makeUserWithPermission('categories.manage', 'franchise', $franchise->id);

        $csv = $this->csvContent(
            Livewire::actingAs($viewer)->test(SubcategoriesManage::class)->call('exportSubcategoriesCsv')
        );

        $this->assertStringContainsString('Sub A', $csv);
    }

    public function test_services_export_is_global_not_franchise_filtered(): void
    {
        $category = ServiceCategory::create(['name' => 'Cat', 'slug' => 'cat', 'module' => 'service', 'is_active' => true]);
        Service::create(['category_id' => $category->id, 'name' => 'Svc A', 'slug' => 'svc-a', 'base_price' => 100, 'price_type' => 'fixed', 'duration_estimate_mins' => 30, 'is_active' => true, 'location_required' => true, 'age_restriction' => false, 'sort_order' => 1]);
        [, , $franchise] = $this->makeFranchiseTree();
        $viewer = $this->makeUserWithPermission('services.manage', 'franchise', $franchise->id);

        $csv = $this->csvContent(
            Livewire::actingAs($viewer)->test(ServicesManage::class)->call('exportServicesCsv')
        );

        $this->assertStringContainsString('Svc A', $csv);
    }
}
