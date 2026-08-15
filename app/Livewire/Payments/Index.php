<?php

namespace App\Livewire\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\AuthorizationService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only visibility into `payments` -- every gateway order attempt
 * across all three purposes (booking / wallet_topup / plan_subscription),
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
     * A payment reaches its geography through ONE of two relations
     * depending on purpose -- booking.* for a booking payment, user.* for a
     * wallet top-up or plan_subscription payment (both carry user_id, no
     * booking_id -- see SubscriptionService::createSubscriptionPaymentOrder(),
     * which sets user_id to the payer regardless of purpose). scopeQuery()'s
     * array-of-candidates form ORs both paths; the irrelevant one is simply
     * null on any given row and its whereHas() never matches.
     */
    private function scopeColumns(): array
    {
        return [
            'zone_id' => ['booking.zone_id', 'user.zone_id'],
            'franchise_id' => ['booking.franchise_id', 'user.franchise_id'],
            'city_id' => ['booking.franchise.city_id', 'user.franchise.city_id'],
            'country_id' => ['booking.franchise.country_id', 'user.franchise.country_id'],
        ];
    }

    public function render()
    {
        $payments = app(AuthorizationService::class)
            ->scopeQuery(Payment::query(), auth()->user(), 'payments.view', $this->scopeColumns())
            ->with(['booking.customer', 'booking.franchise.country', 'user.franchise.country'])
            ->when($this->purposeFilter !== '', fn ($q) => $q->where('purpose', $this->purposeFilter))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $w->where('gateway_order_id', 'like', "%{$this->search}%")
                    ->orWhere('gateway_payment_id', 'like', "%{$this->search}%")
                    ->orWhereHas('booking', fn ($b) => $b->where('code', 'like', "%{$this->search}%"))
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$this->search}%")->orWhere('phone', 'like', "%{$this->search}%"));
            }))
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
