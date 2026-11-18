<?php

namespace App\Services\RentalItems;

use App\Models\City;
use App\Models\RentalItem;
use App\Models\RentalItemContactView;
use App\Models\RentalItemReport;
use App\Models\User;
use App\Support\RentalItemSettings;
use App\Support\RentalKinds;
use Illuminate\Support\Facades\DB;

/**
 * Rent anything (owner decision 2026-11-18, OLX-style): the rules in one place.
 *
 * - Posting: a signed-in person with a verified mobile number; at most `items.max_live_per_person` items waiting or up
 *   at a time; nothing from the banned list; price inside the settings range; enough photos. The team approves.
 * - Customers: an item shows only where Rental and "Rent anything" are on for the item's own zone (CityModules), while
 *   approved, switched on, not expired and not hidden by reports.
 * - Reports: one per person per item; `items.reports_to_hide` open reports hide the item until the team dismisses
 *   (back up) or upholds them (sent back to the owner with the reason). Nothing is deleted automatically.
 * - Contact: call / WhatsApp the owner. No booking and no money between them, so no benefit or money rule applies.
 */
class RentalItemService
{
    public static function kindOn(RentalItem $item): bool
    {
        $item->loadMissing('franchise');

        return RentalKinds::active(RentalKinds::ITEMS, $item->scope());
    }

    public static function visible(RentalItem $item): bool
    {
        return $item->isLive() && self::kindOn($item);
    }

    /** Live items in this city whose own zone has "Rent anything" on (test owners hidden by the shop rule). */
    public static function publicIn(City $city)
    {
        $query = RentalItem::query()->where('status', 'approved')->where('is_active', true)->whereNull('hidden_for_reports_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('franchise', fn ($q) => $q->where('city_id', $city->id));
        \App\Support\Seo\CityModules::whereRentalKindOn($query, $city, RentalKinds::ITEMS);
        if (\App\Support\MarketplaceSettings::on('marketplace.hide_test_shops') && ! \App\Models\Store::isTestViewer(auth()->user())) {
            $query->whereDoesntHave('user', fn ($q) => $q->where('email', 'like', '%'.\App\Console\Commands\TestAccountsCommand::DOMAIN));
        }

        return $query;
    }

    /** Items of this person that count toward their limit (waiting for review or up). */
    public static function liveCount(User $user, ?int $exceptId = null): int
    {
        return RentalItem::where('user_id', $user->id)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->get()
            ->filter(fn (RentalItem $i) => $i->countsTowardLimit())->count();
    }

    /** Null when this person may post (another) item here, else a plain reason to show them. */
    public static function cannotPostReason(User $user, array $scope = [], ?int $exceptId = null): ?string
    {
        if ($user->phone === null || $user->phone === '' || $user->phone_verified_at === null) {
            return 'Please verify your mobile number first. It keeps everyone safe.';
        }
        if ($user->status !== 'active') {
            return 'Your account cannot post items right now. Please contact us.';
        }
        $max = (int) RentalItemSettings::get('items.max_live_per_person', $scope);
        if (self::liveCount($user, $exceptId) >= $max) {
            return "You can have {$max} items up at a time. Take one down to post another.";
        }

        return null;
    }

    /** The first banned word or phrase found in this text (whole words, any case), or null. */
    public static function bannedWordIn(string $text, array $scope = []): ?string
    {
        foreach (RentalItemSettings::listIn('items.banned', $scope) as $word) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/iu', $text)) {
                return $word;
            }
        }

        return null;
    }

    /**
     * Owner sends a draft (or a sent-back item) for review.
     *
     * @throws \RuntimeException with a reason to show the owner
     */
    public function submit(User $owner, RentalItem $item): RentalItem
    {
        if ($item->user_id !== $owner->id) {
            throw new \RuntimeException('This is not your item.');
        }
        if (! $item->isEditable()) {
            throw new \RuntimeException('This item is already with the team.');
        }
        $scope = $item->scope();
        if ($reason = self::cannotPostReason($owner, $scope, $item->id)) {
            throw new \RuntimeException($reason);
        }
        if ($word = self::bannedWordIn(implode(' ', [$item->name, $item->category, $item->about]), $scope)) {
            throw new \RuntimeException("Sorry, \"{$word}\" cannot be rented out here.");
        }
        $min = (int) RentalItemSettings::get('items.min_price', $scope);
        $max = (int) RentalItemSettings::get('items.max_price', $scope);
        if ($item->price === null || (float) $item->price < $min || (float) $item->price > $max) {
            throw new \RuntimeException("Please enter a price between ₹{$min} and ₹{$max}.");
        }
        if (! $item->cover_image || count((array) $item->photos) < (int) RentalItemSettings::get('items.min_photos', $scope)) {
            throw new \RuntimeException('Please add a cover photo and '.RentalItemSettings::get('items.min_photos', $scope).' more photo(s).');
        }
        if (! $item->zone_id) {
            throw new \RuntimeException('Please pick your area.');
        }

        $item->update(['status' => 'in_review', 'submitted_at' => now(), 'rejection_reason' => null]);

        return $item->fresh();
    }

    /** Team approves: live at once for the listing period. */
    public function approve(User $by, RentalItem $item): RentalItem
    {
        if ($item->status !== 'in_review') {
            throw new \RuntimeException('Only items waiting for review can be approved.');
        }
        $days = (int) RentalItemSettings::get('items.listing_days', $item->scope());
        $item->update([
            'status' => 'approved', 'approved_at' => now(), 'approved_by' => $by->id, 'is_active' => true,
            'expires_at' => now()->addDays($days), 'rejection_reason' => null,
        ]);

        return $item->fresh();
    }

    /** Team sends it back to the owner with a reason (editable again). */
    public function sendBack(User $by, RentalItem $item, string $reason): RentalItem
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('Please write what the owner should change.');
        }
        $item->update(['status' => 'rejected', 'rejection_reason' => mb_substr($reason, 0, 500), 'is_active' => false]);

        return $item->fresh();
    }

    /**
     * A customer reports an item. One report per person per item; enough open reports hide it.
     *
     * @throws \RuntimeException with a reason to show the customer
     */
    public function report(User $by, RentalItem $item, string $reason, ?string $note = null): RentalItemReport
    {
        if ($item->user_id === $by->id) {
            throw new \RuntimeException('This is your own item.');
        }
        if (! array_key_exists($reason, RentalItemReport::REASONS)) {
            throw new \RuntimeException('Please pick a reason.');
        }
        if (RentalItemReport::where('rental_item_id', $item->id)->where('user_id', $by->id)->exists()) {
            throw new \RuntimeException('You have already reported this item. Thank you, the team will check it.');
        }

        return DB::transaction(function () use ($by, $item, $reason, $note) {
            $report = RentalItemReport::create([
                'rental_item_id' => $item->id, 'user_id' => $by->id, 'reason' => $reason,
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
            ]);
            $open = RentalItemReport::where('rental_item_id', $item->id)->where('status', 'open')->count();
            $hide = $open >= (int) RentalItemSettings::get('items.reports_to_hide', $item->scope());
            $item->update(['open_reports' => $open] + ($hide && $item->hidden_for_reports_at === null ? ['hidden_for_reports_at' => now()] : []));

            return $report;
        });
    }

    /** Team checked the reports and the item is fine: reports closed, item back up. */
    public function dismissReports(User $by, RentalItem $item): RentalItem
    {
        DB::transaction(function () use ($by, $item) {
            RentalItemReport::where('rental_item_id', $item->id)->where('status', 'open')
                ->update(['status' => 'dismissed', 'handled_by' => $by->id, 'handled_at' => now()]);
            $item->update(['open_reports' => 0, 'hidden_for_reports_at' => null]);
        });

        return $item->fresh();
    }

    /** Team agrees with the reports: item sent back to the owner with the reason, reports closed. */
    public function upholdReports(User $by, RentalItem $item, string $reason): RentalItem
    {
        return DB::transaction(function () use ($by, $item, $reason) {
            $item = $this->sendBack($by, $item, $reason);
            RentalItemReport::where('rental_item_id', $item->id)->where('status', 'open')
                ->update(['status' => 'upheld', 'handled_by' => $by->id, 'handled_at' => now()]);
            $item->update(['open_reports' => 0, 'hidden_for_reports_at' => null]);

            return $item->fresh();
        });
    }

    /**
     * Areas a person can post in: zones of active franchises where Rental and "Rent anything" are on, with the franchise
     * loaded (its city_id names the city). Zone by zone, like the customer pages.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Zone>
     */
    public static function postingZones()
    {
        return \App\Models\Zone::query()->with('franchise')
            ->whereHas('franchise', fn ($q) => $q->where('status', 'active'))->orderBy('name')->get()
            ->filter(fn (\App\Models\Zone $z) => RentalKinds::active(RentalKinds::ITEMS, array_filter([
                'zone_id' => $z->id, 'franchise_id' => $z->franchise_id,
                'city_id' => $z->franchise?->city_id, 'country_id' => $z->franchise?->country_id,
            ])))->values();
    }

    /** Fields of an approved item that change at once; anything else sends it back for a quick check. */
    public const CHANGE_AT_ONCE = ['price', 'price_unit', 'deposit', 'can_deliver'];

    /**
     * Owner saves an item (new or existing). A draft or sent-back item just saves. An approved item keeps price changes
     * at once; a change to its name, kind, photos, description or area sends it back to the team (hidden meanwhile).
     *
     * @return array{0: RentalItem, 1: bool} the item, and whether it went back for review
     */
    public function save(User $owner, ?RentalItem $item, array $fields): array
    {
        if ($item !== null && $item->user_id !== $owner->id) {
            throw new \RuntimeException('This is not your item.');
        }
        if ($item?->status === 'in_review') {
            throw new \RuntimeException('We are checking this item. You can change it once we reply.');
        }
        $zone = \App\Models\Zone::with('franchise')->find($fields['zone_id'] ?? null);
        $fields['franchise_id'] = $zone?->franchise_id;
        $fields['title'] = RentalItem::makeTitle((string) $fields['name'], $zone?->name);

        return DB::transaction(function () use ($owner, $item, $fields) {
            if ($item === null) {
                if ($reason = self::cannotPostReason($owner, $this->scopeOf($fields))) {
                    throw new \RuntimeException($reason);
                }

                return [RentalItem::create($fields + ['user_id' => $owner->id, 'slug' => RentalItem::uniqueSlug($fields['title'])]), false];
            }
            if ($item->status === 'approved') {
                $reviewChange = false;
                foreach (array_diff_key($fields, array_flip(array_merge(self::CHANGE_AT_ONCE, ['franchise_id', 'title']))) as $field => $value) {
                    if (! self::same($item->getAttribute($field), $value)) {
                        $reviewChange = true;
                    }
                }
                if (! $reviewChange) {
                    $item->update(array_intersect_key($fields, array_flip(self::CHANGE_AT_ONCE)));

                    return [$item->fresh(), false];
                }
                // The public link stays the same (old links never break); it shows again once the team approves.
                $item->update(array_diff_key($fields, ['title' => 1]) + ['status' => 'in_review', 'submitted_at' => now()]);

                return [$item->fresh(), true];
            }
            if ($item->title !== $fields['title']) {
                $fields['slug'] = RentalItem::uniqueSlug($fields['title'], $item->id);
            }
            $item->update($fields);

            return [$item->fresh(), false];
        });
    }

    private function scopeOf(array $fields): array
    {
        $franchise = isset($fields['franchise_id']) ? \App\Models\Franchise::find($fields['franchise_id']) : null;

        return array_filter(['zone_id' => $fields['zone_id'] ?? null, 'franchise_id' => $franchise?->id, 'city_id' => $franchise?->city_id, 'country_id' => $franchise?->country_id]);
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return array_values((array) $a) === array_values((array) $b);
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return (string) $a === (string) $b;
    }

    /** Owner: "Not available now" / "Available again" on an approved item. */
    public function setAvailable(User $owner, RentalItem $item, bool $available): RentalItem
    {
        if ($item->user_id !== $owner->id || $item->status !== 'approved') {
            throw new \RuntimeException('Only your approved items can be switched on or off.');
        }
        $item->update(['is_active' => $available]);

        return $item->fresh();
    }

    /** Owner renews an approved item that ran out (or is about to): another listing period, counted from now. */
    public function renew(User $owner, RentalItem $item): RentalItem
    {
        if ($item->user_id !== $owner->id || $item->status !== 'approved') {
            throw new \RuntimeException('Only your approved items can be renewed.');
        }
        if ($item->isExpired() && ($reason = self::cannotPostReason($owner, $item->scope(), $item->id))) {
            throw new \RuntimeException($reason);
        }
        $item->update(['expires_at' => now()->addDays((int) RentalItemSettings::get('items.listing_days', $item->scope())), 'is_active' => true]);

        return $item->fresh();
    }

    /** May this customer see the owner's number now? 'ok' | 'off' | 'limit'. An item already seen stays visible. */
    public static function phoneAccess(User $customer, RentalItem $item): string
    {
        $scope = $item->scope();
        if (! RentalItemSettings::on('items.show_owner_phone', $scope)) {
            return 'off';
        }
        if (RentalItemContactView::where('customer_id', $customer->id)->where('rental_item_id', $item->id)->exists()) {
            return 'ok';
        }
        $seen = RentalItemContactView::where('customer_id', $customer->id)->where('created_at', '>=', now()->subDay())->count();

        return $seen >= (int) RentalItemSettings::get('items.phone_views_per_day', $scope) ? 'limit' : 'ok';
    }

    /** @throws \RuntimeException with a customer-facing reason */
    public function revealPhone(User $customer, RentalItem $item): string
    {
        if (! self::visible($item)) {
            throw new \RuntimeException('This item is not available right now.');
        }
        if ($item->user_id === $customer->id) {
            throw new \RuntimeException('This is your own item.');
        }
        $access = self::phoneAccess($customer, $item);
        if ($access !== 'ok') {
            throw new \RuntimeException($access === 'off' ? 'The owner\'s number is not shown here yet. Please contact us.' : 'You have seen many numbers today. Please try tomorrow.');
        }
        RentalItemContactView::firstOrCreate(['rental_item_id' => $item->id, 'customer_id' => $customer->id]);

        return (string) $item->user?->phone;
    }
}
