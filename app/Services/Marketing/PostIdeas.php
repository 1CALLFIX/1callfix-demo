<?php

namespace App\Services\Marketing;

use App\Models\Banner;
use App\Models\City;
use App\Models\Coupon;
use App\Models\JobOpening;
use App\Models\PageView;
use App\Models\SocialPost;
use App\Models\Store;
use App\Services\Coupons\CouponSettings;
use App\Services\Visitors\PageClassifier;
use App\Support\AppLinks;
use Illuminate\Support\Str;

/**
 * Ready-made post ideas from live data (owner 2026-11-21: no typing): live banners, offers, the most viewed items this
 * week, new shops and restaurants, open jobs, and "get the app". Each idea carries its picture, captions (English +
 * Telugu), hashtags and link. Ideas for a switched-off module are left out (ModuleGate); an idea already used in the
 * last 14 days is left out too. Offer captions always say the offer is for online payment (thumb rule).
 */
class PostIdeas
{
    public function __construct(private ModuleGate $gate) {}

    /** @return list<array{source: string, source_ref: string, title: string, caption_en: string, caption_te: string, hashtags: string, image_path: ?string, link_url: ?string, module: ?string, city_id: ?int, badge: string}> */
    public function all(?int $cityId = null, int $limit = 30): array
    {
        $city = $cityId ? City::find($cityId) : City::where('is_active', true)->orderBy('id')->first();
        $cityName = $city?->name ?? 'Nellore';
        $tag = '#'.Str::studly($cityName);

        $ideas = array_merge(
            $this->banners($tag),
            $this->offers($tag),
            $this->topItems($tag, $cityName),
            $this->newShops($tag),
            $this->jobs($tag, $cityName),
            [$this->app($tag)],
        );

        $used = SocialPost::query()->where('created_at', '>=', now()->subDays(14))->whereNotNull('source_ref')
            ->get(['source', 'source_ref'])->map(fn ($p) => $p->source.':'.$p->source_ref)->all();

        $out = [];
        foreach ($ideas as $idea) {
            $idea['city_id'] ??= $city?->id;
            if (in_array($idea['source'].':'.$idea['source_ref'], $used, true) || $this->gate->offReason($idea['module'], $idea['city_id'])) {
                continue;
            }
            $out[] = $idea;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function idea(string $source, string $ref, string $badge, string $title, string $en, string $te, string $hashtags, ?string $image, ?string $link, ?string $module): array
    {
        return ['source' => $source, 'source_ref' => $ref, 'badge' => $badge, 'title' => Str::limit($title, 110, ''), 'caption_en' => $en, 'caption_te' => $te,
            'hashtags' => $hashtags, 'image_path' => $image, 'link_url' => $link, 'module' => $module, 'city_id' => null];
    }

    private function moduleTag(?string $module): string
    {
        return $module ? ' #'.Str::studly(PageClassifier::MODULE_LABELS[$module] ?? $module) : '';
    }

    private function banners(string $tag): array
    {
        return Banner::query()->currentlyLive()->whereNotNull('image')->orderBy('sort_order')->limit(10)->get()
            ->map(fn (Banner $b) => $this->idea('banner', (string) $b->id, '🖼️ Banner', $b->title,
                $b->title.' — book in a few taps on 1CallFix.',
                $b->title.' — 1CallFix లో కొన్ని ట్యాప్‌లలో బుక్ చేసుకోండి.',
                "#1CallFix {$tag}".$this->moduleTag($this->module($b->module)), $b->image, $b->link ?: '/', $this->module($b->module)))
            ->all();
    }

    private function offers(string $tag): array
    {
        if (! CouponSettings::enabled()) {
            return [];
        }

        return Coupon::query()->where('is_active', true)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()))
            ->limit(5)->get()
            ->map(function (Coupon $c) use ($tag) {
                $until = $c->valid_until ? ' Valid till '.$c->valid_until->format('j M').'.' : '';
                $min = $c->min_order_value ? ' On orders of ₹'.number_format((float) $c->min_order_value).' or more.' : '';

                return $this->idea('offer', (string) $c->id, '🎁 Offer', 'Offer: '.$c->code,
                    "🎁 {$c->name}: use code {$c->code} on 1CallFix.{$min}{$until} Offer applies when you pay online.",
                    "🎁 {$c->name}: 1CallFix లో {$c->code} కోడ్ వాడండి. ఆన్‌లైన్ చెల్లింపుకు మాత్రమే ఆఫర్ వర్తిస్తుంది.",
                    "#1CallFix {$tag} #Offer", null, '/offers', $this->module($c->module));
            })->all();
    }

    private function topItems(string $tag, string $cityName): array
    {
        return PageView::query()->where('page_type', 'item')->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('path, module, MAX(title) as title, COUNT(DISTINCT visitor_id) as people')
            ->groupBy('path', 'module')->orderByDesc('people')->limit(5)->get()
            ->filter(fn ($row) => $row->title && $row->people >= 3)
            ->map(fn ($row) => $this->idea('top_item', substr($row->path, 0, 100), '🔥 Popular', $row->title,
                "🔥 Popular in {$cityName} this week: {$row->title}. See it on 1CallFix.",
                "🔥 ఈ వారం {$cityName} లో ఎక్కువ మంది చూసినది: {$row->title}. 1CallFix లో చూడండి.",
                "#1CallFix {$tag}".$this->moduleTag($row->module), null, $row->path, $row->module))
            ->values()->all();
    }

    private function newShops(string $tag): array
    {
        return Store::query()->publiclyVisible()->notTestShop()->where('created_at', '>=', now()->subDays(21))
            ->with('franchise.city:id,slug')->latest()->limit(5)->get()
            ->map(function (Store $s) use ($tag) {
                $food = $s->isRestaurant();
                $city = $s->franchise?->city?->slug;
                $link = $city && $s->slug ? '/'.$city.($food ? '/food/' : '/shop/').$s->slug : null;

                return $this->idea('new_shop', (string) $s->id, $food ? '🍽️ New restaurant' : '🛍️ New shop', 'New: '.$s->name,
                    ($food ? '🍽️ Now on 1CallFix: ' : '🛍️ Now on 1CallFix: ').$s->name.'. Order from home.',
                    '1CallFix లో ఇప్పుడు '.$s->name.'. ఇంటి నుంచే ఆర్డర్ చేయండి.',
                    "#1CallFix {$tag}".($food ? ' #Food' : ' #Shop'), $s->cover_image ?: $s->logo, $link, $food ? 'food' : 'shop');
            })->all();
    }

    private function jobs(string $tag, string $cityName): array
    {
        return JobOpening::query()->open()->limit(5)->get()
            ->map(fn (JobOpening $j) => $this->idea('job', (string) $j->id, '💼 Job', 'Hiring: '.$j->title,
                "💼 We're hiring in {$cityName}: {$j->title}. Apply in 2 minutes.",
                "💼 {$cityName} లో ఉద్యోగం: {$j->title}. 2 నిమిషాల్లో అప్లై చేయండి.",
                "#1CallFix {$tag} #Jobs #Hiring", null, '/careers/'.$j->slug, null))
            ->all();
    }

    private function app(string $tag): array
    {
        return $this->idea('app', 'get-app', '📱 App', 'Get the 1CallFix app',
            '📱 Home services, parcels, food and shopping in one app. Download 1CallFix free.',
            '📱 హోమ్ సర్వీసులు, పార్సెల్, ఫుడ్, షాపింగ్ అన్నీ ఒకే యాప్‌లో. 1CallFix ఉచితంగా డౌన్‌లోడ్ చేసుకోండి.',
            "#1CallFix {$tag} #App", null, AppLinks::SHORT_PATH, null);
    }

    /** Banner / coupon module code → post module key. */
    private function module(?string $code): ?string
    {
        if (! $code) {
            return null;
        }

        return array_search($code, ModuleGate::CODES, true) ?: (isset(ModuleGate::CODES[$code]) ? $code : null);
    }
}
