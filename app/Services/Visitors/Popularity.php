<?php

namespace App\Services\Visitors;

use App\Models\Booking;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderItem;
use App\Models\PageView;
use App\Models\Setting;
use App\Support\SiteText;
use Illuminate\Support\Facades\Cache;

/**
 * The popularity line under an item's photo (owner 2026-11-21, the Amazon "1K+ bought in past month" and OLX "19 views
 * in past hour" pattern): "👁 19 people viewed this in the past hour" and "🔥 50+ booked in the past month".
 *
 * REAL NUMBERS ONLY (India's 2023 dark-pattern guidelines ban false urgency): views are distinct visitors from
 * page_views (Live Visitors); orders are real paid / cash orders (the same booked() scopes as the Dashboard), never
 * cancelled ones, never padded. Orders round DOWN to 10+, 20+, 50+, 100+, 500+, 1K+. Each line shows only once
 * Super Admin has set its minimum (setting null = off, fail closed) and the count reaches it. Cached 60 s per item,
 * the same as a cached page.
 */
class Popularity
{
    public const VIEWS_MIN = 'popularity.views_min';

    public const ORDERS_MIN = 'popularity.orders_min';

    /** @return list<array{icon: string, text: string, kind: string}> */
    public function lines(string $item, string $path): array
    {
        $viewsMin = self::minimum(self::VIEWS_MIN);
        $ordersMin = self::minimum(self::ORDERS_MIN);
        if ($viewsMin === null && $ordersMin === null) {
            return [];
        }

        $path = '/'.ltrim($path, '/');
        $counts = Cache::remember('popularity:'.md5($item.'|'.$path), 60, fn () => [
            'hour' => $viewsMin === null ? 0 : $this->viewers($path, now()->subHour()),
            'day' => $viewsMin === null ? 0 : $this->viewers($path, now()->subDay()),
            'orders' => $ordersMin === null ? null : $this->orders($item),
        ]);

        $lines = [];
        if ($ordersMin !== null && $counts['orders'] !== null && $counts['orders'][0] >= $ordersMin) {
            [$n, $kind] = $counts['orders'];
            $lines[] = ['icon' => '🔥', 'kind' => 'orders', 'text' => SiteText::get("popularity.{$kind}", ['n' => self::rounded($n)])];
        }
        if ($viewsMin !== null && VisitTracker::enabled()) {
            if ($counts['hour'] >= $viewsMin) {
                $lines[] = ['icon' => '👁', 'kind' => 'views', 'text' => SiteText::get('popularity.viewed_hour', ['n' => $counts['hour']])];
            } elseif ($counts['day'] >= $viewsMin) {
                $lines[] = ['icon' => '👁', 'kind' => 'views', 'text' => SiteText::get('popularity.viewed_day', ['n' => $counts['day']])];
            }
        }

        return $lines;
    }

    public static function minimum(string $key): ?int
    {
        $value = Setting::get($key);

        return ($value === null || trim((string) $value) === '' || (int) $value < 1) ? null : (int) $value;
    }

    /** 7 → "7", 64 → "50+", 1250 → "1K+". Never rounds up. */
    public static function rounded(int $n): string
    {
        foreach ([1000 => '1K+', 500 => '500+', 100 => '100+', 50 => '50+', 20 => '20+', 10 => '10+'] as $floor => $label) {
            if ($n >= $floor) {
                return $n >= 2000 ? floor($n / 1000).'K+' : $label;
            }
        }

        return (string) $n;
    }

    private function viewers(string $path, $since): int
    {
        return (int) PageView::query()->where('path', $path)->where('created_at', '>=', $since)->distinct()->count('visitor_id');
    }

    /**
     * Real orders in the past 30 days for the item, or null for kinds without orders through us yet.
     *
     * @return array{0: int, 1: string}|null [count, wording key]
     */
    private function orders(string $item): ?array
    {
        [$type, $id] = array_pad(explode(':', $item, 2), 2, null);
        $id = (int) $id;
        if ($id < 1) {
            return null;
        }
        $since = now()->subDays(30);

        return match ($type) {
            'service' => [Booking::query()->booked()->where('service_id', $id)->where('status', '!=', 'cancelled')
                ->where('created_at', '>=', $since)->count(), 'booked'],
            'store' => [MarketplaceOrder::query()->booked()->where('store_id', $id)->where('status', '!=', 'cancelled')
                ->where('created_at', '>=', $since)->count(), 'ordered'],
            'product' => [(int) MarketplaceOrderItem::query()->where('product_id', $id)
                ->whereHas('order', fn ($q) => $q->booked()->where('status', '!=', 'cancelled')->where('created_at', '>=', $since))
                ->distinct()->count('marketplace_order_id'), 'bought'],
            default => null,
        };
    }
}
