<?php

namespace App\Services\Homes;

use App\Models\City;
use App\Models\HouseContactView;
use App\Models\HouseListing;
use App\Models\HouseVisit;
use App\Models\User;
use App\Notifications\HouseVisitNotification;
use App\Services\ActivityLogger;
use App\Services\TimezoneResolver;
use App\Support\HomeSettings;
use App\Support\RentalKinds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Property for rent (owner 2026-11-14) — who sees what, visit requests and the owner's number. No money moves here.
 *
 * - A listing shows where Rental is on and "Property for rent" is on (Rental Settings) for its area, approved, switched
 *   on, not rented, not expired.
 * - Visits: a signed-in customer picks a day (today up to Rental Settings' days ahead) and a visit time; at most one
 *   open request per home and a daily limit. The owner accepts (optionally with a note, e.g. "come at 5 PM") or says
 *   no with a reason. The exact address and the owner's number reach the customer once accepted.
 * - The owner's number: only signed-in customers, only when Rental Settings allows it, a daily limit of homes.
 */
class HouseListingService
{
    public static function scope(HouseListing $listing): array
    {
        $listing->loadMissing('franchise');

        return $listing->scope();
    }

    public static function tz(HouseListing $listing): string
    {
        return app(TimezoneResolver::class)->timezoneFor($listing->franchise);
    }

    public static function kindOn(HouseListing $listing): bool
    {
        return RentalKinds::active(RentalKinds::PROPERTIES, self::scope($listing));
    }

    public static function visible(HouseListing $listing): bool
    {
        return $listing->isLive() && self::kindOn($listing);
    }

    /** Live listings in this city (test owners hidden by the shop rule, like halls). */
    public static function publicIn(City $city)
    {
        $query = HouseListing::query()->where('status', 'approved')->where('is_active', true)->whereNull('rented_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('franchise', fn ($q) => $q->where('city_id', $city->id));
        if (\App\Support\MarketplaceSettings::on('marketplace.hide_test_shops') && ! \App\Models\Store::isTestViewer(auth()->user())) {
            $query->whereDoesntHave('provider.user', fn ($q) => $q->where('email', 'like', '%'.\App\Console\Commands\TestAccountsCommand::DOMAIN));
        }

        return $query;
    }

    /** Days a customer may ask to visit: today .. today + Rental Settings' days ahead (local dates). */
    public static function visitWindow(HouseListing $listing): array
    {
        $today = now(self::tz($listing))->startOfDay();

        return [$today->toDateString(), $today->copy()->addDays((int) HomeSettings::get('homes.visit_days_ahead', self::scope($listing)))->toDateString()];
    }

    /** @throws \RuntimeException with a customer-facing reason */
    public function requestVisit(User $customer, HouseListing $listing, string $date, string $slot, string $name, string $phone, ?string $tenantType = null, ?string $message = null): HouseVisit
    {
        $scope = self::scope($listing);
        if (! self::visible($listing)) {
            throw new \RuntimeException('This property is not taking visits right now.');
        }
        if ($listing->provider?->user_id === $customer->id) {
            throw new \RuntimeException('This is your own property.');
        }
        if (! in_array($slot, HomeSettings::listIn('homes.visit_slots', $scope), true)) {
            throw new \RuntimeException('Choose a visit time.');
        }
        [$first, $last] = self::visitWindow($listing);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $first || $date > $last) {
            throw new \RuntimeException('Choose a day between '.Carbon::parse($first)->format('j M').' and '.Carbon::parse($last)->format('j M').'.');
        }

        return DB::transaction(function () use ($customer, $listing, $date, $slot, $name, $phone, $tenantType, $message, $scope) {
            User::whereKey($customer->id)->lockForUpdate()->first();
            if (HouseVisit::where('customer_id', $customer->id)->where('house_listing_id', $listing->id)->whereIn('status', HouseVisit::OPEN)->exists()) {
                throw new \RuntimeException('You already asked to see this property. Open My property visits.');
            }
            $today = HouseVisit::where('customer_id', $customer->id)->where('created_at', '>=', now()->subDay())->count();
            if ($today >= (int) HomeSettings::get('homes.visits_per_day', $scope)) {
                throw new \RuntimeException('You have asked for many visits today. Please try again tomorrow.');
            }

            $visit = HouseVisit::create([
                'code' => HouseVisit::newCode(),
                'house_listing_id' => $listing->id,
                'customer_id' => $customer->id,
                'franchise_id' => $listing->franchise_id,
                'zone_id' => $listing->zone_id,
                'visit_date' => $date,
                'slot' => $slot,
                'customer_name' => mb_substr(trim($name), 0, 120),
                'customer_phone' => mb_substr(trim($phone), 0, 20),
                'tenant_type' => filled($tenantType) ? mb_substr(trim($tenantType), 0, 60) : null,
                'message' => filled($message) ? mb_substr(trim($message), 0, 500) : null,
                'status' => 'requested',
            ]);
            ActivityLogger::logModel($customer, $visit, 'Home visit requested');
            DB::afterCommit(fn () => $this->notifyOwner($visit, 'owner_new'));

            return $visit;
        });
    }

    /** @throws \RuntimeException */
    public function accept(HouseVisit $visit, User $owner, ?string $note = null): HouseVisit
    {
        $this->assertOwner($visit, $owner);
        if ($visit->status !== 'requested') {
            throw new \RuntimeException('This request was already answered.');
        }
        $visit->update(['status' => 'accepted', 'accepted_at' => now(), 'owner_note' => filled($note) ? mb_substr(trim($note), 0, 300) : null]);
        ActivityLogger::logModel($owner, $visit, 'Home visit accepted (owner)');
        $this->notifyCustomer($visit, 'accepted');

        return $visit;
    }

    /** @throws \RuntimeException */
    public function decline(HouseVisit $visit, User $owner, string $reason): HouseVisit
    {
        $this->assertOwner($visit, $owner);
        if (! in_array($visit->status, HouseVisit::OPEN, true)) {
            throw new \RuntimeException('This request was already answered.');
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new \RuntimeException('Tell the customer why.');
        }
        $visit->update(['status' => 'declined', 'declined_at' => now(), 'owner_note' => mb_substr(trim($reason), 0, 300)]);
        ActivityLogger::logModel($owner, $visit, 'Home visit declined (owner)', ['reason' => trim($reason)]);
        $this->notifyCustomer($visit, 'declined');

        return $visit;
    }

    /** @throws \RuntimeException */
    public function cancel(HouseVisit $visit, User $customer): HouseVisit
    {
        if ($visit->customer_id !== $customer->id) {
            abort(404);
        }
        if (! in_array($visit->status, HouseVisit::OPEN, true)) {
            throw new \RuntimeException('This visit cannot be cancelled now.');
        }
        $visit->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        ActivityLogger::logModel($customer, $visit, 'Home visit cancelled (customer)');
        $this->notifyOwner($visit, 'owner_cancelled');

        return $visit;
    }

    /** May this customer see the owner's number now? 'ok' | 'off' | 'limit'. A home already seen stays visible. */
    public static function phoneAccess(User $customer, HouseListing $listing): string
    {
        $scope = self::scope($listing);
        if (! HomeSettings::on('homes.show_owner_phone', $scope)) {
            return 'off';
        }
        if (HouseContactView::where('customer_id', $customer->id)->where('house_listing_id', $listing->id)->exists()) {
            return 'ok';
        }
        $seen = HouseContactView::where('customer_id', $customer->id)->where('created_at', '>=', now()->subDay())->count();

        return $seen >= (int) HomeSettings::get('homes.phone_views_per_day', $scope) ? 'limit' : 'ok';
    }

    /** @throws \RuntimeException */
    public function revealPhone(User $customer, HouseListing $listing): string
    {
        if (! self::visible($listing)) {
            throw new \RuntimeException('This property is not available right now.');
        }
        $access = self::phoneAccess($customer, $listing);
        if ($access !== 'ok') {
            throw new \RuntimeException($access === 'off' ? 'Ask for a visit; you get the number once the owner accepts.' : 'You have seen many numbers today. Ask for a visit instead, or try tomorrow.');
        }
        HouseContactView::firstOrCreate(['house_listing_id' => $listing->id, 'customer_id' => $customer->id]);

        return (string) ($listing->contact_phone ?: $listing->provider?->user?->phone);
    }

    /**
     * Daily tidy (scheduled): listings past their time are hidden until the owner taps "Still available"; visit
     * requests not answered by the visit day are closed as "No reply in time".
     *
     * @return array{expired_requests: int}
     */
    public function tidy(): array
    {
        $done = 0;
        HouseVisit::with('listing.franchise')->where('status', 'requested')->where('visit_date', '<', now()->subDay()->toDateString())
            ->orderBy('id')->limit(500)->get()->each(function (HouseVisit $v) use (&$done) {
                $v->update(['status' => 'expired']);
                $done++;
            });

        return ['expired_requests' => $done];
    }

    private function assertOwner(HouseVisit $visit, User $owner): void
    {
        abort_unless($visit->listing?->provider?->user_id === $owner->id, 404);
    }

    private function channels(HouseVisit $visit): array
    {
        return \App\Notifications\Support\ChannelResolver::resolve(array_filter(['zone_id' => $visit->zone_id, 'franchise_id' => $visit->franchise_id]));
    }

    private function notifyCustomer(HouseVisit $visit, string $what): void
    {
        if ($visit->customer) {
            rescue(fn () => $visit->customer->notify(new HouseVisitNotification($what, $visit, $this->channels($visit))));
        }
    }

    private function notifyOwner(HouseVisit $visit, string $what): void
    {
        $owner = $visit->listing?->provider?->user;
        if ($owner) {
            rescue(fn () => $owner->notify(new HouseVisitNotification($what, $visit, $this->channels($visit))));
        }
    }
}
