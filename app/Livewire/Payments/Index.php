<?php

namespace App\Livewire\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\AuthorizationService;
use App\Support\Concerns\HasCsvExport;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only visibility into `payments` -- every gateway order attempt
 * across all seven purposes (booking / wallet_topup / plan_subscription /
 * parcel_order / taxi_ride / property_reservation / marketplace_order),
 * previously invisible anywhere in the admin panel (confirmed absent by
 * grep during this session's payment-gateway forensic audit; WalletLedger
 * shows the CREDIT/DEBIT ledger, a different table, not this one).
 *
 * Mutations (capture/refund) are NOT duplicated here -- capture happens via
 * the gateway's own signature-verified webhook, and refund happens via
 * booking cancellation (CancellationService::refundIfPaid(), already gated
 * by bookings.cancel at the booking's own scope). This screen only shows
 * what already happened.
 */
class Index extends Component
{
    use WithPagination;
    use HasCsvExport;

    public string $purposeFilter = '';
    public string $statusFilter = '';
    public string $search = '';

    protected $queryString = ['purposeFilter', 'statusFilter', 'search'];

    /** payments.view was seeded (2026_08_14_002000) specifically for this screen. */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('payments.view'), 403, 'You do not have permission to view payments.');
    }

    public function updatingPurposeFilter() { $this->resetPage(); }
    public function updatingStatusFilter() { $this->resetPage(); }
    public function updatingSearch() { $this->resetPage(); }

    /**
     * A payment reaches its geography through ONE of six relations
     * depending on purpose -- booking.* / user.* (the original two), plus
     * parcelOrder.* / taxiRide.* / propertyReservation.* / marketplaceOrder.*
     * (Phase 22.4/22.6/22.7/24, each implements App\Contracts\Orderable and
     * carries its own franchise directly). **2026-08-17 hardening finding:**
     * these four were never added here -- a franchise-scoped `payments.view`
     * grant could see NEITHER their booking.* NOR user.* path resolve
     * (both genuinely null for these purposes), so `scopeQuery()`'s own
     * documented fail-closed behavior ("no grant covers a scope level this
     * model even carries") hid every one of their payments from every
     * non-global admin -- not a security hole (it failed closed, not
     * open), but a real visibility gap for four real, shipped verticals.
     * scopeQuery()'s array-of-candidates form ORs all six paths; whichever
     * ones are irrelevant for a given row are simply null and their
     * whereHas() never matches.
     */
    private function scopeColumns(): array
    {
        return [
            'zone_id' => ['booking.zone_id', 'user.zone_id', 'parcelOrder.zone_id', 'taxiRide.zone_id', 'propertyReservation.zone_id', 'marketplaceOrder.zone_id', 'rentalReservation.zone_id', 'hotelReservation.zone_id'],
            'franchise_id' => ['booking.franchise_id', 'user.franchise_id', 'parcelOrder.franchise_id', 'taxiRide.franchise_id', 'propertyReservation.franchise_id', 'marketplaceOrder.franchise_id', 'rentalReservation.franchise_id', 'hotelReservation.franchise_id'],
            'city_id' => ['booking.franchise.city_id', 'user.franchise.city_id', 'parcelOrder.franchise.city_id', 'taxiRide.franchise.city_id', 'propertyReservation.franchise.city_id', 'marketplaceOrder.franchise.city_id', 'rentalReservation.franchise.city_id', 'hotelReservation.franchise.city_id'],
            'country_id' => ['booking.franchise.country_id', 'user.franchise.country_id', 'parcelOrder.franchise.country_id', 'taxiRide.franchise.country_id', 'propertyReservation.franchise.country_id', 'marketplaceOrder.franchise.country_id', 'rentalReservation.franchise.country_id', 'hotelReservation.franchise.country_id'],
        ];
    }

    /** Scope + every current filter (purpose/status/search), in one place — render() paginates it, exportPaymentsCsv() streams every matching row unpaginated. */
    private function filteredPaymentsQuery()
    {
        return app(AuthorizationService::class)
            ->scopeQuery(Payment::query(), auth()->user(), 'payments.view', $this->scopeColumns())
            ->with([
                'booking.customer', 'booking.franchise.country', 'user.franchise.country',
                'parcelOrder.customer', 'parcelOrder.franchise.country',
                'taxiRide.customer', 'taxiRide.franchise.country',
                'propertyReservation.customer', 'propertyReservation.franchise.country',
                'marketplaceOrder.customer', 'marketplaceOrder.franchise.country',
                'rentalReservation.customer', 'rentalReservation.franchise.country',
                'hotelReservation.customer', 'hotelReservation.franchise.country',
            ])
            ->when($this->purposeFilter !== '', fn ($q) => $q->where('purpose', $this->purposeFilter))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $w->where('gateway_order_id', 'like', "%{$this->search}%")
                    ->orWhere('gateway_payment_id', 'like', "%{$this->search}%")
                    ->orWhereHas('booking', fn ($b) => $b->where('code', 'like', "%{$this->search}%"))
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$this->search}%")->orWhere('phone', 'like', "%{$this->search}%"))
                    ->orWhereHas('parcelOrder', fn ($o) => $o->where('code', 'like', "%{$this->search}%"))
                    ->orWhereHas('taxiRide', fn ($o) => $o->where('code', 'like', "%{$this->search}%"))
                    ->orWhereHas('propertyReservation', fn ($o) => $o->where('code', 'like', "%{$this->search}%"))
                    ->orWhereHas('marketplaceOrder', fn ($o) => $o->where('code', 'like', "%{$this->search}%"))
                    ->orWhereHas('rentalReservation', fn ($o) => $o->where('code', 'like', "%{$this->search}%"))
                    ->orWhereHas('hotelReservation', fn ($o) => $o->where('code', 'like', "%{$this->search}%"));
            }));
    }

    /** Export Everywhere session, Part 1 — current filtered + scoped view as CSV. Never the gateway secret/signature, only what's already shown on-screen. */
    public function exportPaymentsCsv()
    {
        return $this->streamCsvExport(
            'payments-filtered-'.now()->format('Y-m-d-His').'.csv',
            $this->filteredPaymentsQuery(),
            ['id', 'purpose', 'payer', 'amount', 'gateway', 'gateway_order_id', 'gateway_payment_id', 'status', 'created_at'],
            fn (Payment $p) => [$p->id, $p->purpose, $p->user?->name ?? $p->booking?->customer?->name, $p->amount, $p->gateway, $p->gateway_order_id, $p->gateway_payment_id, $p->status, $p->created_at],
        );
    }

    public function render()
    {
        $payments = $this->filteredPaymentsQuery()
            ->latest()
            ->paginate(25);

        $gateway = app(PaymentGateway::class);

        return view('livewire.payments.index', [
            'payments' => $payments,
            'gatewayDisplayName' => $gateway->displayName(),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('layouts.admin', ['title' => 'Payments']);
    }
}
