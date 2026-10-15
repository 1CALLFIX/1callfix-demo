# Wallet & Provider — how it works today (audit 2026-10-15)

Read-only audit of `main` `bed4eaf`. Companion to `WALLET_AND_JOB_LIFECYCLE_AUDIT.md` (leaks L1–L8).

## Quick answers

| Question | Answer |
|---|---|
| Can a customer use the wallet? | **Yes.** Top up (web Earnings → Wallet, or app), pay a booking fully from the wallet, receive refunds, loyalty redemptions and referral rewards into it, tip from it. **Cannot withdraw** it to a bank. |
| Can a customer pay part wallet + part Razorpay? | **No.** It is all-wallet or none. CLAUDE.md lists "wallet + Razorpay" as an online method, but no split payment exists. |
| Is a provider auto-credited ₹500 as a welcome offer after verification? | **No.** KYC approval (`ReviewProviderKycAction`) only sets `kyc_status = approved` and sends a notification. No code anywhere gives a joining/welcome credit. |
| Could the admin set one up? | **Not exactly.** Performance Campaigns can pay a wallet reward, but they only measure `bookings_completed_count` or `revenue_generated`. The nearest is "complete 1 job → ₹X". A true "on approval" bonus needs new code. |
| Does a provider start receiving jobs after approval? | **Only when all of these hold:** KYC approved, account active, at least one service category (now picked at sign-up), a zone, online, with a location fix in the last 30 min, and the job inside the zone's radius. Wallet balance is **not** checked at dispatch (see L1). |

## 1. The wallet

### Structure
- One `wallets` row per user (customer, provider, field worker or franchise owner) with ONE `balance`.
- Every movement is a `wallet_transactions` row (amount, credit/debit, reason, ref, actor). Debits lock the row and
  refuse to go below zero (`WalletService::applyTransaction`).
- A wallet can be frozen (EARN3); a frozen wallet blocks payouts and most movements.
- There is **no separate bucket** for promotional, referral or top-up money: it is all one balance.

### Money in (credits)
| Source | Who | Code |
|---|---|---|
| Top-up via Razorpay | anyone signed in (no role check) | `WalletTopUpService`, `Customer\Earnings\Wallet`, `API\WalletController::topUp` |
| Job earnings (online-paid jobs) | provider | `CommissionService::applyForBooking` |
| Franchise revenue share | franchise owner | `CommissionService`, `PayoutService` (cash share) |
| Refunds on cancellation (wallet-paid or by policy) | customer | `CancellationService`, `BundleSettlementService`, `CustomerCancelBookingAction` |
| Pricing-dispute refunds | customer | `BookingDisputeService` |
| Tips | provider | `TipService` |
| Compensation (e.g. customer no-show) | provider | `CompensationService` |
| Interim-work payment on mid-work cancellation | provider | `CommissionService::applyForCancelledBooking` |
| Referral reward | the referring user (any role with a referral code) | `ReferralService` |
| Performance-campaign reward | provider / customer / franchise | `PerformanceCampaignService` |
| Loyalty points redeemed | customer | `LoyaltyService` |
| Failed payout returned | provider / franchise | `PayoutService::markFailed` |
| Admin adjustment | anyone | `WalletAdjustmentService` (Super Admin, reason, capped by `wallet.admin_adjustment_max`; unset = disabled) |

### Money out (debits)
| Use | Who | Code |
|---|---|---|
| Pay a booking / bundle / order (all modules) | customer | `Create*Action::payWithWallet` |
| Cancellation charge | customer | `CustomerCancelBookingAction` |
| Tip | customer | `TipService` |
| Payout request | provider / field worker / franchise owner | `PayoutService::request` |
| Cash-commission recovery (at payout request only) | provider | `PayoutService::settleCashCommissionReceivables` |
| Pricing-dispute share | provider | `BookingDisputeService`, `PayoutService` |
| Referral clawback (fraud) | referrer | `ReferralService` |
| Admin adjustment | anyone | `WalletAdjustmentService` |

### Wallet findings (new, beyond L1–L8)
- **W1 — Everything in a provider's wallet is withdrawable.** Payout checks only min/max amount, KYC and cash debt.
  Referral rewards, campaign rewards, loyalty money and the provider's own top-up all come out as cash. CLAUDE.md:
  "Promotional/referral credit is never withdrawable". Breached for providers (and franchise owners).
- **W2 — A ₹500 floor can be gamed.** A provider can top up ₹500 (no role check on top-up) to pass the accept gate,
  then withdraw it. Any floor needs "withdrawable = balance − floor" and top-up money kept non-withdrawable.
- **W3 — Admin wallet adjustment is Super Admin only, with no second approver and no franchise scope.** Better than
  nothing (reason + cap + audit), but short of the MANUAL MONEY ACTIONS model, which forbids "Super Admin only".
- **W4 — No part-wallet + Razorpay payment**, though CLAUDE.md names it as an online method.

## 2. The provider

### Lifecycle
1. **/partners** (optional) → lead saved → "Continue to sign-up".
2. **/provider/register**: mobile OTP (Firebase), name, password, services offered (new), work address / pin, KYC
   documents. Creates `users.role = provider` + `providers` row with `kyc_status = pending`, `is_active` default,
   `skills` = chosen categories. **No wallet credit.**
3. **Can log in while pending** (the success screen says they can't — wrong wording). The dashboard readiness panel
   shows "KYC pending"; going online is allowed but dispatch skips them.
4. **Admin approves KYC** (`ReviewProviderKycAction`) → `kyc_status = approved` + notification. Nothing else.
5. **Receives offers** when: approved, active, skills match the job's category, zone matches, online, location
   fresh, inside radius. Ranked by `RankingEngine`; rounds of offers; scheduled jobs get open offers.
6. **Accept** (`AcceptBookingAction`): re-checks all of the above + time slot + minimum wallet balance (if set).
7. **En route → start OTP → work → completion OTP** → `CommissionService` splits the price:
   - online/wallet-paid job: provider share credited to the wallet;
   - cash job: provider keeps the cash; platform share becomes a receivable (recovered only at payout request — L3).
8. **Rating**: customer's review updates `rating_avg`.
9. **Payout**: verified bank/UPI account → request → wallet debited → operator marks paid/failed (L7).

### Provider findings (new)
- **P1 — Pending providers can log in** (fine), but the "you can't sign in until approved" text is wrong.
- **P2 — Suspension doesn't take a provider offline** (L6).
- **P3 — Campaign participants include providers who are not approved** (`CampaignMetricResolver` uses
  `Provider::all()`); harmless while rewards are tied to completed bookings.

## 3. Where L4 (pay before dispatch) changes this

See branch `fix/l4-online-pay-before-dispatch`: an online booking is held (no offers) until the Razorpay capture;
unpaid instant bookings expire on `coupons.unpaid_hold_minutes`; completing an unpaid online booking credits nobody
until the payment arrives.
