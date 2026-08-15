<?php

namespace App\Exports;

use App\Models\Commission;
use App\Models\User;
use App\Services\AuthorizationService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Mission Phase 14 (Operations Import/Export completeness) — the historical
 * reference product's "Earnings" export maps onto 1CallFix's own
 * commissions ledger (CommissionService::applyForBooking() splits every
 * completed booking into provider/franchise/platform shares here). Real,
 * already-computed values only — this never recalculates a split, it reads
 * the same rows Commissions\Index already displays.
 *
 * Row-level scope: reuses the EXACT same AuthorizationService::scopeQuery()
 * call Commissions\Index::baseQuery() already uses, with the acting user
 * passed in — a franchise-scoped viewer's export contains only their own
 * franchise's commissions, never a cross-franchise leak. No sensitive
 * fields exist on this model (numeric splits + a booking reference only).
 */
class CommissionsExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private User $viewer)
    {
    }

    public function collection()
    {
        $columns = ['zone_id' => 'booking.zone_id', 'franchise_id' => 'booking.franchise_id', 'city_id' => 'booking.franchise.city_id', 'country_id' => 'booking.franchise.country_id'];

        return app(AuthorizationService::class)
            ->scopeQuery(Commission::query(), $this->viewer, 'commissions.view', $columns)
            ->with(['booking.franchise', 'booking.provider.user'])
            ->latest()
            ->get();
    }

    public function headings(): array
    {
        return ['booking_code', 'franchise', 'provider', 'provider_commission', 'franchise_commission', 'platform_commission', 'created_at'];
    }

    public function map($commission): array
    {
        return [
            $commission->booking?->code,
            $commission->booking?->franchise?->name,
            $commission->booking?->provider?->user?->name,
            $commission->provider_commission,
            $commission->franchise_commission,
            $commission->platform_commission,
            $commission->created_at?->toDateTimeString(),
        ];
    }
}
