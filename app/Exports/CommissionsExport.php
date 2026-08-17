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
 * fields exist on this model (numeric splits + an order reference only).
 *
 * **2026-08-17 hardening finding, same bug class as Commissions\Index's own
 * fix:** scope/mapping only ever covered `booking.*` — a franchise-scoped
 * viewer's export silently omitted every parcel/taxi/property/marketplace
 * commission row (a real financial-reporting completeness gap, not a
 * leak), and even an unrestricted export rendered empty order/franchise/
 * provider columns for them. Resolved generically per purpose, same
 * relation/earner mapping Commissions\Index's own blade view now uses.
 */
class CommissionsExport implements FromCollection, WithHeadings, WithMapping
{
    private const ORDER_RELATIONS = ['booking', 'parcelOrder', 'taxiRide', 'propertyReservation', 'marketplaceOrder'];

    public function __construct(private User $viewer)
    {
    }

    public function collection()
    {
        $columns = [
            'zone_id' => array_map(fn ($r) => "{$r}.zone_id", self::ORDER_RELATIONS),
            'franchise_id' => array_map(fn ($r) => "{$r}.franchise_id", self::ORDER_RELATIONS),
            'city_id' => array_map(fn ($r) => "{$r}.franchise.city_id", self::ORDER_RELATIONS),
            'country_id' => array_map(fn ($r) => "{$r}.franchise.country_id", self::ORDER_RELATIONS),
        ];

        return app(AuthorizationService::class)
            ->scopeQuery(Commission::query(), $this->viewer, 'commissions.view', $columns)
            ->with([
                'booking.franchise', 'booking.provider.user',
                'parcelOrder.franchise', 'parcelOrder.assignedWorker.user',
                'taxiRide.franchise', 'taxiRide.assignedWorker.user',
                'propertyReservation.franchise', 'propertyReservation.property.provider.user',
                'marketplaceOrder.franchise', 'marketplaceOrder.store.provider.user',
            ])
            ->latest()
            ->get();
    }

    public function headings(): array
    {
        return ['order_code', 'purpose', 'franchise', 'earner', 'provider_commission', 'franchise_commission', 'platform_commission', 'created_at'];
    }

    public function map($commission): array
    {
        $order = $commission->booking ?? $commission->parcelOrder ?? $commission->taxiRide ?? $commission->propertyReservation ?? $commission->marketplaceOrder;

        $earner = match (true) {
            (bool) $commission->booking => $commission->booking->provider?->user,
            (bool) $commission->parcelOrder => $commission->parcelOrder->assignedWorker?->user,
            (bool) $commission->taxiRide => $commission->taxiRide->assignedWorker?->user,
            (bool) $commission->propertyReservation => $commission->propertyReservation->property?->provider?->user,
            (bool) $commission->marketplaceOrder => $commission->marketplaceOrder->store?->provider?->user,
            default => null,
        };

        $purpose = match (true) {
            (bool) $commission->booking => 'booking',
            (bool) $commission->parcelOrder => 'parcel_order',
            (bool) $commission->taxiRide => 'taxi_ride',
            (bool) $commission->propertyReservation => 'property_reservation',
            (bool) $commission->marketplaceOrder => 'marketplace_order',
            default => null,
        };

        return [
            $order?->code,
            $purpose,
            $order?->franchise?->name,
            $earner?->name,
            $commission->provider_commission,
            $commission->franchise_commission,
            $commission->platform_commission,
            $commission->created_at?->toDateTimeString(),
        ];
    }
}
