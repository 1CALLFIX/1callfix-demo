# Promotional Credit — Design (shared foundation)

Status: **DESIGN ONLY — awaiting approval. No code, no migration files written.**
Date: 2026-10-02 · Base: `main` @ `891e502` · Governed by the THUMB RULE (`CLAUDE.md`, `c9a80ff`).

Used by: referral rewards (EARN4), coupon engine, loyalty redemption, campaign rewards,
cashback, any future benefit. One mechanism, not one per feature.

---------------------------------------------------------------------------------------------

## 1. How the wallet stores non-cash credit today

- One balance per user: `wallets.user_id` UNIQUE, `wallets.balance decimal(10,2)`
  (`2026_08_01_023000_create_wallets_table.php:14-15`). One ledger: `wallet_transactions`
  (`amount`, `is_credit`, `reason`, `ref` UNIQUE, `status`, `actor_id`;
  `2026_08_01_024000_…:13-19`). `WalletService::credit/debit` (`app/Services/WalletService.php:20-31`)
  mutate that single balance under a row lock (`:68-89`), with a hard no-negative guard (`:79`).
- **There is no concept of "kind of money".** A referral reward, a loyalty redemption, a campaign
  reward, an admin goodwill credit, a refund and the customer's own top-up all land in the same
  `balance`. The only trace of origin is the `ref` string prefix, decoded after the fact by
  `WalletSourceLabel::keyFor()` (`app/Support/WalletSourceLabel.php:183-196`) — used for labels and
  for `WalletFreezePolicy` (`:16-17`, blocked credits `referral_reward`, `campaign_reward`,
  `loyalty_redemption`, `admin_adjustment`).
- Where non-cash credit is minted today (all via `WalletService::credit`, same balance):
  referral reward `ReferralService.php` (branch `feature/referral-capture`: `:350-354` referrer,
  `:409-413` referee, refs `referral:{id}:reward` / `:referee-reward`); loyalty redemption
  `LoyaltyService.php` (`loyalty-redeem:{uuid}`); campaign reward `PerformanceCampaignService.php`;
  admin adjustment `Earnings/WalletAdjustmentService.php:62`.
- Spending: every module debits the one balance for an online-style "wallet" payment —
  `CreateBookingAction.php:261`, `CreateBookingBundleAction.php:228`, Hotel `:219`, Marketplace `:210`,
  Parcel `:125`, Property `:156`, Rental `:162`, Taxi `:106`; also `TipService.php:53`,
  `CustomerCancelBookingAction.php:285` (cancellation charge), `PayoutService.php:85,160`.
- Refunds of wallet-paid bookings credit the same balance (`CancellationService.php:247-254`,
  `BundleSettlementService.php:177-182`) — no memory of what the original payment was made of.

### The leak this creates (found in this audit)
`PayoutService::request()` (`app/Services/PayoutService.php:53-`) pays out of the user's whole wallet
balance (`walletService->balance`, `:150`), for payee types `provider | field_worker | franchise_owner`
(`:55`). **A provider or franchise owner is also a `users` row with a customer persona** (prod already has
"1CallFix Ato Z Services" as both provider and booking customer). Any referral/promo credit that
persona earns sits in the same balance and is **withdrawable through a payout** — the opposite of the
thumb rule. The split below closes it structurally: payouts read the *cash* bucket only.

Prod state (read-only admin pages, 2026-10-02): referral rewards unset/off; loyalty rates unset; 0 points
redeemed; 0 referrals — so **no promotional money exists in any prod wallet yet** and the split needs no
risky backfill (to be confirmed with a `wallet_transactions` source-label query before migrating).

---------------------------------------------------------------------------------------------

## 2. Proposed split

Two buckets per wallet:

| Bucket | What it is | Withdrawable (payout) | Refund of a booking paid from it | Expiry |
|---|---|---|---|---|
| **cash** (`wallets.balance`, unchanged meaning) | The user's own money: top-ups, earnings (provider/franchise), refunds of their own money, compensation | As today | As today (wallet credit / gateway, per existing code) | none |
| **promo** (new) | Anything granted as a benefit: referral rewards, coupon-style cashback, loyalty redemption, campaign reward, admin goodwill (decision O3) | **Never** | **Returns as promo credit only** | optional, per grant |

Rules (all enforced in `WalletService`, under the same row lock, so no caller can route around them):
1. Promo can be **spent only on online booking/order payment** (a new `debitForPayment()` entry point
   with an allow-list of purposes: booking, booking_bundle, parcel, hotel, rental, property, marketplace,
   taxi). Tips, payouts, plan purchases, cancellation-charge settlement and admin debits use the **cash
   bucket only** (decision O4 on cancellation charge).
2. Promo is **never** withdrawable: `PayoutService` is unchanged because it reads cash `balance` — the
   promo bucket is simply not in that number.
3. **Refunds return as credit**: a booking paid partly/fully from promo refunds the promo part as a new
   promo lot (O2: original expiry vs fresh); the cash part follows existing refund behaviour. A
   Razorpay-paid portion refunds to Razorpay exactly as today.
4. **Cash bookings never touch either bucket** — they don't touch the wallet at all; promo credit is
   rejected on a cash booking server-side (thumb rule), with the booking-creation path asserting
   `payment_method ≠ cash` before any promo spend.
5. Fraud clawback (`ReferralService::flagAsFraud` debits, `:170-200`) debits promo first, then cash only
   for the amount that was withdrawable (existing behaviour preserved).
6. Frozen wallets: `WalletFreezePolicy` keeps working on `ref`; promo grants are `BLOCKED_CREDITS`
   exactly as `referral_reward` is today.

### 2.1 Spending order when both exist — proposal (you decide)
**Promo first, then cash.** Why: (a) promo can expire, cash cannot, so using it first avoids waste;
(b) the customer's own money stays refundable-as-cash if they cancel; (c) it cannot be gamed into a
withdrawal. *Alternative:* cash first — customer-friendlier (they keep "free" money longer) but expiring
credit lapses unused and the company's liability stays on the books. A **single per-booking toggle is
not offered** (no customer choice) to keep refunds deterministic.

Refund/fee apportionment when a mixed-paid booking is cancelled (O5): proposal — the cancellation fee is
taken **pro-rata** across what was paid, and the refund returns each bucket in proportion. (Alternative:
fee from promo first so the company recovers its grant.)

---------------------------------------------------------------------------------------------

## 3. Data model — migration needed: **YES** (shown for approval; files NOT written)

Additive only. No existing column changes meaning.

```php
// 2026_xx_xx_000100_add_promo_credit_to_wallets.php
public function up(): void
{
    Schema::table('wallets', function (Blueprint $table) {
        // Cached SUM(wallet_promo_lots.remaining WHERE status='active'); written only by WalletService
        // under the wallet row lock. `balance` keeps meaning "the user's own money".
        $table->decimal('promo_balance', 10, 2)->default(0)->after('balance');
    });

    Schema::create('wallet_promo_lots', function (Blueprint $table) {
        $table->id();
        $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
        $table->string('source_key', 40);            // referral_reward | referral_referee | loyalty_redemption | campaign_reward | coupon_cashback | admin_grant | promo_refund
        $table->string('source_ref')->unique();       // = the grant's idempotency ref (same ref as its wallet_transactions row)
        $table->decimal('amount', 10, 2);              // granted
        $table->decimal('remaining', 10, 2);           // spendable now
        $table->timestamp('expires_at')->nullable();   // null = no expiry
        $table->string('status', 12)->default('active'); // active | exhausted | expired | revoked  (string, not enum)
        $table->timestamps();
        $table->index(['wallet_id', 'status', 'expires_at']);
    });

    Schema::table('wallet_transactions', function (Blueprint $table) {
        $table->string('bucket', 8)->default('cash')->after('is_credit');   // cash | promo
        $table->foreignId('promo_lot_id')->nullable()->after('bucket')->constrained('wallet_promo_lots')->nullOnDelete();
        $table->index(['wallet_id', 'bucket']);
    });
}

public function down(): void
{
    // Refuses to roll back while any promo money exists, so a rollback can never silently destroy a customer's credit.
    if (DB::table('wallet_promo_lots')->where('remaining', '>', 0)->exists()
        || DB::table('wallets')->where('promo_balance', '>', 0)->exists()) {
        throw new RuntimeException('Refusing to roll back: promotional credit still exists in one or more wallets.');
    }
    Schema::table('wallet_transactions', function (Blueprint $table) {
        $table->dropConstrainedForeignId('promo_lot_id');
        $table->dropIndex(['wallet_id', 'bucket']);
        $table->dropColumn('bucket');
    });
    Schema::dropIfExists('wallet_promo_lots');
    Schema::table('wallets', function (Blueprint $table) {
        $table->dropColumn('promo_balance');
    });
}
```
Backfill: **none**. Existing rows get `bucket='cash'`. Before migrating, you run (read-only) the
source-label query in `Operations → Wallet ledger` for `referral_reward`, `campaign_reward`,
`loyalty_redemption`, `admin_adjustment` credits — if any exist they are listed and **you** decide whether
each is reclassified (a reasoned, audited `wallet:reclassify` step, never automatic).

`decimal(10,2)` matches the wallet (max ₹99,99,999.99); no change.

### 3.1 Service API (`WalletService`, additive)
```
creditPromo(User, amount, reason, ref, sourceKey, ?expiresAt, ?actorId): WalletTransaction   // lot + txn(bucket=promo) + promo_balance, idempotent on ref
debitForPayment(User, amount, purpose, ref): array{cash:float, promo:float}                  // promo-first FIFO by expires_at, then cash; mixed = 1 txn per bucket/lot, refs "{ref}", "{ref}:promo:{lotId}"
refundPaymentParts(User, array parts, ref)                                                  // promo part → new lot; cash part → existing credit()
promoBalance(User) / spendable(User)                                                         // balance() stays cash, so every existing caller is unchanged
expirePromoLots(now): int                                                                     // daily command, writes promo_expiry debit rows, idempotent per lot
```
`credit()`/`debit()` keep their exact current signature and semantics (cash bucket) — the six
non-payment callers and `PayoutService` need no change. `WalletSourceLabel` gets rules for the new refs
(its regexes must be checked for anchoring before the `:promo:{lotId}` suffix is introduced).

### 3.2 Expiry
Setting `wallet.promo_credit_expiry_days` (Super Admin, audited, **null = no expiry**, 0 = explicit
"never"). Frozen onto each lot at grant (`expires_at`), so later setting edits never change an issued
lot. A scheduled command expires lots and records a ledger row; expiry never touches the cash bucket.

---------------------------------------------------------------------------------------------

## 4. Callers that change (when built — Phase 1 of the build order)

| Caller | Change |
|---|---|
| `CreateBookingAction::payWithWallet` `:261` + bundle `:228` + Hotel/Marketplace/Parcel/Property/Rental/Taxi | `debit` → `debitForPayment`; Payment row records the cash/promo split (new JSON column `payments.wallet_breakdown`, **requires its own migration**, shown with the build) so refunds are deterministic |
| `CancellationService::refundIfPaid` `:214-274` (+ 5 module variants) and `BundleSettlementService::reconcileRefund` `:147-213` | refund by breakdown (promo part as credit) |
| `ReferralService` (EARN4) | `credit` → `creditPromo` (source `referral_reward` / `referral_referee`) |
| `LoyaltyService::redeem` | `credit` → `creditPromo` (source `loyalty_redemption`) — **O1** |
| `PerformanceCampaignService` `wallet_credit` reward | `creditPromo` — **O1** |
| `WalletAdjustmentService` (admin credit) | explicit bucket choice, mandatory reason; default cash — **O3** |
| `TipService`, `PayoutService`, `CustomerCancelBookingAction:285`, `WalletTopUpService` | unchanged (cash only) |
| Customer wallet page + admin wallet ledger | show two balances; filter by bucket |

---------------------------------------------------------------------------------------------

## 5. Tests required (any benefit feature)

Promo rejected on a cash booking (web, API) · promo/wallet balance cannot fund a cash booking ·
promo cannot be withdrawn (payout request over `balance` only; provider-with-customer-persona case) ·
refund of a promo-paid booking returns promo credit, never cash · mixed payment splits and refunds
deterministically · promo-first FIFO by expiry · expiry sweep idempotent · expired lot unspendable ·
freeze policy blocks promo grants · concurrency: two simultaneous spends of the last promo rupee (MySQL
procedure per `COUPON_ENGINE_DESIGN.md` §16) · provider payout and commission identical with and without
promo · extra work after a promo booking payable by cash.

---------------------------------------------------------------------------------------------

## 6. Open decisions (need you)

- **O1.** Are loyalty-point redemptions and Performance-Campaign wallet rewards *promotional credit*
  (non-withdrawable) from now on? The thumb rule says "any … promotional credit", and today campaign
  rewards to a provider/franchise are real withdrawable earnings — recommend: customer-audience rewards
  → promo; provider/franchise-audience rewards stay cash.
- **O2.** Refunded promo: keep the original lot's expiry (if still in future) or issue a fresh expiry?
  Recommend original expiry, minimum 30 days remaining (your value if you want a floor).
- **O3.** Admin goodwill credit: promo (default, safer) or cash with a permission?
- **O4.** Cancellation-charge settlement from wallet (`CustomerCancelBookingAction.php:285`): cash only
  (recommended — a charge is an obligation, not a purchase), collect-balance flow collects the shortfall online.
- **O5.** Cancellation-fee apportionment on a mixed-paid booking: pro-rata (recommended) vs promo-first.
- **O6.** Spending order: promo-first (recommended) vs cash-first (§2.1).
- **O7.** Combined "wallet + Razorpay" payment does not exist as a method today
  (`Setting::enabledPaymentMethods`, `app/Models/Setting.php:102-108`: `online|cash|wallet`). Promo can only
  cover a booking fully, or partially with the remainder over Razorpay only if that combined flow is built.
  Build it as part of Phase 1, or limit promo to bookings where wallet covers the whole amount?

---

*STOP. Awaiting approval of this design before any migration or code.*
