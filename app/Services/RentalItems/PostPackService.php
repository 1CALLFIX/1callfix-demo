<?php

namespace App\Services\RentalItems;

use App\Contracts\PaymentGateway;
use App\Models\City;
use App\Models\ListingFee;
use App\Models\RentalItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Homes\ListingFeeService;
use App\Services\WalletService;
use App\Support\RentalItemSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Paid packs for people's posts (owner 2026-11-19), rent and second-hand alike. Four packs, fixed here; every number is
 * the Super Admin's (Rental Settings → Posts · Paid packs) and a price of 0 switches that pack off:
 *
 *  - featured   ⭐ top of the list with a "Featured" tag for N days;
 *  - boost      🚀 Featured, plus a big card in the "Boosted" row at the top of the page (limited spots per city);
 *  - bulk_small 📦 N Featured posts: one used on the post it is bought from, the rest on any of the person's posts
 *  - bulk_large    within the bulk validity days.
 *
 * Money runs through the existing listing fee engine (listing_fees, listing_type "item"): online through the HQ gateway
 * (Razorpay; the webhook activates it once, for the exact amount) or one wallet debit. This is a charge, not a benefit,
 * so the online-only benefits rule is not involved, and no person moves money by hand, so it is not a manual money
 * action. No coupon or discount applies to a pack. The franchise's share is worked out when a pack is bought and kept
 * on the fee row; HQ holds the money. Posting itself stays free.
 */
class PostPackService
{
    public const FEATURED = 'featured';
    public const BOOST = 'boost';
    public const BULK_SMALL = 'bulk_small';
    public const BULK_LARGE = 'bulk_large';

    public const PACKS = [self::FEATURED, self::BOOST, self::BULK_SMALL, self::BULK_LARGE];

    /** listing_fees.listing_type for posts. */
    public const TYPE = 'item';

    public function __construct(private WalletService $wallet, private PaymentGateway $gateway)
    {
    }

    private static function setting(RentalItem $item, string $name): int
    {
        return max(0, (int) RentalItemSettings::get('packs.'.$name, $item->scope()));
    }

    public static function price(RentalItem $item, string $pack): int
    {
        return match ($pack) {
            self::FEATURED => self::setting($item, 'featured_fee'),
            self::BOOST => self::setting($item, 'boost_fee'),
            self::BULK_SMALL => self::setting($item, 'bulk_small_fee'),
            self::BULK_LARGE => self::setting($item, 'bulk_large_fee'),
            default => 0,
        };
    }

    public static function days(RentalItem $item, string $pack): int
    {
        return max(1, $pack === self::BOOST ? self::setting($item, 'boost_days') : self::setting($item, 'featured_days'));
    }

    public static function count(RentalItem $item, string $pack): int
    {
        return match ($pack) {
            self::BULK_SMALL => max(2, self::setting($item, 'bulk_small_count')),
            self::BULK_LARGE => max(2, self::setting($item, 'bulk_large_count')),
            default => 1,
        };
    }

    public static function isBulk(string $pack): bool
    {
        return in_array($pack, [self::BULK_SMALL, self::BULK_LARGE], true);
    }

    /** Any pack is on for this post's area. */
    public static function anyOn(RentalItem $item): bool
    {
        foreach (self::PACKS as $pack) {
            if (self::price($item, $pack) > 0) {
                return true;
            }
        }

        return false;
    }

    /** Boost spots taken right now in this post's city, for its kind of post (the post itself not counted). */
    private static function boostedNearby(RentalItem $item)
    {
        $item->loadMissing('franchise');

        return RentalItem::where('deal', $item->deal)->whereKeyNot($item->id)->where('boosted_until', '>', now())
            ->whereHas('franchise', fn ($q) => $q->where('city_id', $item->franchise?->city_id));
    }

    public static function boostSpotsLeft(RentalItem $item): int
    {
        if ($item->isBoostedNow()) {
            return 1; // adding days to a running Boost takes no new spot
        }

        return max(0, self::setting($item, 'boost_spots') - self::boostedNearby($item)->count());
    }

    public static function nextBoostSpotAt(RentalItem $item): ?CarbonInterface
    {
        return self::boostedNearby($item)->orderBy('boosted_until')->first()?->boosted_until;
    }

    /**
     * The packs to show for this post: only those switched on. Each: key, price, normal (crossed-out, only when the
     * Super Admin set a higher real price), days, count, saving % (bulk, against Featured bought one by one), and why
     * it cannot be picked right now (null = it can).
     *
     * @return list<array{key: string, price: int, normal: ?int, days: int, count: int, saving: ?int, blocked: ?string}>
     */
    public static function offer(RentalItem $item): array
    {
        $out = [];
        $featured = self::price($item, self::FEATURED);
        foreach (self::PACKS as $pack) {
            $price = self::price($item, $pack);
            if ($price <= 0) {
                continue;
            }
            $normal = match ($pack) {
                self::FEATURED => self::setting($item, 'featured_normal_fee'),
                self::BOOST => self::setting($item, 'boost_normal_fee'),
                default => 0,
            };
            $count = self::count($item, $pack);
            $saving = self::isBulk($pack) && $featured > 0 && $featured * $count > $price ? (int) floor(100 - $price * 100 / ($featured * $count)) : null;
            $blocked = null;
            if ($pack === self::BOOST && self::boostSpotsLeft($item) === 0) {
                $next = self::nextBoostSpotAt($item);
                $blocked = 'All Boost spots in your city are taken'.($next ? ' until '.$next->format('j M, g:i A') : '').'. Featured works now.';
            }
            $out[] = [
                'key' => $pack, 'price' => $price, 'normal' => $normal > $price ? $normal : null, 'days' => self::days($item, $pack),
                'count' => $count, 'saving' => $saving ?: null, 'blocked' => $blocked,
            ];
        }

        return $out;
    }

    /** Bulk packs with Featured posts left to use, oldest first. */
    private static function openBulk(User $user)
    {
        return ListingFee::where('user_id', $user->id)->where('listing_type', self::TYPE)->whereIn('kind', [self::BULK_SMALL, self::BULK_LARGE])
            ->where('status', 'paid')->where('credits_until', '>', now())->whereColumn('credits_used', '<', 'credits')
            ->orderBy('credits_until')->orderBy('id');
    }

    /** Featured posts this person still has from bulk packs. */
    public static function creditsLeft(User $user): int
    {
        return (int) self::openBulk($user)->get()->sum(fn (ListingFee $f) => $f->credits - $f->credits_used);
    }

    public static function creditsUntil(User $user): ?CarbonInterface
    {
        return self::openBulk($user)->first()?->credits_until;
    }

    public function onlineAvailable(RentalItem $item): bool
    {
        return Setting::get('payment.online_enabled', '1', $item->scope()) === '1' && $this->gateway->isConfigured();
    }

    /** @throws \RuntimeException with a reason to show the person */
    private function check(User $owner, RentalItem $item, string $pack): int
    {
        if (! in_array($pack, self::PACKS, true)) {
            throw new \RuntimeException('Please pick a pack.');
        }
        if ($item->user_id !== $owner->id) {
            throw new \RuntimeException('This is not your post.');
        }
        if (! $item->isLive()) {
            throw new \RuntimeException('Only a post that customers can see now can get a pack.');
        }
        $price = self::price($item, $pack);
        if ($price <= 0) {
            throw new \RuntimeException('This pack is not on in your area.');
        }
        if ($pack === self::BOOST && self::boostSpotsLeft($item) === 0) {
            throw new \RuntimeException('All Boost spots in your city are taken right now. Featured works now.');
        }

        return $price;
    }

    private function newFee(User $owner, RentalItem $item, string $pack, int $price, string $method, array $extra = []): ListingFee
    {
        $share = (int) RentalItemSettings::get('packs.franchise_share_percent', $item->scope());

        return ListingFee::create($extra + [
            'listing_type' => self::TYPE, 'listing_id' => $item->id, 'user_id' => $owner->id, 'franchise_id' => $item->franchise_id,
            'kind' => $pack, 'amount' => $price, 'franchise_share' => round($price * max(0, min(100, $share)) / 100, 2),
            'days' => self::days($item, $pack), 'credits' => self::isBulk($pack) ? self::count($item, $pack) : null,
            'method' => $method, 'status' => 'pending',
        ]);
    }

    /** Pay from the wallet: one debit, then the pack starts. @throws \RuntimeException */
    public function payFromWallet(User $owner, RentalItem $item, string $pack): ListingFee
    {
        $price = $this->check($owner, $item, $pack);
        if ($this->wallet->isFrozen($owner)) {
            throw new \RuntimeException('Your wallet is on hold. Please pay online or contact us.');
        }
        if ($this->wallet->balance($owner) < $price) {
            throw new \RuntimeException('Your wallet has less than ₹'.number_format($price).'. Pay online, or add money first.');
        }

        return DB::transaction(function () use ($owner, $item, $pack, $price) {
            $fee = $this->newFee($owner, $item, $pack, $price, 'wallet');
            $this->wallet->debit($owner, (float) $price, 'Pack for your post: '.self::label($pack), 'listingfee:'.$fee->id);
            app(ListingFeeService::class)->markPaid($fee);

            return $fee->fresh();
        });
    }

    /** Start an online payment: the Razorpay order for the checkout. The webhook starts the pack. @throws \RuntimeException */
    public function startOnline(User $owner, RentalItem $item, string $pack): array
    {
        $price = $this->check($owner, $item, $pack);
        if (! $this->onlineAvailable($item)) {
            throw new \RuntimeException('Online payment is not available right now. Try your wallet.');
        }
        $order = $this->gateway->createRawOrder((float) $price, 'pack-'.Str::random(8), ['user_id' => $owner->id, 'purpose' => 'listing_fee', 'kind' => $pack]);
        $this->newFee($owner, $item, $pack, $price, 'online', ['gateway' => $this->gateway->identifier(), 'gateway_order_id' => $order['razorpay_order_id']]);

        return $order;
    }

    /** Feature a post with one Featured left from a bulk pack (no payment). @throws \RuntimeException */
    public function useCredit(User $owner, RentalItem $item): ListingFee
    {
        if ($item->user_id !== $owner->id) {
            throw new \RuntimeException('This is not your post.');
        }
        if (! $item->isLive()) {
            throw new \RuntimeException('Only a post that customers can see now can be featured.');
        }

        return DB::transaction(function () use ($owner, $item) {
            $bulk = self::openBulk($owner)->lockForUpdate()->first();
            if (! $bulk) {
                throw new \RuntimeException('You have no Featured posts left in a pack.');
            }
            $bulk->increment('credits_used');
            $fee = ListingFee::create([
                'listing_type' => self::TYPE, 'listing_id' => $item->id, 'user_id' => $owner->id, 'franchise_id' => $item->franchise_id,
                'kind' => self::FEATURED, 'amount' => 0, 'franchise_share' => 0, 'days' => self::days($item, self::FEATURED),
                'method' => 'credit', 'status' => 'pending',
            ]);
            app(ListingFeeService::class)->markPaid($fee);

            return $fee->fresh();
        });
    }

    /** Called once by ListingFeeService::markPaid, right after the fee turns paid. */
    public function activate(ListingFee $fee): void
    {
        $item = RentalItem::find($fee->listing_id);
        if (self::isBulk($fee->kind)) {
            $fee->update(['credits_used' => 1, 'credits_until' => now()->addDays(max(1, (int) RentalItemSettings::get('packs.bulk_valid_days', $item?->scope() ?? [])))]);
        }
        if (! $item) {
            return;
        }
        $after = fn ($at) => $at && $at->isFuture() ? $at->copy() : now();
        $changes = [];
        if ($fee->kind === self::BOOST) {
            $changes['boosted_until'] = $after($item->boosted_until)->addDays($fee->days);
        }
        $featuredUntil = $item->is_featured && $item->featured_until === null ? null : $after($item->featured_until)->addDays($fee->days);
        if ($featuredUntil !== null && isset($changes['boosted_until']) && $featuredUntil->lt($changes['boosted_until'])) {
            $featuredUntil = $changes['boosted_until']->copy();
        }
        $item->update($changes + ['is_featured' => true, 'featured_until' => $featuredUntil]);
        ActivityLogger::logModel($fee->user, $item, 'Pack started: '.self::label($fee->kind).($fee->method === 'credit' ? ' (from a bulk pack)' : ' ₹'.number_format((float) $fee->amount).' ('.$fee->method.')'));
    }

    public static function label(string $pack): string
    {
        return match ($pack) {
            self::FEATURED => 'Featured',
            self::BOOST => 'Boost',
            self::BULK_SMALL, self::BULK_LARGE => 'Featured bulk pack',
            default => $pack,
        };
    }

    /** The "Boosted" row on a city's rent / second-hand page: live Boosted posts, as many as the city's spots. */
    public static function boostedIn(City $city, string $deal)
    {
        $spots = max(1, (int) RentalItemSettings::get('packs.boost_spots', \App\Livewire\Customer\RentItems\Home::cityScope($city)));

        return RentalItemService::publicIn($city, $deal)->where('boosted_until', '>', now())
            ->with(['zone', 'franchise'])->orderBy('boosted_until')->limit($spots)->get();
    }

    /** Daily (homes:tidy): Featured and Boost that ran out end. */
    public static function endLapsed(): int
    {
        $featured = RentalItem::where('is_featured', true)->whereNotNull('featured_until')->where('featured_until', '<=', now())
            ->update(['is_featured' => false, 'featured_until' => null]);
        $boosted = RentalItem::whereNotNull('boosted_until')->where('boosted_until', '<=', now())->update(['boosted_until' => null]);

        return $featured + $boosted;
    }
}
