<?php

namespace App\Exports;

use App\Models\Payout;
use App\Models\Provider;
use App\Models\User;
use App\Services\AuthorizationService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Mission Phase 14 (Operations Import/Export completeness) — maps onto the
 * historical reference product's "Payouts" export. Real payout records
 * only (App\Models\Payout, already the source of truth Payouts\Manage
 * reads/writes) — never a recalculated figure.
 *
 * Row-level scope (Phase 21 item TECH-1): reuses the same
 * AuthorizationService::visibleAmong() + Payout::authorizationScopeHint()
 * pattern Payouts\Manage::visiblePayoutIds() uses, with the acting user
 * passed in — a franchise-scoped viewer's export contains only their own
 * franchise's payouts, matching CommissionsExport's own constructor-
 * injection convention exactly. (Previously this deliberately matched the
 * screen's own then-unscoped behavior — both are now scoped together in
 * the same pass, not left inconsistent.)
 *
 * Never includes raw banking details — PaymentAccount::masked_account_number
 * (last 4 digits only), never account_number/ifsc.
 */
class PayoutsExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private User $viewer)
    {
    }

    public function collection()
    {
        $candidates = Payout::query()->select('id', 'payee_type', 'payee_id')->get();

        $visibleIds = app(AuthorizationService::class)
            ->visibleAmong($candidates, $this->viewer, 'payouts.manage')
            ->pluck('id');

        return Payout::whereIn('id', $visibleIds)->with('paymentAccount')->latest()->get();
    }

    public function headings(): array
    {
        return ['payee_type', 'payee', 'amount', 'period_start', 'period_end', 'status', 'settlement_account', 'gateway_ref', 'processed_at'];
    }

    public function map($payout): array
    {
        return [
            $payout->payee_type,
            $this->payeeLabel($payout),
            $payout->amount,
            $payout->period_start?->toDateString(),
            $payout->period_end?->toDateString(),
            $payout->status,
            $payout->paymentAccount ? "{$payout->paymentAccount->account_type} {$payout->paymentAccount->masked_account_number}" : null,
            $payout->gateway_ref,
            $payout->processed_at?->toDateTimeString(),
        ];
    }

    private function payeeLabel(Payout $payout): string
    {
        if ($payout->payee_type === 'provider') {
            $p = Provider::with('user')->find($payout->payee_id);

            return $p ? ($p->user->name ?? 'Provider #'.$p->id) : 'Provider #'.$payout->payee_id;
        }

        $u = User::find($payout->payee_id);

        return $u?->name ?? 'User #'.$payout->payee_id;
    }
}
