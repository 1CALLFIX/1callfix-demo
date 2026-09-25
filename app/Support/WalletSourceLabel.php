<?php

namespace App\Support;

use App\Models\LoyaltyPoint;
use Illuminate\Database\Eloquent\Builder;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — D6. wallet_transactions has no source
 * column (approved decision: none added); the source of every row is
 * encoded in its unique `ref`. This is the ONE map from ref shape to a
 * human label — used by the customer Wallet tab, the admin ledger viewer and
 * the Earnings Control monitoring totals.
 *
 * Each rule has a regex (authoritative, for labelling one row) and SQL LIKE
 * include/exclude patterns (for filtering a query by label). Rules are
 * checked in order; the first match wins.
 *
 * WalletSourceLabelCoverageTest fails if any wallet writer in app/ writes a
 * ref shape with no rule here — add the rule in the same change as the
 * writer. Every writer is listed in WRITERS below.
 */
final class WalletSourceLabel
{
    public const UNKNOWN = 'unknown';

    /**
     * @var array<string, array{label: string, regex: string, like: array<int, string>, not_like?: array<int, string>}>
     */
    private const RULES = [
        'topup' => [
            'label' => 'Wallet top-up',
            'regex' => '/^topup:\d+$/',
            'like' => ['topup:%'],
        ],
        'wallet_payment' => [
            'label' => 'Paid from wallet',
            'regex' => '/^(booking|booking_bundle|hotel_reservation|marketplace_order|parcel_order|property_reservation|rental_reservation|taxi_ride):\d+:wallet-payment$/',
            'like' => ['%:wallet-payment'],
        ],
        'refund' => [
            'label' => 'Refund',
            // booking_bundle refunds carry a per-event suffix since EARN3
            // (`:{childId}` or `:settle-{paise}`); the bare legacy form is
            // still matched for rows written before.
            'regex' => '/^(booking|booking_bundle|hotel_reservation|marketplace_order|parcel_order|property_reservation|rental_reservation|taxi_ride):\d+:wallet-refund(:[\w-]+)?$/',
            'like' => ['%:wallet-refund', '%:wallet-refund:%'],
        ],
        'job_earning' => [
            'label' => 'Job earnings',
            'regex' => '/^[a-z_]+:\d+:(provider|worker)-earning$/',
            'like' => ['%:provider-earning', '%:worker-earning'],
        ],
        'franchise_earning' => [
            'label' => 'Franchise revenue share',
            'regex' => '/^([a-z_]+:\d+:franchise-earning|cash-commission:\d+:franchise-earning:[\w-]+)$/',
            'like' => ['%:franchise-earning', 'cash-commission:%:franchise-earning:%'],
        ],
        'cash_commission' => [
            'label' => 'Cash-job commission settled',
            'regex' => '/^cash-commission:\d+:settle:[\w-]+$/',
            'like' => ['cash-commission:%:settle:%'],
        ],
        'payout_reversal' => [
            'label' => 'Payout returned',
            'regex' => '/^payout:\d+:refund$/',
            'like' => ['payout:%:refund'],
        ],
        'payout' => [
            'label' => 'Payout',
            'regex' => '/^payout:[0-9a-f-]{36}$/',
            'like' => ['payout:%'],
            'not_like' => ['payout:%:refund'],
        ],
        'loyalty_redemption' => [
            'label' => 'Loyalty points redeemed',
            'regex' => '/^loyalty-redeem:[0-9a-f-]{36}$/',
            'like' => ['loyalty-redeem:%'],
        ],
        'referral_reward' => [
            'label' => 'Referral reward',
            'regex' => '/^referral:\d+:reward$/',
            'like' => ['referral:%:reward'],
        ],
        'referral_clawback' => [
            'label' => 'Referral reward reversed',
            'regex' => '/^referral:\d+:clawback$/',
            'like' => ['referral:%:clawback'],
        ],
        'compensation' => [
            'label' => 'Compensation',
            'regex' => '/^compensation:\d+:[a-z_]+:[0-9a-f-]{36}$/',
            'like' => ['compensation:%'],
        ],
        'tip_paid' => [
            'label' => 'Tip paid',
            'regex' => '/^tip:\d+:[0-9a-f-]{36}:debit$/',
            'like' => ['tip:%:debit'],
        ],
        'tip_received' => [
            'label' => 'Tip received',
            'regex' => '/^tip:\d+:[0-9a-f-]{36}:credit$/',
            'like' => ['tip:%:credit'],
        ],
        'campaign_reward' => [
            'label' => 'Campaign reward',
            'regex' => '/^perf_campaign:\d+:participant:\d+$/',
            'like' => ['perf_campaign:%'],
        ],
        'admin_adjustment' => [
            'label' => 'Admin adjustment',
            'regex' => '/^admin-adjust:[0-9a-f-]{36}$/',
            'like' => ['admin-adjust:%'],
        ],
    ];

    /**
     * Every wallet writer in app/ (file => one sample ref per credit/debit
     * call site, in source order). WalletSourceLabelCoverageTest counts the
     * real call sites per file and fails on any drift, then asserts every
     * sample maps to a rule.
     *
     * @var array<string, array<int, string>>
     */
    public const WRITERS = [
        'app/Actions/CreateBookingAction.php' => ['booking:1:wallet-payment'],
        'app/Actions/CreateBookingBundleAction.php' => ['booking_bundle:1:wallet-payment'],
        'app/Actions/CreateHotelReservationAction.php' => ['hotel_reservation:1:wallet-payment'],
        'app/Actions/CreateMarketplaceOrderAction.php' => ['marketplace_order:1:wallet-payment'],
        'app/Actions/CreateParcelOrderAction.php' => ['parcel_order:1:wallet-payment'],
        'app/Actions/CreatePropertyReservationAction.php' => ['property_reservation:1:wallet-payment'],
        'app/Actions/CreateRentalReservationAction.php' => ['rental_reservation:1:wallet-payment'],
        'app/Actions/CreateTaxiRideAction.php' => ['taxi_ride:1:wallet-payment'],
        'app/Services/BundleSettlementService.php' => ['booking_bundle:1:wallet-refund:2'],
        'app/Services/CancellationService.php' => [
            'booking:1:wallet-refund', 'parcel_order:1:wallet-refund', 'taxi_ride:1:wallet-refund',
            'property_reservation:1:wallet-refund', 'rental_reservation:1:wallet-refund',
            'hotel_reservation:1:wallet-refund', 'marketplace_order:1:wallet-refund',
        ],
        'app/Services/CommissionService.php' => [
            'booking:1:provider-earning', 'booking:1:franchise-earning',
            'parcel_order_id:1:worker-earning', 'parcel_order_id:1:franchise-earning',
        ],
        'app/Services/CompensationService.php' => ['compensation:1:overtime:00000000-0000-0000-0000-000000000000'],
        'app/Services/LoyaltyService.php' => ['loyalty-redeem:00000000-0000-0000-0000-000000000000'],
        'app/Services/PayoutService.php' => [
            'payout:00000000-0000-0000-0000-000000000000',
            'cash-commission:1:settle:00000000-0000-0000-0000-000000000000',
            'cash-commission:1:franchise-earning:00000000-0000-0000-0000-000000000000',
            'payout:1:refund',
        ],
        'app/Services/PerformanceCampaignService.php' => ['perf_campaign:1:participant:2'],
        'app/Services/ReferralService.php' => ['referral:1:clawback', 'referral:1:reward'],
        'app/Services/TipService.php' => [
            'tip:1:00000000-0000-0000-0000-000000000000:debit',
            'tip:1:00000000-0000-0000-0000-000000000000:credit',
        ],
        'app/Services/WalletTopUpService.php' => ['topup:1'],
        'app/Services/Earnings/WalletAdjustmentService.php' => ['admin-adjust:00000000-0000-0000-0000-000000000000', 'admin-adjust:00000000-0000-0000-0000-000000000000'],
    ];

    public static function keyFor(?string $ref): string
    {
        if ($ref === null || $ref === '') {
            return self::UNKNOWN;
        }

        foreach (self::RULES as $key => $rule) {
            if (preg_match($rule['regex'], $ref) === 1) {
                return $key;
            }
        }

        return self::UNKNOWN;
    }

    public static function labelFor(?string $ref): string
    {
        $key = self::keyFor($ref);

        return $key === self::UNKNOWN ? 'Other' : self::RULES[$key]['label'];
    }

    /** @return array<string, string> key => label, in rule order */
    public static function options(): array
    {
        return array_map(fn ($r) => $r['label'], self::RULES);
    }

    /** Narrow a wallet_transactions query to one label. Unknown keys match nothing. */
    public static function scopeQuery(Builder $query, string $key, string $column = 'ref'): Builder
    {
        $rule = self::RULES[$key] ?? null;

        if (! $rule) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where(function (Builder $q) use ($rule, $column) {
                foreach ($rule['like'] as $pattern) {
                    $q->orWhere($column, 'like', $pattern);
                }
            })
            ->where(function (Builder $q) use ($rule, $column) {
                foreach ($rule['not_like'] ?? [] as $pattern) {
                    $q->where($column, 'not like', $pattern);
                }
            });
    }

    /** Label for a loyalty_points row — sourced from its reason/ref, the same way. */
    public static function loyaltyLabel(LoyaltyPoint $row): string
    {
        $ref = (string) $row->ref;

        return match (true) {
            str_starts_with($ref, 'loyalty-expire:') => 'Points expired',
            str_starts_with($ref, 'admin-adjust:') => 'Admin adjustment',
            str_ends_with($ref, ':points-clawback') => 'Referral reward reversed',
            $row->reason === 'redeemed' => 'Redeemed to wallet',
            $row->reason === 'booking_completed' => 'Booking completed',
            $row->reason === 'referral_reward' => 'Referral reward',
            str_starts_with((string) $row->reason, 'Performance campaign reward') => 'Campaign reward',
            default => (int) $row->points >= 0 ? 'Points earned' : 'Points deducted',
        };
    }
}
