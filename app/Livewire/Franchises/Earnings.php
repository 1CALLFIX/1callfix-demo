<?php

namespace App\Livewire\Franchises;

use App\Models\Booking;
use App\Models\Commission;
use App\Models\Franchise;
use App\Models\Payout;
use App\Models\ProviderCommissionReceivable;
use App\Services\AuthorizationService;
use App\Services\TimezoneResolver;
use App\Services\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Owner request 2026-10-21 — a franchise owner sees what their franchise earned. Read-only. The franchise's share of
 * every completed job is credited to the franchise owner's wallet by CommissionService (online) or as the provider
 * pays the cash commission (PayoutService), so this screen reads those same records:
 *  - commissions.franchise_commission for the franchise's bookings in the chosen month;
 *  - the cash-job franchise share not yet collected from providers (provider_commission_receivables);
 *  - the owner's wallet balance and franchise_owner payouts (only shown to the owner and Super Admin).
 *
 * Who sees which franchise: Super Admin all; otherwise franchises the user owns plus those their commissions.view
 * grant covers.
 */
class Earnings extends Component
{
    public ?int $franchiseId = null;

    /** Y-m in the platform timezone */
    public string $month = '';

    protected $queryString = ['franchiseId', 'month'];

    public function mount(): void
    {
        abort_if($this->franchises()->isEmpty(), 403, 'You do not have a franchise to show earnings for.');

        if (! $this->franchiseId || ! $this->franchises()->contains('id', $this->franchiseId)) {
            $this->franchiseId = $this->franchises()->first()->id;
        }
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now(app(TimezoneResolver::class)->platformTimezone())->format('Y-m');
        }
    }

    /** @return Collection<int, Franchise> */
    private function franchises(): Collection
    {
        $user = auth()->user();
        if ($user->role === 'super_admin') {
            return Franchise::orderBy('name')->get(['id', 'name', 'owner_user_id', 'city_id', 'country_id', 'commission_model', 'commission_value']);
        }

        $authz = app(AuthorizationService::class);

        return Franchise::orderBy('name')->get(['id', 'name', 'owner_user_id', 'city_id', 'country_id', 'commission_model', 'commission_value'])
            ->filter(fn (Franchise $f) => $f->owner_user_id === $user->id
                || $authz->can($user, 'commissions.view', array_filter(['franchise_id' => $f->id, 'city_id' => $f->city_id, 'country_id' => $f->country_id])))
            ->values();
    }

    public function render()
    {
        $franchise = $this->franchises()->firstWhere('id', $this->franchiseId);
        abort_unless($franchise, 404);

        $tz = app(TimezoneResolver::class)->platformTimezone();
        $start = Carbon::createFromFormat('Y-m-d H:i:s', $this->month.'-01 00:00:00', $tz)->utc();
        $end = $start->copy()->setTimezone($tz)->addMonth()->utc();

        $bookingIds = Booking::where('franchise_id', $franchise->id)->select('id');
        $monthCommissions = Commission::whereIn('booking_id', $bookingIds)->whereBetween('created_at', [$start, $end]);

        $receivables = ProviderCommissionReceivable::whereIn('booking_id', Booking::where('franchise_id', $franchise->id)->select('id'))
            ->where('franchise_portion', '>', 0)->get(['franchise_portion', 'franchise_settled']);

        $user = auth()->user();
        $seesWallet = $franchise->owner_user_id && ($user->role === 'super_admin' || $user->id === $franchise->owner_user_id);

        return view('livewire.franchises.earnings', [
            'franchises' => $this->franchises(),
            'franchise' => $franchise,
            'jobs' => (clone $monthCommissions)->count(),
            'gross' => round((float) (clone $monthCommissions)->sum(\Illuminate\Support\Facades\DB::raw('provider_commission + franchise_commission + platform_commission')), 2),
            'earned' => round((float) (clone $monthCommissions)->sum('franchise_commission'), 2),
            'cashPending' => round($receivables->sum(fn ($r) => max(0, (float) $r->franchise_portion - (float) $r->franchise_settled)), 2),
            'rows' => (clone $monthCommissions)->with('booking:id,code,payment_method,completed_at')->latest('id')->limit(50)->get(),
            'seesWallet' => $seesWallet,
            'walletBalance' => $seesWallet ? app(WalletService::class)->balance($franchise->owner) : null,
            'payouts' => $seesWallet ? Payout::where('payee_type', 'franchise_owner')->where('payee_id', $franchise->owner_user_id)->latest('id')->limit(10)->get() : collect(),
            'months' => collect(range(0, 11))->map(fn ($i) => now($tz)->startOfMonth()->subMonths($i)->format('Y-m')),
        ])->layout('layouts.admin', ['title' => 'Franchise Earnings']);
    }
}
