<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\HotelReservation;
use App\Models\MarketplaceOrder;
use App\Models\ParcelOrder;
use App\Models\PropertyReservation;
use App\Models\RentalReservation;
use App\Models\TaxiRide;
use App\Models\User;
use App\Services\AuthorizationService;
use Carbon\CarbonInterface;

/**
 * Admin dashboard (owner request 2026-11-03): one sales line per module, so every transaction (services, parcel,
 * shop, food, taxi, rental, hotel) shows on the dashboard with a module-wise table and a grand total.
 *
 * Each module is read with the SAME permission and row scope as its own admin screen, so a viewer only sees the
 * modules (and the franchise/zone rows) they could open anyway. "Sales" is the value of orders that finished
 * (completed / delivered / checked out), placed in the period; cash vs online splits that same figure.
 */
class ModuleSales
{
    private const SCOPE_COLUMNS = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];

    /**
     * key => label, colour, dashboard tab, and one or more sources (model + permission + statuses + value column).
     * Shop and Food share the marketplace order table, split by its module column.
     */
    public static function modules(): array
    {
        return [
            'service' => ['label' => 'Services', 'colour' => 'blue', 'prelaunch' => false, 'sources' => [
                ['model' => Booking::class, 'permission' => 'dashboard.view', 'done' => ['completed'], 'value' => 'COALESCE(price_final, price_quoted)', 'route' => 'admin.bookings.index', 'link_permission' => 'bookings.view'],
            ]],
            'parcel' => ['label' => 'Parcel', 'colour' => 'orange', 'prelaunch' => false, 'sources' => [
                ['model' => ParcelOrder::class, 'permission' => 'parcel_orders.view', 'done' => ['delivered'], 'value' => 'COALESCE(price_final, price_quoted)', 'route' => 'admin.parcel-orders.index'],
            ]],
            'shop' => ['label' => 'Shop', 'colour' => 'violet', 'prelaunch' => false, 'sources' => [
                ['model' => MarketplaceOrder::class, 'permission' => 'marketplace_orders.view', 'done' => ['completed'], 'value' => 'COALESCE(price_final, total_amount)', 'route' => 'admin.marketplace-orders.index', 'params' => ['moduleFilter' => 'shop'],
                    'where' => fn ($q) => $q->where('module', '!=', Modules::FOOD)],
            ]],
            'food' => ['label' => 'Food', 'colour' => 'rose', 'prelaunch' => false, 'sources' => [
                ['model' => MarketplaceOrder::class, 'permission' => 'marketplace_orders.view', 'done' => ['completed'], 'value' => 'COALESCE(price_final, total_amount)', 'route' => 'admin.marketplace-orders.index', 'params' => ['moduleFilter' => 'food'],
                    'where' => fn ($q) => $q->where('module', Modules::FOOD)],
            ]],
            'taxi' => ['label' => 'Taxi', 'colour' => 'amber', 'prelaunch' => true, 'sources' => [
                ['model' => TaxiRide::class, 'permission' => 'taxi_rides.view', 'done' => ['trip_completed'], 'value' => 'COALESCE(price_final, price_quoted)', 'route' => 'admin.taxi-rides.index'],
            ]],
            'rental' => ['label' => 'Rental', 'colour' => 'teal', 'prelaunch' => true, 'sources' => [
                ['model' => RentalReservation::class, 'permission' => 'rental_reservations.view', 'done' => ['returned', 'completed'], 'value' => 'COALESCE(price_final, price_quoted)', 'route' => 'admin.rental-reservations.index'],
                ['model' => PropertyReservation::class, 'permission' => 'property_reservations.view', 'done' => ['completed'], 'value' => 'COALESCE(price_final, price_quoted)', 'route' => 'admin.property-reservations.index'],
            ]],
            'hotel' => ['label' => 'Hotel', 'colour' => 'cyan', 'prelaunch' => true, 'sources' => [
                ['model' => HotelReservation::class, 'permission' => 'hotel_reservations.view', 'done' => ['checked_out', 'completed'], 'value' => 'COALESCE(price_final, price_quoted)', 'route' => 'admin.hotel-reservations.index'],
            ]],
        ];
    }

    /** The module keys this user can see (at least one source permission held). */
    public static function visibleKeys(User $user): array
    {
        return array_keys(array_filter(self::modules(), fn ($m) => collect($m['sources'])
            ->contains(fn ($s) => $user->hasPermissionAnywhere($s['permission']))));
    }

    /**
     * One row per visible module: orders, open, completed, cancelled, sales, online, cash, link.
     *
     * @return array<string, array{label: string, colour: string, prelaunch: bool, orders: int, open: int, completed: int, cancelled: int, sales: float, online: float, cash: float, route: ?string}>
     */
    public static function summary(User $user, CarbonInterface $from): array
    {
        $rows = [];
        foreach (self::modules() as $key => $module) {
            $row = ['label' => $module['label'], 'colour' => $module['colour'], 'prelaunch' => $module['prelaunch'],
                'orders' => 0, 'open' => 0, 'completed' => 0, 'cancelled' => 0, 'sales' => 0.0, 'online' => 0.0, 'cash' => 0.0, 'route' => null];
            $seen = false;

            foreach ($module['sources'] as $source) {
                if (! $user->hasPermissionAnywhere($source['permission'])) {
                    continue;
                }
                $seen = true;
                if ($user->hasPermissionAnywhere($source['link_permission'] ?? $source['permission'])) {
                    $row['route'] ??= route($source['route'], $source['params'] ?? []);
                }

                $base = app(AuthorizationService::class)
                    ->scopeQuery($source['model']::query(), $user, $source['permission'], self::SCOPE_COLUMNS)
                    ->where('created_at', '>=', $from);
                if (isset($source['where'])) {
                    ($source['where'])($base);
                }

                $byStatus = (clone $base)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
                $done = (clone $base)->whereIn('status', $source['done'])
                    ->selectRaw("SUM({$source['value']}) as total, SUM(CASE WHEN payment_method LIKE 'cash%' THEN {$source['value']} ELSE 0 END) as cash")
                    ->first();

                $completed = (int) collect($source['done'])->sum(fn ($s) => $byStatus[$s] ?? 0);
                $cancelled = (int) ($byStatus['cancelled'] ?? 0);
                $orders = (int) $byStatus->sum();

                $row['orders'] += $orders;
                $row['completed'] += $completed;
                $row['cancelled'] += $cancelled;
                $row['open'] += max(0, $orders - $completed - $cancelled - (int) ($byStatus['disputed'] ?? 0));
                $row['sales'] += round((float) ($done->total ?? 0), 2);
                $row['cash'] += round((float) ($done->cash ?? 0), 2);
            }

            if ($seen) {
                $row['online'] = round($row['sales'] - $row['cash'], 2);
                $rows[$key] = $row;
            }
        }

        return $rows;
    }

    /** Grand total across the given summary rows. */
    public static function total(array $rows): array
    {
        $sum = fn ($k) => collect($rows)->sum($k);

        return ['orders' => (int) $sum('orders'), 'open' => (int) $sum('open'), 'completed' => (int) $sum('completed'),
            'cancelled' => (int) $sum('cancelled'), 'sales' => round((float) $sum('sales'), 2),
            'online' => round((float) $sum('online'), 2), 'cash' => round((float) $sum('cash'), 2)];
    }
}
