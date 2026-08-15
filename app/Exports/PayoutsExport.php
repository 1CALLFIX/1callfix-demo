<?php

namespace App\Exports;

use App\Models\Payout;
use App\Models\Provider;
use App\Models\User;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Mission Phase 14 (Operations Import/Export completeness) — maps onto the
 * historical reference product's "Payouts" export. Real payout records
 * only (App\Models\Payout, already the source of truth Payouts\Manage
 * reads/writes) — never a recalculated figure.
 *
 * Scope note: matches Payouts\Manage's OWN current (unscoped) behavior —
 * `payouts.manage` is a global-oriented permission in this codebase (per
 * the Phase 11 audit convention for "sensitive config" screens with no
 * separate franchise-scoped .view), and the existing screen itself shows
 * every payout to anyone who can open it, with no row-level franchise
 * filter. This export deliberately does not invent stricter scoping than
 * the screen it exports from — that inconsistency (a franchise-scoped
 * payouts.manage grant can view the screen but the screen doesn't itself
 * filter by scope) is a real, pre-existing gap, logged in
 * KNOWN_RISKS_AND_DECISIONS.md rather than silently changed here.
 *
 * Never includes raw banking details — PaymentAccount::masked_account_number
 * (last 4 digits only), never account_number/ifsc.
 */
class PayoutsExport implements FromCollection, WithHeadings, WithMapping
{
    public function collection()
    {
        return Payout::with('paymentAccount')->latest()->get();
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
