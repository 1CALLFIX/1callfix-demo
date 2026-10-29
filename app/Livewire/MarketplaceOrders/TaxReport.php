<?php

namespace App\Livewire\MarketplaceOrders;

use App\Models\MarketplaceSettlement;
use App\Support\SuperAdminGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Shop Tax Report (E-commerce Phase 5): per seller per month — GSTIN, PAN, orders, taxable value, the GST
 * TCS and the income-tax TDS withheld (for the platform's returns and to deposit), with a CSV download for the CA.
 * Super Admin only. By the month the order was completed (settlement created).
 */
class TaxReport extends Component
{
    public string $month = '';

    public function mount(): void
    {
        SuperAdminGate::authorize(auth()->user());
        $this->month = now()->format('Y-m');
    }

    private function rows()
    {
        $start = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();

        return MarketplaceSettlement::query()
            ->join('marketplace_orders', 'marketplace_orders.id', '=', 'marketplace_settlements.marketplace_order_id')
            ->join('stores', 'stores.id', '=', 'marketplace_orders.store_id')
            ->whereBetween('marketplace_settlements.created_at', [$start, $start->copy()->endOfMonth()])
            ->groupBy('stores.id', 'stores.name', 'stores.gstin', 'stores.pan')
            ->orderBy('stores.name')
            ->get([
                'stores.id', 'stores.name', 'stores.gstin', 'stores.pan',
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(marketplace_orders.subtotal) as taxable'),
                DB::raw('SUM(marketplace_settlements.tcs_gst_amount) as tcs'),
                DB::raw('SUM(marketplace_settlements.tds_income_tax_amount) as tds'),
            ]);
    }

    public function download(): StreamedResponse
    {
        SuperAdminGate::authorize(auth()->user());
        $rows = $this->rows();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['shop', 'gstin', 'pan', 'orders', 'taxable_value', 'gst_tcs', 'income_tax_tds']);
            foreach ($rows as $r) {
                fputcsv($out, [$r->name, $r->gstin, $r->pan, $r->orders, round((float) $r->taxable, 2), round((float) $r->tcs, 2), round((float) $r->tds, 2)]);
            }
            fclose($out);
        }, 'shop-tax-'.$this->month.'.csv', ['Content-Type' => 'text/csv']);
    }

    public function render()
    {
        return view('livewire.marketplace-orders.tax-report', ['rows' => $this->rows()])
            ->layout('layouts.admin', ['title' => 'Shop Tax Report']);
    }
}
