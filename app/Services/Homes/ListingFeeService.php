<?php

namespace App\Services\Homes;

use App\Contracts\PaymentGateway;
use App\Models\HouseListing;
use App\Models\ListingFee;
use App\Models\Setting;
use App\Models\User;
use App\Models\VehicleListing;
use App\Services\ActivityLogger;
use App\Services\WalletService;
use App\Support\HomeSettings;
use App\Support\VehicleListingSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Listing fee for property (owner 2026-11-17) and vehicles for rent (2026-11-18): one engine, each listing type with its
 * own settings (homes.* / vehicles.*). The owner pays to show a listing ("listing") or to show it first ("featured"),
 * either online through the HQ gateway (Razorpay) or from their wallet. The fee is set per city / zone in Rental
 * Settings; 0 means free and nothing changes. This is a charge, not a benefit, so the online-only benefits rule is not
 * involved; it is not a manual money action either (no person moves, waives or adjusts it).
 *
 * - Paying for "listing" sets the live period (expires_at) from the later of now and the current end, so renewing early
 *   loses no days. Paying for "featured" sets is_featured with featured_until, and `homes:tidy` ends it.
 * - Wallet: one ledger debit, idempotent by a per-fee reference. Online: a gateway order; the webhook (see
 *   RazorpayWebhookHandler) calls handleCaptured(), which checks the amount and activates once.
 * - The franchise never holds the money: it goes to the HQ gateway or the owner's wallet debit, like every customer payment.
 */
class ListingFeeService
{
    /** listing_fees.listing_type => [model, settings class, settings prefix] */
    public const TYPES = [
        'house' => [HouseListing::class, HomeSettings::class, 'homes'],
        'vehicle' => [VehicleListing::class, VehicleListingSettings::class, 'vehicles'],
    ];

    public function __construct(private WalletService $wallet, private PaymentGateway $gateway)
    {
    }

    public static function typeOf(HouseListing|VehicleListing $listing): string
    {
        return $listing instanceof VehicleListing ? 'vehicle' : 'house';
    }

    private static function setting(HouseListing|VehicleListing $listing, string $name): ?string
    {
        [, $settings, $prefix] = self::TYPES[self::typeOf($listing)];

        return $settings::get($prefix.'.'.$name, $listing->scope());
    }

    public static function amount(HouseListing|VehicleListing $listing, string $kind): int
    {
        return max(0, (int) self::setting($listing, $kind === 'featured' ? 'featured_fee' : 'listing_fee'));
    }

    public static function days(HouseListing|VehicleListing $listing, string $kind): int
    {
        return max(1, (int) self::setting($listing, $kind === 'featured' ? 'featured_days' : 'listing_days'));
    }

    /** A fee applies to this listing's area: approval alone does not put it live. */
    public static function required(HouseListing|VehicleListing $listing): bool
    {
        return self::amount($listing, 'listing') > 0;
    }

    /** The listing has a paid (or free) period running now. */
    public static function hasValidPeriod(HouseListing|VehicleListing $listing): bool
    {
        return $listing->expires_at !== null && $listing->expires_at->isFuture();
    }

    public function onlineAvailable(HouseListing|VehicleListing $listing): bool
    {
        return Setting::get('payment.online_enabled', '1', $listing->scope()) === '1' && $this->gateway->isConfigured();
    }

    private static function noun(HouseListing|VehicleListing $listing): string
    {
        return self::typeOf($listing) === 'vehicle' ? 'vehicle' : 'property';
    }

    /** @throws \RuntimeException with an owner-facing reason */
    private function check(User $owner, HouseListing|VehicleListing $listing, string $kind): int
    {
        if (! in_array($kind, ['listing', 'featured'], true)) {
            throw new \RuntimeException('Choose what to pay for.');
        }
        $listing->loadMissing('provider');
        if ($listing->provider?->user_id !== $owner->id) {
            throw new \RuntimeException('This is not your '.self::noun($listing).'.');
        }
        if ($listing->status !== 'approved') {
            throw new \RuntimeException('You can pay once the team has approved the '.self::noun($listing).'.');
        }
        $amount = self::amount($listing, $kind);
        if ($amount <= 0) {
            throw new \RuntimeException($kind === 'featured' ? 'Featuring is not on for your area.' : 'There is no fee to pay.');
        }
        if ($kind === 'featured' && ! $listing->isLive()) {
            throw new \RuntimeException('Only a live '.self::noun($listing).' can be featured. Pay to show it first.');
        }

        return $amount;
    }

    /** Pay from the owner's wallet: one debit, then activate. @throws \RuntimeException */
    public function payFromWallet(User $owner, HouseListing|VehicleListing $listing, string $kind): ListingFee
    {
        $amount = $this->check($owner, $listing, $kind);
        if ($this->wallet->isFrozen($owner)) {
            throw new \RuntimeException('Your wallet is on hold. Please pay online or contact us.');
        }
        if ($this->wallet->balance($owner) < $amount) {
            throw new \RuntimeException('Your wallet has less than ₹'.number_format($amount).'. Add money first, or pay online.');
        }

        return DB::transaction(function () use ($owner, $listing, $kind, $amount) {
            $fee = ListingFee::create([
                'listing_type' => self::typeOf($listing), 'listing_id' => $listing->id, 'user_id' => $owner->id, 'franchise_id' => $listing->franchise_id,
                'kind' => $kind, 'amount' => $amount, 'days' => self::days($listing, $kind), 'method' => 'wallet', 'status' => 'pending',
            ]);
            $this->wallet->debit($owner, (float) $amount, ($kind === 'featured' ? 'Feature a ' : 'Listing fee: ').self::noun($listing), 'listingfee:'.$fee->id);
            $this->markPaid($fee);

            return $fee->fresh();
        });
    }

    /** Start an online payment: returns the Razorpay order for the browser checkout. @throws \RuntimeException */
    public function startOnline(User $owner, HouseListing|VehicleListing $listing, string $kind): array
    {
        $amount = $this->check($owner, $listing, $kind);
        if (! $this->onlineAvailable($listing)) {
            throw new \RuntimeException('Online payment is not available right now. Try your wallet.');
        }
        $order = $this->gateway->createRawOrder((float) $amount, 'listing-'.Str::random(8), ['user_id' => $owner->id, 'purpose' => 'listing_fee', 'kind' => $kind]);
        ListingFee::create([
            'listing_type' => self::typeOf($listing), 'listing_id' => $listing->id, 'user_id' => $owner->id, 'franchise_id' => $listing->franchise_id,
            'kind' => $kind, 'amount' => $amount, 'days' => self::days($listing, $kind), 'method' => 'online', 'status' => 'pending',
            'gateway' => $this->gateway->identifier(), 'gateway_order_id' => $order['razorpay_order_id'],
        ]);

        return $order;
    }

    /**
     * Webhook: a captured gateway payment. Returns null when the order is not a listing fee (the caller carries on with
     * its other purposes), otherwise an outcome. The captured amount must equal the fee, as for every payment.
     *
     * @return array{outcome: string, fee: ListingFee}|null
     */
    public function handleCaptured(string $orderId, ?string $paymentId, mixed $capturedPaise): ?array
    {
        $fee = ListingFee::where('gateway_order_id', $orderId)->first();
        if (! $fee) {
            return null;
        }

        return DB::transaction(function () use ($fee, $paymentId, $capturedPaise) {
            $fee = ListingFee::whereKey($fee->id)->lockForUpdate()->first();
            if ($fee->status === 'paid') {
                return ['outcome' => 'already_processed', 'fee' => $fee];
            }
            $expected = (int) round((float) $fee->amount * 100);
            $paise = is_int($capturedPaise) ? $capturedPaise : (is_string($capturedPaise) && ctype_digit($capturedPaise) ? (int) $capturedPaise : null);
            if ($paise !== $expected) {
                return ['outcome' => 'amount_mismatch', 'fee' => $fee];
            }
            $fee->update(['gateway_payment_id' => $paymentId]);
            $this->markPaid($fee);

            return ['outcome' => 'captured', 'fee' => $fee->fresh()];
        });
    }

    /** Mark paid and activate, once (a second call does nothing). */
    public function markPaid(ListingFee $fee): void
    {
        $fee = ListingFee::whereKey($fee->id)->lockForUpdate()->first();
        if ($fee->status === 'paid') {
            return;
        }
        $fee->update(['status' => 'paid', 'paid_at' => now()]);

        $model = self::TYPES[$fee->listing_type][0] ?? HouseListing::class;
        $listing = $model::find($fee->listing_id);
        if (! $listing) {
            return;
        }
        if ($fee->kind === 'featured') {
            $from = $listing->featured_until && $listing->featured_until->isFuture() ? $listing->featured_until : now();
            $listing->update(['is_featured' => true, 'featured_until' => $from->copy()->addDays($fee->days)]);
        } else {
            $from = $listing->expires_at && $listing->expires_at->isFuture() ? $listing->expires_at : now();
            $listing->update(['is_active' => true, 'expires_at' => $from->copy()->addDays($fee->days)]);
        }
        ActivityLogger::logModel($fee->user, $listing, 'Listing fee paid: '.$fee->kind.' ₹'.number_format((float) $fee->amount).' ('.$fee->method.')');
    }

    /** Paid features that have run out go back to the normal order (every listing type). Called by homes:tidy. */
    public static function endLapsedFeatures(): int
    {
        $ended = 0;
        foreach (self::TYPES as [$model]) {
            $ended += $model::where('is_featured', true)->whereNotNull('featured_until')->where('featured_until', '<=', now())
                ->update(['is_featured' => false, 'featured_until' => null]);
        }

        return $ended;
    }
}
