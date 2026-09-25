<?php

namespace App\Livewire\Customer\Earnings;

use App\Livewire\Customer\Earnings\Concerns\EarningsTab;
use App\Models\LoyaltyPoint;
use App\Models\Setting;
use App\Services\LoyaltyService;
use App\Support\EarningsSettings;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — Stage 4, Earnings → Loyalty points.
 *
 * Available is the live FIFO balance (LoyaltyService::summary()), correct
 * before the expiry job runs. Redeem is two steps — preview (points in, ₹
 * out, computed on the server from settings) then confirm — and the confirm
 * step hands ONLY the points to LoyaltyService::redeem(), which recomputes
 * the rupees itself: nothing the browser sends can change the amount
 * credited. Redemption is customers only (D1): any other role gets 403
 * here, exactly like the API.
 */
class Loyalty extends Component
{
    use EarningsTab;
    use WithPagination;

    public string $redeemPoints = '';

    /** Set by preview(); confirm() re-validates it against the server-side quote. */
    public ?int $previewPoints = null;

    public ?float $previewRupees = null;

    public string $error = '';

    public string $notice = '';

    protected function tabSwitch(): string
    {
        return 'earnings.loyalty_tab';
    }

    public function mount(): void
    {
        $this->ensureTabOn();
    }

    /** @return array{on: bool, rate: ?int, min: ?int} the redemption policy as the server sees it */
    private function policy(): array
    {
        $scope = $this->earningsScope();

        return [
            'on' => EarningsSettings::on('loyalty.redeem_enabled', $scope),
            'rate' => EarningsSettings::integer('loyalty.points_per_rupee_redemption', $scope),
            'min' => EarningsSettings::integer('loyalty.min_redemption_points', $scope),
        ];
    }

    public function preview(): void
    {
        $this->ensureTabOn();
        abort_unless(auth()->user()->role === 'customer', 403);
        $this->reset('error', 'notice', 'previewPoints', 'previewRupees');

        $this->validate(['redeemPoints' => ['required', 'integer', 'min:1']]);

        $policy = $this->policy();
        if (! $policy['on'] || $policy['rate'] === null || $policy['rate'] < 1 || $policy['min'] === null) {
            $this->error = 'Loyalty redemption is currently unavailable.';

            return;
        }

        $points = (int) $this->redeemPoints;
        $this->previewPoints = $points;
        $this->previewRupees = round($points / $policy['rate'], 2);
    }

    public function confirmRedeem(LoyaltyService $loyalty): void
    {
        $this->ensureTabOn();
        abort_unless(auth()->user()->role === 'customer', 403);
        $this->reset('error', 'notice');

        if ($this->previewPoints === null || $this->previewPoints !== (int) $this->redeemPoints) {
            $this->error = 'Please review the redemption again before confirming.';

            return;
        }

        try {
            $result = $loyalty->redeem(auth()->user(), $this->previewPoints, $this->earningsScope());
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $symbol = Setting::get('locale.currency_symbol', '₹');
        $this->notice = "Redeemed {$result['points_redeemed']} points for {$symbol}".number_format($result['rupees_credited'], 2).' — added to your wallet.';
        $this->reset('redeemPoints', 'previewPoints', 'previewRupees');
    }

    public function render(LoyaltyService $loyalty)
    {
        $this->ensureTabOn();

        $user = auth()->user();
        $scope = $this->earningsScope();
        $policy = $this->policy();

        return view('livewire.customer.earnings.loyalty', [
            'tabs' => $this->visibleTabs(),
            'summary' => $loyalty->summary($user),
            'history' => LoyaltyPoint::query()->with('booking:id,code,customer_id')
                ->where('user_id', $user->id)->latest('id')->paginate(20),
            'policy' => $policy,
            'earnRate' => EarningsSettings::on('loyalty.customer_enabled', $scope)
                ? EarningsSettings::number('loyalty.customer_points_per_currency_unit', $scope) : null,
            'expiryDays' => EarningsSettings::integer('loyalty.points_expiry_days', $scope),
            'isCustomer' => $user->role === 'customer',
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', ['title' => 'Loyalty points']);
    }
}
