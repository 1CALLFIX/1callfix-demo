<?php

namespace App\Livewire\Customer\Earnings;

use App\Contracts\PaymentGateway;
use App\Livewire\Customer\Earnings\Concerns\EarningsTab;
use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\Setting;
use App\Models\Wallet as WalletModel;
use App\Models\WalletTransaction;
use App\Services\WalletTopUpService;
use App\Support\EarningsSettings;
use App\Support\WalletSourceLabel;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — Stage 4, Earnings → Wallet. Replaces the
 * Phase E6 Customer\Wallet\Index screen (customer.wallet now redirects here).
 *
 * Balance is the stored wallets.balance; history is the caller's OWN
 * wallet_transactions only, labelled by WalletSourceLabel, paginated, with
 * the booking / bundle code resolved in one query per page (no N+1).
 * Add money appears only while wallet.topup_enabled is on, and delegates to
 * WalletTopUpService::requestTopUp() — the same service the API uses, which
 * re-checks the switch, every limit and the freeze. Nothing here credits a
 * wallet.
 */
class Wallet extends Component
{
    use EarningsTab;
    use WithPagination;

    public string $topUpAmount = '';

    public string $error = '';

    public string $notice = '';

    protected function tabSwitch(): string
    {
        return 'earnings.wallet_tab';
    }

    public function mount(): void
    {
        $this->ensureTabOn();
    }

    public function requestTopUp(WalletTopUpService $topUps, PaymentGateway $gateway): void
    {
        $this->ensureTabOn();
        $this->reset('error', 'notice');

        $this->validate(
            ['topUpAmount' => ['required', 'numeric', 'min:1']],
            ['topUpAmount.min' => 'Enter an amount of at least 1.'],
        );

        if (! $gateway->isConfigured()) {
            $this->error = 'Wallet top-up needs an online payment gateway, which is not configured in this environment.';

            return;
        }

        try {
            $order = $topUps->requestTopUp(auth()->user(), (float) $this->topUpAmount, $this->earningsScope());
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->dispatch('razorpay-open', order: $order)->self();
        $this->notice = 'Complete the payment to add funds. Your balance updates once the payment is confirmed.';
    }

    public function render()
    {
        $this->ensureTabOn();

        $user = auth()->user();
        $wallet = WalletModel::where('user_id', $user->id)->first();

        $ledger = WalletTransaction::query()
            ->where('wallet_id', $wallet?->id ?? 0)
            ->latest('id')
            ->paginate(20);

        return view('livewire.customer.earnings.wallet', [
            'tabs' => $this->visibleTabs(),
            'balance' => (float) ($wallet?->balance ?? 0),
            'frozen' => (bool) $wallet?->frozen_at,
            'ledger' => $ledger,
            'codes' => $this->codesFor($ledger->getCollection()),
            'topUpOn' => EarningsSettings::on('wallet.topup_enabled', $this->earningsScope()),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
            'gatewayConfigured' => app(PaymentGateway::class)->isConfigured(),
        ])->layout('components.layouts.customer', ['title' => 'Wallet']);
    }

    /**
     * ref → human reference (booking / bundle code) for one page, two queries
     * total, scoped to the caller's own bookings so a ref can never surface
     * someone else's code.
     *
     * @return array<string, string>
     */
    private function codesFor($rows): array
    {
        $ids = ['booking' => [], 'booking_bundle' => []];
        foreach ($rows as $row) {
            if (preg_match('/^(booking|booking_bundle):(\d+):/', (string) $row->ref, $m)) {
                $ids[$m[1]][] = (int) $m[2];
            }
        }

        $userId = auth()->id();
        $bookings = $ids['booking'] ? Booking::whereIn('id', $ids['booking'])->where('customer_id', $userId)->pluck('code', 'id') : collect();
        $bundles = $ids['booking_bundle'] ? BookingBundle::whereIn('id', $ids['booking_bundle'])->where('customer_id', $userId)->pluck('code', 'id') : collect();

        $out = [];
        foreach ($rows as $row) {
            if (preg_match('/^(booking|booking_bundle):(\d+):/', (string) $row->ref, $m)) {
                $code = ($m[1] === 'booking' ? $bookings : $bundles)[(int) $m[2]] ?? null;
                if ($code) {
                    $out[$row->ref] = $code;
                }
            }
        }

        return $out;
    }

    public function label(?string $ref): string
    {
        return WalletSourceLabel::labelFor($ref);
    }
}
