<?php

namespace App\Services\Account;

use App\Models\Booking;
use App\Models\FieldWorker;
use App\Models\MarketplaceOrder;
use App\Models\ParcelOrder;
use App\Models\Provider;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ActivityLogger;
use App\Support\SiteText;
use Illuminate\Support\Facades\DB;

/**
 * Account deletion for Google Play (owner decisions 2026-11-05):
 * - Customers delete themselves, except while their wallet has money or a booking / order / parcel is still open.
 * - Partners (service partner, seller/restaurant, rider) only ask; an admin closes the account once dues and payouts
 *   are settled.
 * - Closing stops sign-in at once (status suspended). Personal details are erased 30 days later (a mistake can be
 *   undone by Help before then). Bookings, orders and bills stay, without the name, as tax law needs.
 */
final class AccountDeletionService
{
    public const ERASE_AFTER_DAYS = 30;

    /** Statuses after which a booking / order / parcel is finished. Anything else is still open. */
    private const FINISHED = ['completed', 'delivered', 'cancelled', 'rejected', 'returned', 'refunded'];

    public function isPartner(User $user): bool
    {
        return Provider::where('user_id', $user->id)->exists() || FieldWorker::where('user_id', $user->id)->exists();
    }

    /** Why this customer cannot delete right now, in their language. Empty = they can. @return list<string> */
    public function blockers(User $user): array
    {
        $reasons = [];
        $balance = (float) (Wallet::where('user_id', $user->id)->value('balance') ?? 0);
        if ($balance > 0) {
            $symbol = \App\Models\Setting::get('locale.currency_symbol', '₹');
            $reasons[] = SiteText::get('account.delete.block_wallet', ['amount' => $symbol.number_format($balance, 2)]);
        }
        $open = Booking::where('customer_id', $user->id)->whereNotIn('status', self::FINISHED)->exists()
            || ParcelOrder::where('customer_id', $user->id)->whereNotIn('status', self::FINISHED)->exists()
            || MarketplaceOrder::where('customer_id', $user->id)->whereNotIn('status', self::FINISHED)->exists();
        if ($open) {
            $reasons[] = SiteText::get('account.delete.block_open');
        }

        return $reasons;
    }

    /** Close now: sign-in stops, app sign-ins and notifications end. Erased after ERASE_AFTER_DAYS. */
    public function close(User $user, ?User $actor, string $reason): void
    {
        DB::transaction(function () use ($user, $actor, $reason) {
            $user->forceFill(['status' => 'suspended', 'closed_at' => now(), 'fcm_token' => null])->save();
            $user->tokens()->delete();
            ActivityLogger::logModel($actor ?? $user, $user, 'Account closed', ['reason' => $reason, 'erase_after_days' => self::ERASE_AFTER_DAYS]);
        });
    }

    /** A partner asks; an admin finishes it (Admin → Users & Partners → Account deletions). */
    public function request(User $user): void
    {
        if ($user->deletion_requested_at === null) {
            $user->forceFill(['deletion_requested_at' => now()])->save();
            ActivityLogger::logModel($user, $user, 'Account deletion requested');
        }
    }

    /** Undo a close before the details are erased (the person called Help). */
    public function reopen(User $user, User $actor): void
    {
        abort_if($user->erased_at !== null, 422, 'This account was already erased.');
        $user->forceFill(['status' => 'active', 'closed_at' => null, 'deletion_requested_at' => null])->save();
        ActivityLogger::logModel($actor, $user, 'Account reopened');
    }

    /** Erase personal details of accounts closed more than ERASE_AFTER_DAYS ago and still closed. Returns how many. */
    public function eraseDue(): int
    {
        $n = 0;
        User::query()->whereNotNull('closed_at')->whereNull('erased_at')->where('status', 'suspended')
            ->where('closed_at', '<=', now()->subDays(self::ERASE_AFTER_DAYS))
            ->each(function (User $user) use (&$n) {
                DB::transaction(function () use ($user) {
                    // Never delete address rows: bookings and parcels are removed with them (cascade). Blank the
                    // words and blur the pin to ~1 km instead; the orders stay for the tax records.
                    $user->addresses()->get()->each(fn ($a) => $a->forceFill([
                        'label' => 'Deleted', 'address_line' => 'Address removed', 'landmark' => null,
                        'lat' => $a->lat !== null ? round((float) $a->lat, 2) : null,
                        'lng' => $a->lng !== null ? round((float) $a->lng, 2) : null,
                    ])->save());
                    $user->tokens()->delete();
                    $user->forceFill([
                        'name' => 'Deleted user', 'phone' => 'deleted-'.$user->id, 'email' => null, 'password' => null,
                        'fcm_token' => null, 'profile_photo' => null, 'avatar_url' => null, 'erased_at' => now(),
                    ])->save();
                    ActivityLogger::logModel(null, $user, 'Account personal details erased');
                });
                $n++;
            });

        return $n;
    }
}
