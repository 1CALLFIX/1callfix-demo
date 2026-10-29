<?php

namespace Tests\Feature\Marketplace;

use App\Actions\CompleteMarketplaceOrderAction;
use App\Livewire\MarketplaceOrders\TaxReport;
use App\Livewire\Seller\Shop;
use App\Models\MarketplaceSettlement;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * E-commerce Phase 5 (compliance, 2026-10-29). Owner: build it now with the rates as unset Super Admin settings; the
 * CA fills them in. Proves nothing is withheld while unset, TCS / TDS come out of the seller's share once set (and a
 * cash-pickup seller owes them back), the invoice is private to the people on the order, and the monthly report.
 */
class ShopTaxComplianceTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private function complete(string $method = 'online'): array
    {
        $s = $this->makeMarketplaceOrderScenario('ready', [
            'order_type' => 'pickup', 'subtotal' => 1000, 'total_amount' => 1000,
            'payment_method' => $method, 'payment_status' => $method === 'cash' ? 'pending' : 'paid',
        ]);
        $s['franchise']->update(['platform_fee_percent' => 20, 'commission_model' => 'none', 'commission_value' => 0]);
        $s['order']->items()->create(['product_id' => $s['product']->id, 'product_name_snapshot' => 'Lamp', 'quantity' => 1, 'unit_price' => 1000, 'line_total' => 1000]);
        app(CompleteMarketplaceOrderAction::class)->execute($s['order']->id);

        return $s + ['row' => MarketplaceSettlement::where('marketplace_order_id', $s['order']->id)->sole()];
    }

    public function test_nothing_is_withheld_while_the_rates_are_unset(): void
    {
        $row = $this->complete()['row'];
        $this->assertEquals(0, $row->tcs_gst_amount);
        $this->assertEquals(0, $row->tds_income_tax_amount);
        $this->assertEquals(800, $row->seller_share);
    }

    public function test_tcs_and_tds_come_out_of_the_seller_share_and_a_cash_pickup_seller_owes_them(): void
    {
        Setting::set('marketplace.tcs_gst_percent', '1');
        Setting::set('marketplace.tds_194o_percent', '0.1');

        $online = $this->complete('online')['row'];
        $this->assertEquals(10, $online->tcs_gst_amount);
        $this->assertEquals(1, $online->tds_income_tax_amount);
        $this->assertEquals(789, $online->seller_share, '800 - 10 TCS - 1 TDS');

        $cash = $this->complete('cash')['row'];
        $this->assertEquals(211, $cash->cash_owed, 'commission 200 + TCS 10 + TDS 1, the seller holds the cash');
    }

    public function test_the_invoice_shows_the_sellers_gstin_and_only_to_people_on_the_order(): void
    {
        $s = $this->complete();
        Livewire::actingAs($s['owner']->user)->test(Shop::class)
            ->set('gstin', 'bad')->call('save')->assertHasErrors('gstin')
            ->set('gstin', '37ABCDE1234F1Z5')->set('pan', 'ABCDE1234F')->call('save')->assertHasNoErrors();

        $url = route('customer.shop.orders.invoice', $s['order']);
        $this->actingAs($s['customer'])->get($url)->assertOk()->assertSee('Tax invoice')->assertSee('37ABCDE1234F1Z5')->assertSee('INV-'.$s['order']->code);
        $this->actingAs($s['owner']->user)->get($url)->assertOk();
        $this->actingAs($this->makeCustomer())->get($url)->assertNotFound();
    }

    public function test_the_monthly_report_totals_per_seller_and_is_super_admin_only(): void
    {
        Setting::set('marketplace.tcs_gst_percent', '1');
        $s = $this->complete();
        Livewire::actingAs($this->makeSuperAdmin())->test(TaxReport::class)
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 1 && round((float) $rows->first()->tcs, 2) === 10.0)
            ->call('download')->assertFileDownloaded('shop-tax-'.now()->format('Y-m').'.csv');

        $this->actingAs($s['owner']->user)->get(route('admin.shop-tax-report'))->assertForbidden();
    }
}
