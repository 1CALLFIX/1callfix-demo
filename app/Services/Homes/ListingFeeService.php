<?php

namespace App\Services\Homes;

use App\Contracts\PaymentGateway;
use App\Models\HouseListing;
use App\Models\ListingFee;
use App\Models\Setting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\WalletService;
use App\Support\HomeSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Listing fee for property (owner 2026-11-17). The owner pays to show a property ("listing") or to show it first
 * ("featured"), either online through the HQ gateway (Razorpay) or from their wallet. The fee is set per city / zone in
 * Rental Settings; 0 means free and nothing changes. This is a charge, not a benefit, so the online-only benefits rule is
 * not involved; it is not a manual money action either (no person moves, waives or adjusts it).
 *
 * - Paying for "listing" sets the live period (expires_at) from the later of now and the current end, so renewing early
 *   loses no days. Paying for "featured" sets is_featured with featured_until, and `homes:tidy` ends it.
 * - Wallet: one ledger debit, idempotent by a per-fee reference. Online: a gateway order; the webhook (see
 *   RazorpayWebhookHandler) calls handleCaptured(), which checks the amount and activates once.
 * - The franchise never holds the money: it goes to the HQ gateway or the owner's wallet debit, like every customer payment.
 */
class ListingFeeService
{
    public const TYPE = 'house';

    public function __construct(private WalletService $wallet, private PaymentGateway $gateway)
    {
    }

    public static function amount(HouseListing $home, string $kind): int
    {
        $key = $kind === 'featured' ? 'homes.featured_fee' : 'homes.listing_fee';

        return max(0, (int) HomeSettings::get($key, $home->scope()));
    }

    public static function days(HouseListing $home, string $kind): int
    {
        $key = $kind === 'featured' ? 'homes.featured_days' : 'homes.listing_days';

        return max(1, (int) HomeSettings::get($key, $home->scope()));
    }

    /** A fee applies to this listing's area: approval alone does not put it live. */
    public static function required(HouseListing $home): bool
    {
        return self::amount($home, 'listing') > 0;
    }

    /** Approved, owner has paid before or it is free: the "Pay to show" step does not block it. */
    public static function hasValidPeriod(HouseListing $home): bool
    {
        return $home->expires_at !== null && $home->expires_at->isFuture();
    }

    public function onlineAvailable(HouseListing $home): bool
    {
        return Setting::get('payment.online_enabled', '1', $home->scope()) === '1' && $this->gateway->isConfigured();
    }

    /** @throws \RuntimeException with an owner-facing reason */
    private function check(User $owner, HouseListing $home, string $kind): int
    {
        if (! in_array($kind, ['listing', 'featured'], true)) {
            throw new \RuntimeException('Choose what to pay for.');
        }
        $home->loadMissing('provider');
        if ($home->provider?->user_id !== $owner->id) {
            throw new \RuntimeException('This is not your property.');
        }
        if ($home->status !== 'approved') {
            throw new \RuntimeException('You can pay once the team has approved the property.');
        }
        $amount = self::amount($home, $kind);
        if ($amount <= 0) {
            throw new \RuntimeException($kind === 'featured' ? 'Featuring is not on for your area.' : 'There is no fee to pay.');
        }
        if ($kind === 'featured' && ! $home->isLive()) {
            throw new \RuntimeException('Only a live property can be featured. Pay to show it first.');
        }

        return $amount;
    }

    /** Pay from the owner's wallet: one debit, then activate. @throws \RuntimeException */
    public function payFromWallet(User $owner, HouseListing $home, string $kind): ListingFee
    {
        $amount = $this->check($owner, $home, $kind);
        if ($this->wallet->isFrozen($owner)) {
            throw new \RuntimeException('Your wallet is on hold. Please pay online or contact us.');
        }
        if ($this->wallet->balance($owner) < $amount) {
            throw new \RuntimeException('Your wallet has less than ₹'.number_format($amount).'. Add money first, or pay online.');
        }

        return DB::transaction(function () use ($owner, $home, $kind, $amount) {
            $fee = ListingFee::create([
                'listing_type' => self::TYPE, 'listing_id' => $home->id, 'user_id' => $owner->id, 'franchise_id' => $home->franchise_id,
                'kind' => $kind, 'amount' => $amount, 'days' => self::days($home, $kind), 'method' => 'wallet', 'status' => 'pending',
            ]);
            $this->wallet->debit($owner, (float) $amount, $kind === 'featured' ? 'Feature a property' : 'Property listing fee', 'listingfee:'.$fee->id);
            $this->markPaid($fee);

            return $fee->fresh();
        });
    }

    /** Start an online payment: returns the Razorpay order for the browser checkout. @throws \RuntimeException */
    public function startOnline(User $owner, HouseListing $home, string $kind): array
    {
        $amount = $this->check($owner, $home, $kind);
        if (! $this->onlineAvailable($home)) {
            throw new \RuntimeException('Online payment is not available right now. Try your wallet.');
        }
        $order = $this->gateway->createRawOrder((float) $amount, 'listing-'.Str::random(8), ['user_id' => $owner->id, 'purpose' => 'listing_fee', 'kind' => $kind]);
        ListingFee::create([
            'listing_type' => self::TYPE, 'listing_id' => $home->id, 'user_id' => $owner->id, 'franchise_id' => $home->franchise_id,
            'kind' => $kind, 'amount' => $amount, 'days' => self::days($home, $kind), 'method' => 'online', 'status' => 'pending',
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

        $home = HouseListing::find($fee->listing_id);
        if (! $home) {
            return;
        }
        if ($fee->kind === 'featured') {
            $from = $home->featured_until && $home->featured_until->isFuture() ? $home->featured_until : now();
            $home->update(['is_featured' => true, 'featured_until' => $from->copy()->addDays($fee->days)]);
        } else {
            $from = $home->expires_at && $home->expires_at->isFuture() ? $home->expires_at : now();
            $home->update(['is_active' => true, 'expires_at' => $from->copy()->addDays($fee->days)]);
        }
        ActivityLogger::logModel($fee->user, $home, 'Listing fee paid: '.$fee->kind.' ₹'.number_format((float) $fee->amount).' ('.$fee->method.')');
    }

    /** Paid features that have run out go back to the normal order. Called by homes:tidy. */
    public static function endLapsedFeatures(): int
    {
        return HouseListing::where('is_featured', true)->whereNotNull('featured_until')->where('featured_until', '<=', now())
            ->update(['is_featured' => false, 'featured_until' => null]);
    }
}
